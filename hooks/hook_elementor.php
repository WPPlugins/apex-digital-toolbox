<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookElementor')) {
    class toolboxHookElementor extends toolboxHookController
    {
        public const PRUNE_CRON_HOOK = 'apex_toolbox_prune_elementor_revisions';
        public const PRUNE_MANUAL_ACTION = 'apex_toolbox_prune_elementor_now';
        public const PRUNE_NONCE_ACTION = 'apex_toolbox_prune_elementor_now';

        // Legacy single event from the removed bulk-regeneration feature. Kept only so any event
        // still sitting in a live site's cron array can be cleared - see clearLegacyRegenEvent().
        public const CSS_REGEN_HOOK = 'regenerate_all_elementor_css';

        // Elementor filter applied unconditionally just before a document's CSS is enqueued. Unlike
        // the elementor/css-file/before_enqueue action it runs UPSTREAM of enqueue()'s two early
        // returns (the per-handle $printed guard and the status==='empty' bail), so it sees cases
        // the action never does - and because it runs before enqueue() reads the meta, a heal here
        // fixes the CURRENT request rather than only the next one.
        public const CSS_HEAL_FILTER = 'elementor/frontend/builder_content/before_enqueue_css_file';

        // Elementor fires this per element inside a document's CSS build, immediately before the
        // element's rules are rendered - the right place to re-assert the style-controls flag a
        // nested build has switched off. See guardStyleControlsFlag().
        public const CSS_PARSE_ELEMENT_HOOK = 'elementor/element/before_parse_css';

        // Consecutive failed writes for one path before we stop retrying and force inline CSS
        public const CSS_HEAL_INLINE_AFTER_FAILURES = 2;
        public const CSS_HEAL_FAIL_TTL = 900;

        // A status==='empty' document carrying at least this much _elementor_data is suspicious.
        // Observed and counted only - never healed. See recordEmptyCssObservation().
        public const CSS_HEAL_EMPTY_DATA_FLOOR = 1_000;

        // Keep every revision within this window
        public const PRUNE_FRESH_DAYS = 7;

        // Beyond fresh, keep newest per day until this age; older than this keep newest per ISO week
        public const PRUNE_DAILY_DAYS = 30;

        // Safety floor: always keep this many newest revisions per post
        public const PRUNE_MIN_KEEP = 3;

        // Parent posts processed per cron tick before self-rescheduling
        public const PRUNE_BATCH = 200;

        /**
         * At most one MISSING-file record is cleared per request. The style-controls fault has no
         * such cap - that signal is exact and per-document, whereas a wipe can leave many files
         * missing at once and clearing them all would set up a many-document rebuild next request.
         *
         * An instance property, not a static: registerHooks() builds exactly one
         * toolboxHookElementor per request and binds every heal entry point to it, so per-instance
         * IS per-request. A static would additionally leak between tests in one PHP process.
         */
        private $css_cleared_this_request = false;

        /**
         * Documents whose CSS was built while the style-controls flag was off, so their stylesheet
         * is incomplete. Collected during the build and acted on at shutdown - deleting the record
         * mid-build would disturb the build that is still running.
         *
         * @var array<int, true>
         */
        private $polluted_css_posts = array();

        function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('Elementor');
            $this->addHook(APEX_TOOLBOX_HOOK_FILTER, 'init', 'registerWidgetListCategories', array(
            'label' => 'List Categories - Widget',
            'description' => 'Show a list of taxonomy links based on their parent and split them in them into columns with dividers',
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'elementor/css-file/before_enqueue', 'healCssFileBeforeEnqueue', array(
              'label' => 'Heal Broken Elementor CSS (per-request)',
              'description' => 'Works around an Elementor bug that silently strips whole groups of style rules from '
                . 'a page\'s stylesheet. When one document\'s CSS build triggers another (a page embedding a '
                . 'template), the inner build switches Elementor\'s "use style controls" flag off and never puts it '
                . 'back, so every element after that point contributes no CSS. The result is a stylesheet that is '
                . 'valid, correctly linked and incomplete, which Elementor then caches permanently. This holds the '
                . 'flag on, and rebuilds any document that was already affected before its stylesheet is linked, '
                . 'so the visitor who triggered it still gets a complete page. Also clears the record when a '
                . 'stylesheet is missing from disk. Forced on: the bug is '
                . 'silent and affects any Elementor site. Disable with APEX_CSS_HEAL_DISABLED in wp-config.',
              'priority' => 5,
              'args' => 1,
              'force' => true,
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'admin_bar_menu', 'adminBarCssHealStats', array(
              'label' => 'CSS Heal Stats in Admin Bar',
              'description' => 'Show a small counter in the admin bar with the running totals of successful and failed CSS heals.',
              'priority' => 999,
              'args' => 1,
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'init', 'scheduleElementorRevisionPrune', array(
              'label' => 'Prune Old Elementor Revisions Daily',
              'description' => 'Runs daily via WP-Cron to trim the revision history of Elementor-built posts. Keeps every revision from the last 7 days, the newest per day for revisions 7-30 days old, the newest per week beyond 30 days, and always keeps the 3 most recent revisions per post as a safety floor. Prevents wp_posts/wp_postmeta from growing unbounded over long build cycles.',
              'force' => true,
            ));
          // Always attach the cron callback when this file loads so that, if the user disables the
          // schedule hook above, the next firing can self-unschedule rather than orphan the event.
            add_action(self::PRUNE_CRON_HOOK, array($this, 'pruneElementorRevisions'));
          // Second entry point for the CSS heal. Registered here rather than as its own addHook so
          // the feature keeps ONE user-facing toggle - filterCssFileBeforeEnqueue re-checks
          // isHookEnabled('elementor', 'healCssFileBeforeEnqueue') itself, the same way the prune
          // cron and admin-post handlers do.
            add_filter(self::CSS_HEAL_FILTER, array($this, 'filterCssFileBeforeEnqueue'), 5);
          // Priority 1: get in before anything else that might read the flag for this element.
            add_action(self::CSS_PARSE_ELEMENT_HOOK, array($this, 'guardStyleControlsFlag'), 1, 2);
          // Admin-post handler for the "Run Now" button on the settings page. The handler itself
          // re-checks isHookEnabled so a disabled cron-hook means the action is a no-op.
            add_action('admin_post_' . self::PRUNE_MANUAL_ACTION, array($this, 'handleManualPruneRequest'));
        }

      /**
       * Register the list categories widget with Elementor
       */
        public function registerWidgetListCategories()
        {
            $this->includeFile('WidgetListCategories.php');
        }

      /**
       * Hold Elementor's style-controls flag on for the duration of a CSS build, and record any
       * document that was already damaged by it being off.
       *
       * The bug: Core\Files\CSS\Base::parse_content() turns the flag on at the top and hard-codes
       * it OFF at the bottom - note that the line immediately above it saves and restores the
       * responsive-duplication mode properly, so the omission reads as an oversight. The flag lives
       * in a single global static (Core\Frontend\Performance::$use_style_controls). So when one
       * document's build triggers another - a page embedding a template, which is ordinary - the
       * inner build's closing line switches the flag off while the OUTER build is still running.
       * From that point Controls_Stack::get_controls() stops merging the style_controls bucket and
       * every remaining element contributes nothing. The stylesheet is written, valid, correctly
       * linked and missing whole groups of rules, and since CSS\Post never overrides
       * is_update_required() Elementor keeps serving it forever.
       *
       * This hook fires per element immediately before that element's rules are rendered, so
       * re-asserting the flag repairs every element from here on.
       *
       * Reading it first is the diagnosis. A `false` means a nested build already switched it off
       * partway through THIS document, so the elements built before we noticed are already missing
       * and re-asserting cannot bring them back - the document is recorded for clearing at
       * shutdown. This is a fact about the build rather than an inference from its output, which is
       * why no baselines, thresholds or stored state are needed to find it.
       *
       * @param mixed $post_css Expected: \Elementor\Core\Files\CSS\Post
       * @param mixed $element Unused; part of the hook signature.
       */
        public function guardStyleControlsFlag($post_css, $element = null): void
        {
            unset($element);

            if ($this->isHealDisabled() || ! $this->canReadStyleControlsFlag()) {
                return;
            }
            if (! $this->Toolbox->isHookEnabled('elementor', 'healCssFileBeforeEnqueue')) {
                return;
            }

            if (! \Elementor\Core\Frontend\Performance::is_use_style_controls()) {
                $post_id = is_object($post_css) && method_exists($post_css, 'get_post_id')
                  ? intval($post_css->get_post_id())
                  : 0;

                if ($post_id > 0 && ! isset($this->polluted_css_posts[ $post_id ])) {
                    $this->polluted_css_posts[ $post_id ] = true;
                    // Registered on demand so a healthy request carries no shutdown callback.
                    add_action('shutdown', array($this, 'clearPollutedCssRecords'));
                }

                \Elementor\Core\Frontend\Performance::set_use_style_controls(true);

                return;
            }

            // Already on: nothing to repair, and nothing to record.
        }

      /**
       * Clear the stored CSS of any document built with the style-controls flag off, so it rebuilds
       * on a later request with nothing nested inside it.
       *
       * Deliberately not a rebuild in place: that would be another nested build, which is the fault
       * itself. Clearing the record returns the document to status '', the one state Elementor
       * regenerates from, and an ordinary visitor does the work.
       */
        public function clearPollutedCssRecords(): void
        {
            if (! $this->polluted_css_posts) {
                return;
            }

            foreach (array_keys($this->polluted_css_posts) as $post_id) {
                delete_post_meta($post_id, '_elementor_css');
                $this->bumpCounter('apex_css_heal_polluted_total');
                error_log(
                    '[Apex CSS Heal] Post ' . $post_id . ' was built with style controls switched off by a '
                    . 'nested build - record cleared so it rebuilds on its own',
                );
            }

            update_option('apex_css_heal_last_polluted_at', time(), false);
            $this->polluted_css_posts = array();
        }

      /**
       * Per-request self-heal for Elementor post-CSS files, on the
       * `elementor/css-file/before_enqueue` action.
       *
       * Elementor's enqueue path trusts post-meta status === 'file' without verifying the file is
       * on disk, so a missing file + lying meta ships a broken <link>.
       *
       * This entry point is late: the action fires AFTER enqueue() has captured its local $meta and
       * after two early returns, so a meta change made here only takes effect on the next request.
       * Regenerating the file still fixes the current one (the <link> resolves once the file
       * exists). filterCssFileBeforeEnqueue() is the earlier, more capable entry point; both share
       * healCssFile().
       *
       * @param mixed $css_file Expected: \Elementor\Core\Files\CSS\Post
       */
        public function healCssFileBeforeEnqueue($css_file)
        {
            $this->healCssFile($css_file);
        }

      /**
       * The earlier of the two heal entry points, on Elementor's
       * `elementor/frontend/builder_content/before_enqueue_css_file` filter.
       *
       * Elementor applies this immediately before calling $css_file->enqueue(), which matters for
       * two reasons. It is upstream of enqueue()'s per-handle $printed guard - on a singular page
       * the main document is enqueued early during wp_enqueue_scripts, so a file that disappears
       * after that point never reaches the action at all. And because it runs before enqueue()
       * reads the meta, anything we change here is seen by the very request we are serving, rather
       * than only the next one.
       *
       * A filter, so the value must be returned untouched.
       *
       * @param mixed $css_file
       * @return mixed
       */
        public function filterCssFileBeforeEnqueue($css_file)
        {
            // Defence in depth: one toggle covers both entry points, and this one is registered
            // unconditionally from the constructor.
            if (! $this->Toolbox->isHookEnabled('elementor', 'healCssFileBeforeEnqueue')) {
                return $css_file;
            }

            $this->buildCleanCssNow($css_file);
            $this->healCssFile($css_file);

            return $css_file;
        }

      /**
       * Shared heal routine behind both entry points.
       *
       * @param mixed $css_file
       */
        public function healCssFile($css_file): void
        {
            if ($this->isHealDisabled()) {
                return;
            }

            if (! class_exists('\Elementor\Core\Files\CSS\Post')) {
                return;
            }
            if (! ( $css_file instanceof \Elementor\Core\Files\CSS\Post )) {
                return;
            }

            // Post_Local_Cache (and so Post_Preview) returns true from is_update_required(), so
            // Elementor rebuilds it on every request and it needs no help from us. Skipping it
            // avoids stat-ing and regenerating on every editor preview render.
            //
            // Not a safety guard: Post_Preview::get_post_id() returns 0, so the meta-deletion path
            // is already unreachable for these and the real document's meta was never at risk.
            if (
                class_exists('\Elementor\Core\Files\CSS\Post_Local_Cache')
                && $css_file instanceof \Elementor\Core\Files\CSS\Post_Local_Cache
            ) {
                return;
            }

            // Front-end page renders only — broken <link>s only ship there.
            // WP_CLI matters as its own case: it is not is_admin(), so it would otherwise fall
            // through, and CSS built under CLI comes out incomplete even for a single document.
            if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('WP_CLI')) {
                return;
            }
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return;
            }

            // Re-entry guard in case another plugin chains enqueues during update()
            static $in_heal = false;
            if ($in_heal) {
                return;
            }

            $meta = $css_file->get_meta();
            $path = (string) $css_file->get_path();
            if (! is_array($meta) || $path === '') {
                return;
            }

            $post_id = method_exists($css_file, 'get_post_id')
              ? intval($css_file->get_post_id())
              : 0;

            // Only stat when the status claims a file. This runs for every enqueued document on
            // every front-end request, and the overwhelming majority are healthy - there is no
            // reason to touch the filesystem for a status that cannot be the defect. @filesize
            // because the file can vanish between the two calls; false casts to 0, which reads as
            // broken, and that is the right way to fail here.
            $status = (string) ($meta['status'] ?? '');
            $exists = false;
            $size = 0;
            if ($status === 'file') {
                $exists = file_exists($path);
                $size = $exists
                  ? (int) @filesize($path)
                  : 0;
            }

            $decision = $this->getCssHealDecision(
                $meta,
                $exists,
                $size,
                $this->getElementorDataLength($meta, $post_id),
            );

            if ($decision['kind'] === 'observe') {
                $this->recordEmptyCssObservation($post_id, $path, (string) $decision['reason']);

                return;
            }

            // A healthy-looking file still has to be size-checked: the damaging case is a file that
            // exists, is non-empty and links fine, but was rebuilt missing whole groups of rules.
            if ($decision['kind'] === 'ok') {
                if ($decision['reason'] === 'file_ok') {
                    // Healthy again: forget any earlier failure streak so a later, unrelated
                    // problem starts from zero rather than going straight to the inline fallback.
                    delete_transient('apex_css_heal_fails_' . md5($path));
                }

                return;
            }

            if ($decision['kind'] !== 'regenerate') {
                return;
            }

            // A missing file is the OTHER fault: transient, and unlike a short file it does show
            // up as a broken <link>. Recovery is the same though - clear the record and let the
            // document rebuild by itself on a later request. We deliberately do NOT call
            // $css_file->update() here: that regenerates inside the current request alongside
            // whatever else is regenerating, which is exactly what produces short files. Fixing a
            // missing file by causing an incomplete one is a bad trade - the incomplete one is
            // permanent and invisible.
            $fail_key = 'apex_css_heal_fails_' . md5($path);
            $failures = intval(get_transient($fail_key));

            // Terminal state. If clearing the record has not produced a file after repeated
            // attempts the path is almost certainly unwritable (permissions, full disk, read-only
            // uploads), and Elementor has no write-failure fallback of its own - it would keep
            // recording success and we would keep clearing, once per request, forever. Inline CSS
            // renders the page correctly and stops the cycle. This does compile one document in
            // this request, which is the case measured as safe; it is also a last resort.
            if ($failures >= self::CSS_HEAL_INLINE_AFTER_FAILURES) {
                $in_heal = true;
                try {
                    $this->forceInlineCss($css_file, $post_id, $path);
                } finally {
                    $in_heal = false;
                }
                $this->maybeSendCssHealFailureAlert($path, $post_id);

                return;
            }

            if (! $post_id || $this->css_cleared_this_request) {
                return;
            }
            $this->css_cleared_this_request = true;

            set_transient($fail_key, $failures + 1, self::CSS_HEAL_FAIL_TTL);
            delete_post_meta($post_id, '_elementor_css');
            $this->bumpCounter('apex_css_heal_total');
            update_option('apex_css_heal_last_at', time(), false);
            update_option('apex_css_heal_last_path', $path, false);
            error_log(sprintf(
                '[Apex CSS Heal] %s for %s - record cleared so it rebuilds alone',
                $decision['reason'] === 'file_empty' ? 'Zero-byte CSS' : 'Missing CSS',
                $path,
            ));
        }

      /**
       * Pure decision: what, if anything, is wrong with this document's CSS state.
       *
       * Kinds:
       *  - 'ok' nothing to do
       *  - 'regenerate' status 'file' but the file is missing or empty (the confirmed defect)
       *  - 'observe' a suspicious state we count but deliberately do NOT act on
       *
       * The 'observe' kinds are instrumentation only. Healing a status 'empty' document would risk
       * an unbreakable loop - regenerating a genuinely styleless document produces 'empty' again,
       * so it would be re-healed on every request forever. Neither observed state has been seen in
       * production; the counters exist to settle that before any behaviour is attached to them.
       *
       * @param array $meta
       * @return array{kind:string,reason:string}
       */
        public function getCssHealDecision(array $meta, bool $fileExists, int $fileSize, int $dataLength): array
        {
            $status = isset($meta['status'])
                ? (string) $meta['status']
                : '';

            if ($status === 'file') {
                if (! $fileExists) {
                    return array('kind' => 'regenerate', 'reason' => 'file_missing');
                }
                if ($fileSize <= 0) {
                    return array('kind' => 'regenerate', 'reason' => 'file_empty');
                }

                return array('kind' => 'ok', 'reason' => 'file_ok');
            }

            if ($status === 'empty' && $dataLength >= self::CSS_HEAL_EMPTY_DATA_FLOOR) {
                return array('kind' => 'observe', 'reason' => 'empty_with_data');
            }

            if ($status === 'inline' && empty($meta['css'])) {
                return array('kind' => 'observe', 'reason' => 'inline_without_css');
            }

            return array('kind' => 'ok', 'reason' => 'status_' . ($status === '' ? 'unset' : $status));
        }

      /**
       * Surface CSS-heal counters in the admin bar for quick visibility.
       *
       * @param \WP_Admin_Bar $wp_admin_bar
       */
        public function adminBarCssHealStats($wp_admin_bar)
        {
            // Defense in depth: only render when this specific hook is enabled.
            if (! $this->Toolbox->isHookEnabled('elementor', 'adminBarCssHealStats')) {
                return;
            }
            if (! current_user_can('manage_options')) {
                return;
            }
            $rebuilt = intval(get_option('apex_css_rebuilt_clean_total', 0));
            $polluted = intval(get_option('apex_css_heal_polluted_total', 0));
            $missing = intval(get_option('apex_css_heal_total', 0));
            $inline = intval(get_option('apex_css_heal_inline_total', 0));
            $observed = intval(get_option('apex_css_heal_observed_total', 0));
            $failures = intval(get_option('apex_css_heal_failed_total', 0));

            // Every path that does something records its own timestamp; the tooltip has to reflect
            // the latest of them, or it reads "nothing yet" while the counters beside it are not
            // zero - which is what it did when it keyed off the missing-file heal alone.
            $last_at = max(
                intval(get_option('apex_css_heal_last_at', 0)),
                intval(get_option('apex_css_heal_last_polluted_at', 0)),
                intval(get_option('apex_css_heal_last_inline_at', 0)),
                intval(get_option('apex_css_heal_last_failure_at', 0)),
            );
            $last_path = (string) get_option('apex_css_heal_last_path', '');

            // Title stays short enough for the admin bar; the tooltip carries the full breakdown.
            $cleared = $polluted + $missing;
            $title = sprintf('CSS Heal: %d fixed | %d cleared | %d fail', $rebuilt, $cleared, $failures);
            $tooltip = sprintf(
                'Incomplete CSS rebuilt in-request: %d; cleared for a later rebuild: %d; '
                . 'missing files cleared: %d; served inline: %d; observed only: %d; failures: %d',
                $rebuilt,
                $polluted,
                $missing,
                $inline,
                $observed,
                $failures,
            );
            $tooltip .= $last_at
              ? ' — last activity ' . human_time_diff($last_at, time()) . ' ago'
              : ' — nothing recorded yet';
            if ($last_path !== '') {
                $tooltip .= ' (last file: ' . $last_path . ')';
            }
            $wp_admin_bar->add_node(array(
              'id' => 'apex_css_heal',
              'title' => esc_html($title),
              'meta' => array( 'title' => $tooltip ),
            ));
        }

      /**
       * Ensure the daily revision-prune cron is scheduled. Runs on `init`.
       * Also wires up the admin-side status panel + manual-run trigger when the request is in admin context.
       */
        public function scheduleElementorRevisionPrune()
        {
            // Forced on for every site, but there's nothing to prune without Elementor. Skip the cron
            // and the admin panel, and clear any event left over from when Elementor was active. This
            // re-runs on every `init`, so it self-corrects the moment Elementor is (de)activated.
            if (! $this->isElementorActive()) {
                wp_clear_scheduled_hook(self::PRUNE_CRON_HOOK);

                return;
            }
            if (! wp_next_scheduled(self::PRUNE_CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_CRON_HOOK);
            }

            $this->clearLegacyRegenEvent();
            if (is_admin()) {
                add_action('admin_init', array($this, 'addPruneSettings'));
            }
        }

      /**
       * Register the read-only "Elementor Revisions" status panel on the plugin's settings page.
       * Recomputed per request so the displayed bucket counts stay fresh.
       */
        public function addPruneSettings()
        {
            $this->Toolbox->addSetting(array(
              'name' => 'elementor_revision_prune_panel',
              'label' => 'Elementor Revisions',
              'type' => 'html',
              'value' => $this->renderPrunePanel(),
              'description' => '',
            ), 'Elementor');
        }

      /**
       * Build the HTML for the prune status panel: bucket summary + run-now button.
       */
        public function renderPrunePanel(): string
        {
            $summary = $this->getBucketSummary();
            $last_at = intval(get_option('apex_toolbox_elementor_prune_last_complete_at', 0));
            $next_run = wp_next_scheduled(self::PRUNE_CRON_HOOK);
            $cursor = intval(get_option('apex_toolbox_elementor_prune_cursor', 0));

            $last_run_line = $last_at
              ? '<strong>' . esc_html(human_time_diff($last_at, time())) . ' ago</strong> (' . esc_html(wp_date('Y-m-d H:i', $last_at)) . ')'
              : '<em>not yet</em>';
            $next_run_line = $next_run
              ? '<strong>' . esc_html(human_time_diff(time(), $next_run)) . '</strong> (' . esc_html(wp_date('Y-m-d H:i', $next_run)) . ')'
              : '<em>not scheduled</em>';
            $cursor_line = $cursor > 0
              ? ' &mdash; mid-pass, last processed parent post ID ' . intval($cursor)
              : '';

            $action_url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::PRUNE_MANUAL_ACTION),
                self::PRUNE_NONCE_ACTION,
            );

            return '<style>
			  .apex-prune-panel { max-width:680px; margin:0 0 14px; border-collapse:separate; }
			  .apex-prune-panel th, .apex-prune-panel td { padding:10px 14px; }
			  /* Right-align header + cells of the numeric column, and constrain its width.
				 The chained selector bumps specificity above WP admin\'s `.widefat thead th { text-align:left }`. */
			  .apex-prune-panel th.apex-prune-panel-num { text-align:right; width:120px; }
			  .apex-prune-panel td.apex-prune-panel-num { text-align:right; white-space:nowrap; }
			  .apex-prune-meta { margin:14px 0 18px; line-height:1.6; }
			  .apex-prune-actions { margin:20px 0 6px; }
			  .apex-prune-actions .button { margin-right:12px; vertical-align:middle; }
			  .apex-prune-actions .description { vertical-align:middle; }
		  </style>'
              . '<table class="widefat striped apex-prune-panel">'
              . '<thead><tr><th>Bucket</th><th>Window</th><th class="apex-prune-panel-num">Revisions</th></tr></thead>'
              . '<tbody>'
              . '<tr><td>Fresh (kept verbatim)</td><td>&lt; ' . self::PRUNE_FRESH_DAYS . ' days</td><td class="apex-prune-panel-num">' . number_format_i18n($summary['fresh']) . '</td></tr>'
              . '<tr><td>Daily bucket (keep newest per day)</td><td>' . self::PRUNE_FRESH_DAYS . '-' . self::PRUNE_DAILY_DAYS . ' days</td><td class="apex-prune-panel-num">' . number_format_i18n($summary['daily']) . '</td></tr>'
              . '<tr><td>Weekly bucket (keep newest per ISO week)</td><td>&ge; ' . self::PRUNE_DAILY_DAYS . ' days</td><td class="apex-prune-panel-num">' . number_format_i18n($summary['weekly']) . '</td></tr>'
              . '<tr><td colspan="2"><strong>Total revisions on Elementor-built posts</strong></td><td class="apex-prune-panel-num"><strong>' . number_format_i18n($summary['total']) . '</strong></td></tr>'
              . '<tr><td colspan="2">Elementor-built parent posts</td><td class="apex-prune-panel-num">' . number_format_i18n($summary['parents']) . '</td></tr>'
              . '</tbody></table>'
              . '<p class="apex-prune-meta">Last completed pass: ' . $last_run_line . $cursor_line . '<br>'
              . 'Next scheduled run: ' . $next_run_line . '</p>'
              . '<p class="apex-prune-actions"><a href="' . esc_url($action_url) . '" class="button button-secondary">Run prune now</a>'
              . '<span class="description">Runs immediately and processes up to ' . self::PRUNE_BATCH . ' parent posts; if more remain a follow-up batch is scheduled 60s out.</span></p>';
        }

      /**
       * Aggregate counts of Elementor-built-post revisions across the three retention buckets.
       * Note: this is a population-level count - it does not subtract the safety floor (which is
       * applied per parent post), so the "fresh" and "daily" counts can include some revisions
       * that would be saved by the floor rather than the bucket. Useful as a "how much old data
       * is in the system" indicator, not as a "this many will be deleted" estimate.
       *
       * @return array{fresh:int,daily:int,weekly:int,total:int,parents:int}
       */
        public function getBucketSummary(): array
        {
            global $wpdb;

            $fresh_days = self::PRUNE_FRESH_DAYS;
            $daily_days = self::PRUNE_DAILY_DAYS;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
				  SUM(CASE WHEN r.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) THEN 1 ELSE 0 END) AS fresh,
				  SUM(CASE WHEN r.post_date_gmt <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
							AND r.post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) THEN 1 ELSE 0 END) AS daily,
				  SUM(CASE WHEN r.post_date_gmt <  DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) THEN 1 ELSE 0 END) AS weekly,
				  COUNT(*) AS total,
				  COUNT(DISTINCT r.post_parent) AS parents_with_revisions
			  FROM {$wpdb->posts} r
			  INNER JOIN {$wpdb->postmeta} pm ON r.post_parent = pm.post_id
			  WHERE r.post_type = 'revision'
			  AND pm.meta_key = '_elementor_edit_mode'
			  AND pm.meta_value = 'builder'",
                $fresh_days,
                $fresh_days,
                $daily_days,
                $daily_days,
            ), ARRAY_A);
			// phpcs:enable

            // Total Elementor-built parent count (including parents with zero revisions)
            $parents = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT p.ID)
			  FROM {$wpdb->posts} p
			  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_elementor_edit_mode'
			  AND pm.meta_value = 'builder'
			  AND p.post_type NOT IN ('revision', 'auto-draft')",
            );

            return array(
              'fresh' => isset($row['fresh']) ? (int) $row['fresh'] : 0,
              'daily' => isset($row['daily']) ? (int) $row['daily'] : 0,
              'weekly' => isset($row['weekly']) ? (int) $row['weekly'] : 0,
              'total' => isset($row['total']) ? (int) $row['total'] : 0,
              'parents' => $parents,
            );
        }

      /**
       * Handle the "Run prune now" button. Validates the request, runs the prune, posts a notice,
       * and redirects back to the settings page.
       */
        public function handleManualPruneRequest(): void
        {
            if (! current_user_can('manage_options')) {
                wp_die('Forbidden', 'Forbidden', array('response' => 403));
            }
            check_admin_referer(self::PRUNE_NONCE_ACTION);

            $stats = $this->pruneElementorRevisions();
            $notice = $this->buildManualPruneNotice($stats);
            $this->addNotice($notice['text'], $notice['type']);

            wp_safe_redirect(admin_url('admin.php?page=apex_toolbox_settings'));
            exit;
        }

      /**
       * Pure formatter: turn a prune-stats array into an admin notice payload (text + type).
       * `type` is the int notice level expected by toolboxHookController::addNotice (1=success, 2=warning, 3=danger).
       *
       * @param array $stats
       * @return array{text:string,type:int}
       */
        public function buildManualPruneNotice(array $stats): array
        {
            $kind = $stats['kind'] ?? '';
            switch ($kind) {
                case 'disabled':
                    return array('text' => 'Elementor revision pruning is disabled - enable the hook before running it manually.', 'type' => 2);
                case 'no_elementor':
                    return array('text' => 'Elementor is not active on this site - nothing to prune.', 'type' => 2);
                case 'no_parents':
                    return array('text' => 'No Elementor-built posts were found - nothing to prune.', 'type' => 1);
                case 'batch':
                    return array(
                      'text' => sprintf(
                          'Prune ran: processed %d parent posts, deleted %d revisions. %d parents still pending - a follow-up batch was scheduled.',
                          intval($stats['parents_processed'] ?? 0),
                          intval($stats['revisions_deleted'] ?? 0),
                          intval($stats['parents_pending'] ?? 0),
                      ),
                      'type' => 1,
                    );
                case 'completed':
                    return array(
                      'text' => sprintf(
                          'Prune complete: processed %d parent posts, deleted %d revisions.',
                          intval($stats['parents_processed'] ?? 0),
                          intval($stats['revisions_deleted'] ?? 0),
                      ),
                      'type' => 1,
                    );
                default:
                    return array('text' => 'Prune ran but returned an unrecognised status.', 'type' => 2);
            }
        }

      /**
       * Daily cron callback: trim old revisions of Elementor-built posts.
       *
       * Retention tiers (per parent post, applied after a safety floor of the newest PRUNE_MIN_KEEP):
       *   - < 7 days old : keep every revision
       *   - 7-30 days old : keep newest revision per local calendar day
       *   - >= 30 days old : keep newest revision per ISO week
       *
       * Batches PRUNE_BATCH parent posts per run, persisting a cursor so a large site
       * processes incrementally. Self-reschedules a follow-up single event when more work remains.
       */
        public function pruneElementorRevisions(): array
        {
            // Self-unschedule if the user has disabled this hook (or Elementor is unavailable on this site)
            if (! $this->Toolbox->isHookEnabled('elementor', 'scheduleElementorRevisionPrune')) {
                wp_clear_scheduled_hook(self::PRUNE_CRON_HOOK);

                return array('kind' => 'disabled', 'parents_processed' => 0, 'revisions_deleted' => 0, 'parents_pending' => 0);
            }
            if (! $this->isElementorActive()) {
                error_log('[Apex Elementor Revision Prune] Elementor not active - skipping run');

                return array('kind' => 'no_elementor', 'parents_processed' => 0, 'revisions_deleted' => 0, 'parents_pending' => 0);
            }

            global $wpdb;

            $parent_ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			   WHERE pm.meta_key = '_elementor_edit_mode'
			   AND pm.meta_value = 'builder'
			   AND p.post_type NOT IN ('revision', 'auto-draft')
			   ORDER BY p.ID ASC",
            );

            if (empty($parent_ids)) {
                error_log('[Apex Elementor Revision Prune] No Elementor-built posts found');

                return array('kind' => 'no_parents', 'parents_processed' => 0, 'revisions_deleted' => 0, 'parents_pending' => 0);
            }

            $cursor = intval(get_option('apex_toolbox_elementor_prune_cursor', 0));
            $remaining_ids = array_values(array_filter($parent_ids, static fn ($id) => intval($id) > $cursor));

            if (empty($remaining_ids)) {
                // Full pass complete - reset cursor for next daily run
                update_option('apex_toolbox_elementor_prune_cursor', 0, false);
                update_option('apex_toolbox_elementor_prune_in_progress_parent', 0, false);
                update_option('apex_toolbox_elementor_prune_last_complete_at', time(), false);
                error_log('[Apex Elementor Revision Prune] Full pass complete - cursor reset');

                return array('kind' => 'completed', 'parents_processed' => 0, 'revisions_deleted' => 0, 'parents_pending' => 0);
            }

            $batch = array_slice($remaining_ids, 0, self::PRUNE_BATCH);
            $now = time();
            $fresh_cutoff = $now - self::PRUNE_FRESH_DAYS * DAY_IN_SECONDS;
            $daily_cutoff = $now - self::PRUNE_DAILY_DAYS * DAY_IN_SECONDS;

            // If a previous run died mid-parent (likely OOM), the in_progress option still names it.
            // Loaded once at the top of the run so subsequent iterations see the original value, not
            // the per-parent updates we make below.
            $crashed_parent = intval(get_option('apex_toolbox_elementor_prune_in_progress_parent', 0));

            $parents_processed = 0;
            $revisions_deleted = 0;
            $bailed_early = false;

            foreach ($batch as $parent_id) {
                // E: bail before this iteration if we're already over budget from prior parents.
                // Checked at the top so the bail catches all per-parent code paths (no-op parents continue out below).
                if ($parents_processed > 0 && $this->isMemoryBudgetExceeded()) {
                    $usage_mb = round(memory_get_usage(true) / 1024 / 1024, 1);
                    $limit_mb = round(wp_convert_hr_to_bytes((string) ini_get('memory_limit')) / 1024 / 1024, 1);
                    error_log("[Apex Elementor Revision Prune] Memory budget reached ({$usage_mb}MB of {$limit_mb}MB) - bailing before parent {$parent_id}, will resume on next run");
                    $bailed_early = true;

                    break;
                }

                $parent_id = intval($parent_id);

                // D: previous-run crash recovery - skip a parent we already started but didn't finish
                if ($crashed_parent > 0 && $crashed_parent === $parent_id) {
                    error_log(sprintf(
                        '[Apex Elementor Revision Prune] Skipping parent %d - previous run did not finish processing it (likely OOM). Inspect with: SELECT COUNT(*) FROM wp_posts WHERE post_parent=%d AND post_type=\'revision\';',
                        $parent_id,
                        $parent_id,
                    ));
                    update_option('apex_toolbox_elementor_prune_cursor', $parent_id, false);
                    update_option('apex_toolbox_elementor_prune_in_progress_parent', 0, false);
                    $crashed_parent = 0;
                    $parents_processed++;

                    continue;
                }

                // D: mark this parent as in-progress before any heavy work
                update_option('apex_toolbox_elementor_prune_in_progress_parent', $parent_id, false);

                // A: lightweight query - ID + post_date_gmt only, never loads post_content blobs.
                // Critical: Elementor revisions store the entire _elementor_data JSON in post_content,
                // routinely several MB each. wp_get_post_revisions() would load all of them into
                // WP_Post objects and the post-cache, which is what was OOM-ing on busy posts.
                $revisions = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_date_gmt
				   FROM {$wpdb->posts}
				   WHERE post_parent = %d
				   AND post_type = 'revision'
				   ORDER BY post_date_gmt DESC, ID DESC",
                    $parent_id,
                ));

                $revision_count = count($revisions);

                // F: announce the parent before any heavy lifting so debug.log shows where a crash happened
                error_log("[Apex Elementor Revision Prune] Processing parent {$parent_id} ({$revision_count} revisions)");

                if ($revision_count <= self::PRUNE_MIN_KEEP) {
                    $parents_processed++;
                    update_option('apex_toolbox_elementor_prune_cursor', $parent_id, false);
                    update_option('apex_toolbox_elementor_prune_in_progress_parent', 0, false);

                    continue;
                }

                // Drop the safety floor off the front - never delete these
                $candidates = array_slice($revisions, self::PRUNE_MIN_KEEP);

                $daily_buckets = array();
                $weekly_buckets = array();
                $to_delete = array();

                foreach ($candidates as $rev) {
                    $timestamp = strtotime($rev->post_date_gmt . ' UTC');
                    if (! $timestamp) {
                        continue;
                    }
                    if ($timestamp >= $fresh_cutoff) {
                        // Fresh window - keep all
                        continue;
                    }
                    if ($timestamp >= $daily_cutoff) {
                        // Daily bucket - keep newest per local calendar day
                        $key = wp_date('Y-m-d', $timestamp);
                        if (isset($daily_buckets[ $key ])) {
                            $to_delete[] = $rev;
                        } else {
                            $daily_buckets[ $key ] = $rev->ID;
                        }
                    } else {
                        // Weekly bucket - keep newest per ISO week
                        $key = wp_date('o-W', $timestamp);
                        if (isset($weekly_buckets[ $key ])) {
                            $to_delete[] = $rev;
                        } else {
                            $weekly_buckets[ $key ] = $rev->ID;
                        }
                    }
                }

                // Free the bucket-loop intermediates before we start the expensive delete loop
                unset($candidates, $daily_buckets, $weekly_buckets, $revisions);

                $parent_deleted = 0;
                foreach ($to_delete as $rev) {
                    try {
                        $deleted = wp_delete_post_revision($rev->ID);
                        if ($deleted && ! is_wp_error($deleted)) {
                            $revisions_deleted++;
                            $parent_deleted++;
                        }
                        // B: evict the just-deleted revision from the post-cache so memory doesn't pile up
                        clean_post_cache($rev->ID);
                    } catch (\Throwable $e) {
                        error_log("[Apex Elementor Revision Prune] Failed deleting revision {$rev->ID}: " . $e->getMessage());
                    }
                }
                unset($to_delete);

                $parents_processed++;

                // C: per-parent cursor save so partial-batch progress survives a crash on a LATER parent
                update_option('apex_toolbox_elementor_prune_cursor', $parent_id, false);
                update_option('apex_toolbox_elementor_prune_in_progress_parent', 0, false);

                if ($parent_deleted > 0) {
                    error_log("[Apex Elementor Revision Prune] Finished parent {$parent_id} - deleted {$parent_deleted} revisions");
                }
            }

            $still_pending = count($remaining_ids) - $parents_processed;
            if ($still_pending > 0 || $bailed_early) {
                wp_schedule_single_event(time() + 60, self::PRUNE_CRON_HOOK);
                error_log("[Apex Elementor Revision Prune] Processed {$parents_processed} parents, deleted {$revisions_deleted} revisions, {$still_pending} parents pending - next batch scheduled");

                return array(
                  'kind' => 'batch',
                  'parents_processed' => $parents_processed,
                  'revisions_deleted' => $revisions_deleted,
                  'parents_pending' => $still_pending,
                );
            }
            update_option('apex_toolbox_elementor_prune_cursor', 0, false);
            update_option('apex_toolbox_elementor_prune_in_progress_parent', 0, false);
            update_option('apex_toolbox_elementor_prune_last_complete_at', time(), false);
            error_log("[Apex Elementor Revision Prune] Processed {$parents_processed} parents, deleted {$revisions_deleted} revisions - pass complete");

            return array(
              'kind' => 'completed',
              'parents_processed' => $parents_processed,
              'revisions_deleted' => $revisions_deleted,
              'parents_pending' => 0,
            );
        }

      /**
       * Whether this Elementor build exposes the style-controls flag. Older releases predate
       * Core\Frontend\Performance, and a future one may drop it - in either case the guard simply
       * does nothing rather than fataling.
       */
        protected function canReadStyleControlsFlag(): bool
        {
            return class_exists('\Elementor\Core\Frontend\Performance')
                && method_exists('\Elementor\Core\Frontend\Performance', 'is_use_style_controls')
                && method_exists('\Elementor\Core\Frontend\Performance', 'set_use_style_controls');
        }

      /**
       * E: Soft memory budget gate. Returns true when memory_get_usage is over 80% of memory_limit,
       * signalling the caller to bail and reschedule before the process is killed by the kernel.
       * Returns false when memory_limit is unlimited (-1 / 0) or unparseable.
       * Protected so tests can override via a subclass.
       */
        protected function isMemoryBudgetExceeded(): bool
        {
            $limit_raw = (string) ini_get('memory_limit');
            if ($limit_raw === '' || $limit_raw === '-1') {
                return false;
            }
            $limit = function_exists('wp_convert_hr_to_bytes')
              ? wp_convert_hr_to_bytes($limit_raw)
              : (int) $limit_raw;
            if ($limit <= 0) {
                return false;
            }

            return memory_get_usage(true) > $limit * 0.80;
        }

      /**
       * Whether Elementor is active on this site. Wrapped so tests can simulate its absence
       * (the test bootstrap stubs \Elementor\Plugin into existence).
       */
        protected function isElementorActive(): bool
        {
            return class_exists('\Elementor\Plugin');
        }

      /**
       * The APEX_CSS_HEAL_DISABLED wp-config kill switch. Wrapped rather than read inline so a test
       * can turn it on via a subclass - defining the constant in a test would leak into every later
       * test in the same PHP process.
       */
        protected function isHealDisabled(): bool
        {
            return defined('APEX_CSS_HEAL_DISABLED') && APEX_CSS_HEAL_DISABLED;
        }

      /**
       * Rebuild a document we know was built with the style-controls flag off, right now, so THIS
       * response ships complete CSS.
       *
       * guardStyleControlsFlag() repairs every element built after a nested build returns, but it
       * cannot recover the element during whose own parse the nesting happened - that element's
       * rules were rendered with the flag already off. Left to the shutdown clear alone, the
       * visitor who triggered the rebuild is served the partial stylesheet and only the NEXT
       * visitor gets a good one.
       *
       * The retry is clean because the pollution comes from a nested build, and a nested build
       * only happens once: by now the embedded document has CSS of its own, so this rebuild has
       * nothing to nest inside it and nothing switches the flag off underneath it. That is the
       * whole basis of the fix.
       *
       * Acts on any status, not just an empty one. The affected document is typically already
       * built by the time this filter runs - a theme-builder location renders it first - so a
       * status check would skip the one case that matters.
       *
       * @param mixed $css_file Expected: \Elementor\Core\Files\CSS\Post
       */
        private function buildCleanCssNow($css_file): void
        {
            if ($this->isHealDisabled()) {
                return;
            }
            if (! class_exists('\Elementor\Core\Files\CSS\Post')) {
                return;
            }
            if (! ( $css_file instanceof \Elementor\Core\Files\CSS\Post )) {
                return;
            }

            // Front-end renders only, matching healCssFile(). CSS built under WP-CLI is unreliable
            // for reasons of its own, so this must not take over the build there.
            if (is_admin() || wp_doing_ajax() || wp_doing_cron() || defined('WP_CLI')) {
                return;
            }
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return;
            }

            $post_id = method_exists($css_file, 'get_post_id')
              ? intval($css_file->get_post_id())
              : 0;
            if ($post_id <= 0 || ! isset($this->polluted_css_posts[ $post_id ])) {
                return;
            }

            // Unmark BEFORE rebuilding. It is what stops this recursing - the rebuild re-enters
            // this filter for any document it renders, and the same id can no longer match - and it
            // lets guardStyleControlsFlag() re-mark the document if the rebuild is polluted too.
            unset($this->polluted_css_posts[ $post_id ]);

            try {
                $css_file->update();
            } catch (\Throwable $e) {
                error_log('[Apex CSS Heal] Rebuild failed for post ' . $post_id . ': ' . $e->getMessage());

                return;
            }

            if (isset($this->polluted_css_posts[ $post_id ])) {
                // The rebuild was polluted as well. Never retry in a loop: leave it marked and let
                // the shutdown clear hand it to a later request.
                error_log(
                    '[Apex CSS Heal] Rebuild of post ' . $post_id . ' was polluted too - leaving it '
                    . 'for the next request',
                );

                return;
            }

            $this->bumpCounter('apex_css_rebuilt_clean_total');
            error_log(sprintf(
                '[Apex CSS Heal] Post %d was built with style controls switched off - rebuilt '
                . 'immediately with nothing nested, so this request serves complete CSS',
                $post_id,
            ));
        }

      /**
       * Terminal state for a path we cannot write.
       *
       * Elementor has no write-failure fallback: update() picks inline only when the site's
       * css_print_method setting says so, never because a write failed. Left alone, an unwritable
       * path means every request regenerates, fails, and clears the meta again - churn with no
       * working page at the end of it. Writing the meta ourselves as inline renders the document
       * correctly instead, since enqueue() handles CSS_STATUS_INLINE natively.
       *
       * @param mixed $css_file
       */
        private function forceInlineCss($css_file, int $post_id, string $path): void
        {
            if (! $post_id) {
                return;
            }

            $content = '';
            try {
                $content = (string) $css_file->get_content();
            } catch (\Throwable $e) {
                error_log('[Apex CSS Heal] Could not read CSS content for ' . $path . ': ' . $e->getMessage());
            }

            if ($content === '') {
                // Nothing to inline - clear the meta so Elementor at least retries from scratch.
                delete_post_meta($post_id, '_elementor_css');
                error_log('[Apex CSS Heal] No content to inline for ' . $path . ' — meta cleared');

                return;
            }

            $meta = (array) $css_file->get_meta();
            $meta['status'] = 'inline';
            $meta['css'] = $content;
            $meta['time'] = time();

            update_post_meta($post_id, '_elementor_css', $meta);
            $inline_total = intval(get_option('apex_css_heal_inline_total', 0)) + 1;
            update_option('apex_css_heal_inline_total', $inline_total, false);
            update_option('apex_css_heal_last_inline_at', time(), false);
            update_option('apex_css_heal_last_inline_path', $path, false);
            error_log('[Apex CSS Heal] ' . $path . ' unwritable after repeated attempts — serving inline CSS instead');
        }

      /** Increment one of the heal counters. */
        private function bumpCounter(string $option): void
        {
            update_option($option, intval(get_option($option, 0)) + 1, false);
        }

      /**
       * Remove the cron event left behind by the bulk-regeneration feature that 2.1.0 shipped and
       * 2.1.1 removes.
       *
       * That feature regenerated up to 500 documents in a single request, which is the condition
       * that produces incomplete CSS - and on a host where system cron runs `wp cron event run`
       * (Kinsta's default) the event fired under WP-CLI, where every rebuild is incomplete even
       * one at a time. Sites that took 2.1.0 can still have the event queued, so clear it rather
       * than leave a scheduled task pointing at a callback that no longer exists.
       */
        private function clearLegacyRegenEvent(): void
        {
            if (wp_next_scheduled(self::CSS_REGEN_HOOK)) {
                wp_clear_scheduled_hook(self::CSS_REGEN_HOOK);
                error_log('[Apex CSS Heal] Cleared the legacy bulk CSS regeneration event');
            }
        }

      /**
       * Count a suspicious-but-unhealed state, at most once per document per hour so the number
       * reads as "distinct documents seen" rather than "requests served".
       */
        private function recordEmptyCssObservation(int $post_id, string $path, string $reason): void
        {
            if (! $post_id) {
                return;
            }

            $seen_key = 'apex_css_heal_seen_' . $post_id;
            if (get_transient($seen_key)) {
                return;
            }
            set_transient($seen_key, 1, HOUR_IN_SECONDS);

            update_option(
                'apex_css_heal_observed_total',
                intval(get_option('apex_css_heal_observed_total', 0)) + 1,
                false,
            );
            update_option('apex_css_heal_last_observed_at', time(), false);
            update_option('apex_css_heal_last_observed', $reason . ' post=' . $post_id, false);
            error_log('[Apex CSS Heal] Observed (not healed): ' . $reason . ' for post ' . $post_id . ' — ' . $path);
        }

      /**
       * Size of the document's Elementor data, used to tell a genuinely styleless document from one
       * that should have produced CSS. Only read when the status is 'empty', which is rare.
       *
       * @param array $meta
       */
        private function getElementorDataLength(array $meta, int $post_id): int
        {
            if ($post_id <= 0 || ($meta['status'] ?? '') !== 'empty') {
                return 0;
            }

            $data = get_post_meta($post_id, '_elementor_data', true);

            return is_string($data)
              ? strlen($data)
              : 0;
        }

      /**
       * Include an Elementor specific file
       *
       * @param $file
       */
        private function includeFile($file)
        {
            $this->Toolbox->includeFile('elementor/' . $file);
        }

      /**
       * Send a single failure-alert email per cooldown window.
       *
       * @param string $path Path of the CSS file that failed to regenerate.
       * @param int $post_id Post ID whose CSS failed.
       */
        private function maybeSendCssHealFailureAlert($path, $post_id)
        {
            if (defined('APEX_CSS_HEAL_NO_EMAIL') && APEX_CSS_HEAL_NO_EMAIL) {
                return;
            }
            if (get_transient('apx_css_heal_alert_sent')) {
                return;
            }
            $to = defined('APEX_CSS_HEAL_ALERT_EMAIL') && APEX_CSS_HEAL_ALERT_EMAIL
              ? APEX_CSS_HEAL_ALERT_EMAIL
              : get_option('admin_email');
            if (! $to) {
                return;
            }
            $host = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
            $subject = '[Apex CSS Heal] Failed regeneration on ' . $host;
            $failures = intval(get_option('apex_css_heal_failed_total', 0));
            $body = "Elementor CSS regeneration failed on {$host}.\n\n"
                    . "File: {$path}\n"
                    . "Post ID: {$post_id}\n"
                    . "Total failures: {$failures}\n\n"
                    . 'Meta has been cleared so the next request will regenerate from scratch.';
            wp_mail($to, $subject, $body);
            set_transient('apx_css_heal_alert_sent', 1, HOUR_IN_SECONDS);
        }
    }
}
