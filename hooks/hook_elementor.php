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
        public const string PRUNE_CRON_HOOK = 'apex_toolbox_prune_elementor_revisions';
        public const string PRUNE_MANUAL_ACTION = 'apex_toolbox_prune_elementor_now';
        public const string PRUNE_NONCE_ACTION = 'apex_toolbox_prune_elementor_now';

        // Keep every revision within this window
        public const int PRUNE_FRESH_DAYS = 7;

        // Beyond fresh, keep newest per day until this age; older than this keep newest per ISO week
        public const int PRUNE_DAILY_DAYS = 30;

        // Safety floor: always keep this many newest revisions per post
        public const int PRUNE_MIN_KEEP = 3;

        // Parent posts processed per cron tick before self-rescheduling
        public const int PRUNE_BATCH = 200;

        function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('Elementor');
            $this->addHook(APEX_TOOLBOX_HOOK_FILTER, 'init', 'registerWidgetListCategories', array(
            'label' => 'List Categories - Widget',
            'description' => 'Show a list of taxonomy links based on their parent and split them in them into columns with dividers',
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'upgrader_process_complete', 'fixElementorCssAfterUpdate', array(
              'label' => 'Fix CSS Regeneration After Plugin Updates',
              'description' => 'Automatically regenerate Elementor CSS files after plugin updates to prevent broken styles. Only regenerates affected posts for better performance.',
              'priority' => 20,
              'args' => 2,
            ));
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'elementor/css-file/before_enqueue', 'healCssFileBeforeEnqueue', array(
              'label' => 'Heal Missing Elementor CSS Files (per-request)',
              'description' => 'When Elementor is about to enqueue a post-CSS file whose meta says "file" but the file is missing on disk, regenerate it just-in-time. Falls back to clearing meta (forcing inline regen on next request) if write keeps failing. Logs and counts every occurrence.',
              'priority' => 5,
              'args' => 1,
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
       * Immediately regenerate only affected Elementor CSS files after plugin update
       *
       * @param \WP_Upgrader $upgrader_object
       * @param array $options
       */
        public function fixElementorCssAfterUpdate($upgrader_object, $options)
        {
            // Only run for plugin updates
            if ($options['type'] !== 'plugin') {
                return;
            }

            // Register the scheduled action hook
            add_action('regenerate_all_elementor_css', array($this, 'regenerateAffectedElementorCss'));

            // Run immediately if we can, otherwise schedule
            if (! wp_doing_cron() && ! defined('DOING_AUTOSAVE')) {
                $this->regenerateAffectedElementorCss();
            } else {
                wp_schedule_single_event(time() + 30, 'regenerate_all_elementor_css');
            }
        }

      /**
       * Regenerate CSS only for posts with missing or invalid CSS files
       */
        public function regenerateAffectedElementorCss()
        {
            if (! class_exists('\Elementor\Plugin')) {
                return;
            }

            global $wpdb;

            // Get only published posts built with Elementor (excluding revisions and drafts)
            $post_ids = $wpdb->get_col(
                "SELECT DISTINCT p.ID
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			   WHERE pm.meta_key = '_elementor_edit_mode'
			   AND pm.meta_value = 'builder'
			   AND p.post_status = 'publish'
			   AND p.post_type NOT IN ('revision', 'auto-draft')",
            );

            if (empty($post_ids)) {
                return;
            }

            $affected_posts = array();
            $uploads_dir = wp_upload_dir();
            $css_base_path = $uploads_dir['basedir'] . '/elementor/css/';

            // Regenerate Kit CSS first (global colors/fonts) - always needed
            try {
                $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
                if ($kit) {
                    $kit_css = \Elementor\Core\Files\CSS\Post::create($kit->get_id());
                    $kit_css->update();
                    error_log("Elementor CSS Fix: Regenerated Kit CSS (ID: {$kit->get_id()})");
                }
            } catch (\Throwable $e) {
                error_log('Elementor CSS Fix: Failed to regenerate Kit CSS: ' . $e->getMessage());
            }

            // Check which posts actually need regeneration
            foreach ($post_ids as $post_id) {
                $needs_regeneration = false;

                // Check if CSS file exists
                $css_filename = "post-{$post_id}.css";
                $css_file_path = $css_base_path . $css_filename;

                if (! file_exists($css_file_path)) {
                    $needs_regeneration = true;
                } else {
                    // Check if meta is missing or invalid
                    $meta = get_post_meta($post_id, \Elementor\Core\Files\CSS\Post::META_KEY, true);

                    if (empty($meta) || empty($meta['status']) || $meta['status'] === '') {
                        $needs_regeneration = true;
                    } elseif ($meta['status'] === 'file') {
                        // File should exist but verify it's not empty
                        if (filesize($css_file_path) < 100) {
                            // Suspiciously small CSS file - likely incomplete
                            $needs_regeneration = true;
                        }
                    }
                }

                if ($needs_regeneration) {
                    $affected_posts[] = $post_id;
                }
            }

            // Only regenerate affected posts
            if (empty($affected_posts)) {
                error_log('Elementor CSS Fix: No affected posts found - all CSS files are valid');

                // Nothing to do!
                return;
            }

            $regenerated = 0;
            // Process max 500 per execution
            $batch_limit = 500;

            foreach (array_slice($affected_posts, 0, $batch_limit) as $post_id) {
                try {
                    $post = get_post($post_id);
                    $post_title = $post
                      ? $post->post_title
                      : 'Unknown';
                    $post_type = $post
                      ? $post->post_type
                      : 'unknown';

                    $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
                    $css_file->update();
                    $regenerated++;

                    error_log("Elementor CSS Fix: Regenerated CSS for '{$post_title}' (ID: {$post_id}, Type: {$post_type})");
                } catch (\Throwable $e) {
                    error_log("Elementor CSS Fix: Failed to regenerate CSS for post {$post_id}: " . $e->getMessage());
                }
            }

            // If there are more affected posts, schedule next batch
            if (count($affected_posts) > $batch_limit) {
                $remaining = count($affected_posts) - $batch_limit;
                wp_schedule_single_event(time() + 30, 'regenerate_all_elementor_css');
                error_log("Elementor CSS Fix: Regenerated {$regenerated} posts, {$remaining} remaining - scheduled next batch");
            } else {
                error_log("Elementor CSS Fix: Complete - regenerated {$regenerated} affected posts out of " . count($post_ids) . ' total published Elementor posts');
            }
        }

      /**
       * Per-request self-heal for Elementor post-CSS files.
       *
       * Elementor's enqueue path (Elementor\Core\Files\Base::enqueue) trusts post-meta
       * status === 'file' without verifying the file is on disk, so a missing file +
       * lying meta ships a broken <link>. This regenerates the file just-in-time
       * before Elementor's own wp_enqueue_style call runs, and falls back to
       * clearing the meta so the next request rebuilds from scratch.
       *
       * @param mixed $css_file Expected: \Elementor\Core\Files\Base
       */
        public function healCssFileBeforeEnqueue($css_file)
        {
            // Kill switch
            if (defined('APEX_CSS_HEAL_DISABLED') && APEX_CSS_HEAL_DISABLED) {
                return;
            }

            // Only act on per-post CSS — kit/preview/global have different lifecycles
            if (! class_exists('\Elementor\Core\Files\CSS\Post')) {
                return;
            }
            if (! ( $css_file instanceof \Elementor\Core\Files\CSS\Post )) {
                return;
            }

            // Front-end page renders only — broken <link>s only ship there
            if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
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
            if (! is_array($meta) || empty($meta['status']) || $meta['status'] !== 'file') {
                return;
            }

            $path = $css_file->get_path();
            if (! $path) {
                return;
            }

            // Disk is fine — nothing to heal
            if (file_exists($path) && filesize($path) > 0) {
                return;
            }

            // Per-file lock so concurrent requests don't dog-pile regeneration
            $lock_key = 'apex_css_heal_lock_' . md5($path);
            if (get_transient($lock_key)) {
                return;
            }
            set_transient($lock_key, 1, 30);

            $post_id = method_exists($css_file, 'get_post_id')
              ? intval($css_file->get_post_id())
              : 0;

            $in_heal = true;
            try {
                try {
                    $css_file->update();
                } catch (\Throwable $e) {
                    error_log('[Apex CSS Heal] Exception during regen for ' . $path . ': ' . $e->getMessage());
                }

                if (file_exists($path) && filesize($path) > 0) {
                    error_log('[Apex CSS Heal] Regenerated missing CSS: ' . $path);
                    update_option('apex_css_heal_total', intval(get_option('apex_css_heal_total', 0)) + 1, false);
                    update_option('apex_css_heal_last_at', time(), false);
                    update_option('apex_css_heal_last_path', $path, false);
                } else {
                    error_log('[Apex CSS Heal] FAILED regen for ' . $path . ' — switching to inline fallback');
                    // Clear the meta so the next request treats this as a fresh post
                    // and rebuilds from scratch (Elementor will compile inline if it can't write).
                    if ($post_id) {
                        delete_post_meta($post_id, '_elementor_css');
                    }
                    update_option('apex_css_heal_failed_total', intval(get_option('apex_css_heal_failed_total', 0)) + 1, false);
                    update_option('apex_css_heal_last_failure_at', time(), false);
                    update_option('apex_css_heal_last_failure_path', $path, false);

                    $this->maybeSendCssHealFailureAlert($path, $post_id);
                }
            } finally {
                $in_heal = false;
                delete_transient($lock_key);
            }
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
            $total = intval(get_option('apex_css_heal_total', 0));
            $failures = intval(get_option('apex_css_heal_failed_total', 0));
            $last_at = intval(get_option('apex_css_heal_last_at', 0));
            $last_path = get_option('apex_css_heal_last_path', '');
            $title = sprintf('CSS Heal: %d ok | %d fail', $total, $failures);
            $tooltip = $last_at
              ? 'Last heal: ' . human_time_diff($last_at, time()) . ' ago — ' . $last_path
              : 'No heals yet';
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
