<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookOutboundFirewall')) {
    /**
     * Outbound HTTP firewall for non-production environments.
     *
     * Born from a real incident: a WooCommerce DB carrying live payment-gateway credentials was
     * cloned into a dev environment, and a bug there issued real refunds against real cards because
     * the clone could still reach the live gateway. This feature removes that second fault: a
     * non-production environment must not be able to make outbound HTTP calls to external hosts by
     * default.
     *
     * Mechanism: hook `pre_http_request` (the same WordPress HTTP API entry point Windcave and most
     * gateways use). In a non-production environment, requests to external hosts return a WP_Error
     * (as if the network were down) unless the host is allowlisted. Production is never touched.
     *
     * Limitation: this only governs the WordPress HTTP API (wp_remote_*). Code using raw cURL /
     * sockets (e.g. the Stripe PHP SDK) bypasses this filter and is NOT blocked.
     */
    class toolboxHookOutboundFirewall extends toolboxHookController
    {
        public const BLOCK_ERROR_CODE = 'apex_toolbox_outbound_blocked';
        public const ALLOWLIST_FILTER = 'apex_toolbox_http_allowlist';
        public const DEFAULT_ALLOWLIST_FILTER = 'apex_toolbox_http_default_allowlist';
        public const CONFIG_CONSTANT = 'APEX_TOOLBOX_HTTP_ALLOWLIST';

        // admin-post actions (also used as their nonce action names)
        public const ALLOW_HOST_ACTION = 'apex_toolbox_http_allow_host';
        public const REMOVE_HOST_ACTION = 'apex_toolbox_http_remove_host';
        public const DISMISS_HOST_ACTION = 'apex_toolbox_http_dismiss_host';
        public const CLEAR_BLOCKED_ACTION = 'apex_toolbox_http_clear_blocked';

        // Cap the discovery log so a runaway sync hitting many hosts can't grow the option unbounded.
        public const MAX_BLOCKED_HOSTS = 200;

        // WordPress.org infrastructure is safe to reach from a clone: it is read-only (update checks,
        // the plugin/theme browser, translations, dashboard feeds) with no real-world side effects, so
        // it is allowlisted by default to keep a dev environment's dashboard working. Deliberately does
        // NOT include *.wordpress.com - that is the Jetpack/Automattic API surface (sync, stats, social,
        // WooPayments) where calls CAN have outward side effects, exactly what this firewall guards
        // against. Filterable via apex_toolbox_http_default_allowlist so a site can strip it.
        private const DEFAULT_ALLOWLIST = array('*.wordpress.org');

        // Memoised environment decision for this request.
        private $_env = null;

        // Blocks accumulated this request, flushed once on shutdown (keeps writes cheap).
        private $_pendingBlocks = array();
        private $_shutdownHooked = false;

        public function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('Outbound HTTP Firewall');
            // Registered as a filter on pre_http_request directly via the hook system, so it is active
            // from the moment the plugin boots - before init and during cron, where the dangerous
            // background calls run. Forced on for every site; production is a no-op (see filterOutboundHttp).
            $this->addHook(APEX_TOOLBOX_HOOK_FILTER, 'pre_http_request', 'filterOutboundHttp', array(
                'label' => 'Block Outbound HTTP in Non-Production',
                'description' => 'In any non-production environment (DDEV, a *.kinsta.cloud staging host, or a '
                    . 'site whose URL does not match the configured Production URL) external HTTP requests made via '
                    . 'the WordPress HTTP API are blocked and logged, unless the host is allowlisted. Production is '
                    . 'never affected. Does NOT block raw cURL/socket calls. Disable per-site via the '
                    . 'APEX_TOOLBOX_DISABLED_HOOKS wp-config kill-switch.',
                'force' => true,
                'args' => 3,
            ));

            // Admin glue registered directly (not via the hook system) so the feature only needs the
            // single Hooks-page checkbox above, mirroring the Elementor prune pattern.
            if (is_admin()) {
                add_action('admin_init', array($this, 'addFirewallSettings'));
                add_action('admin_notices', array($this, 'maybeShowProductionUrlNotice'));
                add_action('admin_post_' . self::ALLOW_HOST_ACTION, array($this, 'handleAllowHost'));
                add_action('admin_post_' . self::REMOVE_HOST_ACTION, array($this, 'handleRemoveHost'));
                add_action('admin_post_' . self::DISMISS_HOST_ACTION, array($this, 'handleDismissHost'));
                add_action('admin_post_' . self::CLEAR_BLOCKED_ACTION, array($this, 'handleClearBlocked'));
            }
        }

        // =====================================================================
        // Environment detection - the core safety logic
        // =====================================================================

        /**
         * Authoritative non-production check. Evaluated in order; first match wins:
         *   1. DDEV - always block (hard-wired, not gated by any setting).
         *   2. Kinsta non-primary (siteurl host ends in .kinsta.cloud) - always block (hard-wired).
         *   3. Configured staging: production_url is set AND the siteurl domain differs from it.
         *   4. Default -> allow.
         *
         * Source of truth is always get_site_url() (the DB siteurl), never $_SERVER/HTTP_HOST: it
         * works in CLI/cron, and a live Kinsta site is also reachable on its *.kinsta.cloud system
         * domain - keying off the request host would wrongly block a live site reached that way.
         *
         * Fail-safe is "allow": we only block on a positive non-prod signal, so production can never
         * break.
         *
         * @return array{block:bool,reason:string}
         */
        public function isNonProductionEnvironment(): array
        {
            // 1. DDEV - cannot be disabled
            if ($this->isDdev()) {
                return array('block' => true, 'reason' => 'ddev');
            }

            $siteHost = $this->getSiteUrlHost();

            // 2. Kinsta non-primary system domain - cannot be disabled
            if ($siteHost !== '' && $this->hostEndsWith($siteHost, '.kinsta.cloud')) {
                return array('block' => true, 'reason' => 'kinsta.cloud');
            }

            // 3. Configured staging: a production URL is set and we are not on it.
            $productionUrl = $this->Toolbox->getOption('production_url');
            if ($productionUrl) {
                $prodHost = $this->normaliseDomain($productionUrl);
                if ($siteHost !== '' && $prodHost !== '' && $siteHost !== $prodHost) {
                    return array('block' => true, 'reason' => 'domain-mismatch');
                }
            }

            // 4. True production, or an unconfigured/unknown environment - leave untouched.
            return array('block' => false, 'reason' => 'production');
        }

        /**
         * Memoised wrapper around isNonProductionEnvironment() - the decision is stable for a request.
         *
         * @return array{block:bool,reason:string}
         */
        public function getEnvironment(): array
        {
            if (! is_array($this->_env)) {
                $this->_env = $this->isNonProductionEnvironment();
            }

            return $this->_env;
        }

        // =====================================================================
        // Firewall
        // =====================================================================

        /**
         * pre_http_request callback. Returns $preempt unchanged to let WordPress perform the request,
         * or a WP_Error to short-circuit it.
         *
         * @param mixed $preempt Whatever the previous filter returned (false = "not handled").
         * @param array $args Parsed request args (unused).
         * @param string $url The request URL.
         * @return mixed false|WP_Error
         */
        public function filterOutboundHttp($preempt = false, $args = array(), $url = '')
        {
            // Production fast path: short-circuit before any work so the callback is effectively a no-op.
            if (! $this->getEnvironment()['block']) {
                return $preempt;
            }

            $decision = $this->getOutboundDecision((string) $url);
            if ($decision['kind'] === 'allow') {
                return $preempt;
            }

            $this->recordBlockedHost($decision['host'], (string) $url);

            return new \WP_Error(
                self::BLOCK_ERROR_CODE,
                sprintf('Outbound request to %s blocked (non-production environment).', $decision['host']),
            );
        }

        /**
         * Pure decision for a single URL, assuming a blocking environment. Returns:
         *   ['kind' => 'allow', 'host' => ?string, 'reason' => string] - let it through
         *   ['kind' => 'block', 'host' => string, 'reason' => string] - block it
         *
         * @return array{kind:string,host:?string,reason:string}
         */
        public function getOutboundDecision(string $url): array
        {
            $host = $this->extractHost($url);
            if ($host === '') {
                // Relative/garbage URL - no external host to block.
                return array('kind' => 'allow', 'host' => null, 'reason' => 'no-host');
            }
            if ($this->isHostAllowed($host)) {
                return array('kind' => 'allow', 'host' => $host, 'reason' => 'allowlisted');
            }

            return array('kind' => 'block', 'host' => $host, 'reason' => 'blocked');
        }

        // =====================================================================
        // Allowlist (always-allowed self/loopback + code force-list + DB list)
        // =====================================================================

        /**
         * Whether a host passes any allowlist tier.
         */
        public function isHostAllowed(string $host): bool
        {
            $host = strtolower(trim($host));
            if ($host === '') {
                return false;
            }
            foreach ($this->getEffectiveAllowlist() as $pattern) {
                if ($this->hostMatchesPattern($host, $pattern)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Every allowlist entry in effect: always-allowed self/loopback hosts, the curated toolbox
         * defaults (wordpress.org infra), the code force-list (wp-config constant + filter), and the
         * DB allowlist.
         *
         * @return list<string>
         */
        public function getEffectiveAllowlist(): array
        {
            return $this->normaliseHostList(array_merge(
                $this->getAlwaysAllowedHosts(),
                $this->getDefaultAllowlist(),
                $this->getCodeAllowlist(),
                $this->getDbAllowlist(),
            ));
        }

        /**
         * Curated default allowlist shipped with the toolbox: WordPress.org infrastructure, which is
         * read-only and safe to reach from a clone (see DEFAULT_ALLOWLIST). Filterable via
         * apex_toolbox_http_default_allowlist so a security-conscious site can strip or extend it -
         * unlike getAlwaysAllowedHosts(), these are not permanently unblockable.
         *
         * @return list<string>
         */
        public function getDefaultAllowlist(): array
        {
            $hosts = self::DEFAULT_ALLOWLIST;
            $filtered = apply_filters(self::DEFAULT_ALLOWLIST_FILTER, $hosts);
            if (is_array($filtered)) {
                $hosts = $filtered;
            }

            return $this->normaliseHostList($hosts);
        }

        /**
         * Internal/self hosts that are never blocked in any environment - WordPress performs loopback
         * requests (cron spawn, REST) to its own host, and blocking those would break the site.
         *
         * @return list<string>
         */
        public function getAlwaysAllowedHosts(): array
        {
            $hosts = array('localhost', '127.0.0.1', '::1', '*.ddev.site');
            $siteHost = $this->getSiteUrlHost();
            if ($siteHost !== '') {
                $hosts[] = $siteHost;
            }

            return $hosts;
        }

        /**
         * Code force-allowlist: the APEX_TOOLBOX_HTTP_ALLOWLIST wp-config constant (comma-separated
         * string or array) plus anything added via the apex_toolbox_http_allowlist filter. Authoritative
         * and read-only in the UI so a per-project "always allow X" can't be toggled off by accident.
         *
         * @return list<string>
         */
        public function getCodeAllowlist(): array
        {
            $hosts = $this->parseHostList($this->getConfiguredAllowlistRaw());
            $filtered = apply_filters(self::ALLOWLIST_FILTER, $hosts);
            if (is_array($filtered)) {
                $hosts = $filtered;
            }

            return $this->normaliseHostList($hosts);
        }

        /**
         * Admin-managed DB allowlist (option apex_toolbox_http_allowlist).
         *
         * @return list<string>
         */
        public function getDbAllowlist(): array
        {
            $value = $this->Toolbox->getOption('http_allowlist');
            if (! is_array($value)) {
                $value = $this->parseHostList($value);
            }

            return $this->normaliseHostList($value);
        }

        /**
         * Match a host against an allowlist pattern. Supports an exact host (api.example.com) and a
         * leading-wildcard domain (*.example.com matches any subdomain AND the apex example.com).
         */
        public function hostMatchesPattern(string $host, string $pattern): bool
        {
            $host = strtolower(trim($host));
            $pattern = strtolower(trim($pattern));
            if ($host === '' || $pattern === '') {
                return false;
            }
            if (strpos($pattern, '*.') === 0) {
                // ".example.com"
                $suffix = substr($pattern, 1);
                // "example.com"
                $apex = substr($pattern, 2);
                if ($host === $apex) {
                    return true;
                }

                return strlen($host) > strlen($suffix)
                    && substr($host, -strlen($suffix)) === $suffix;
            }

            return $host === $pattern;
        }

        // =====================================================================
        // Blocked-host discovery log
        // =====================================================================

        /**
         * Record a blocked host for this request. Deduped by host into a pending buffer; persisted once
         * on shutdown (see flushBlockedHosts) so a sync making many blocked calls doesn't hammer the DB.
         */
        public function recordBlockedHost(?string $host, string $url): void
        {
            $host = strtolower(trim((string) $host));
            if ($host === '') {
                return;
            }
            $newlySeen = ! isset($this->_pendingBlocks[ $host ]);
            if ($newlySeen) {
                $this->_pendingBlocks[ $host ] = array('count' => 0, 'sample_url' => $url, 'last_caller' => '');
                // Server-log visibility for each newly-seen host this request.
                error_log('[Apex Outbound Firewall] Blocked outbound request to ' . $host . ' (' . $url . ')');
            }
            $this->_pendingBlocks[ $host ]['count'] += 1;
            $this->_pendingBlocks[ $host ]['sample_url'] = $url;
            $this->_pendingBlocks[ $host ]['last_caller'] = $this->captureCaller();

            $this->ensureShutdownFlush();
        }

        /**
         * Persist the pending blocks into the apex_toolbox_blocked_hosts option. Registered on shutdown.
         */
        public function flushBlockedHosts(): void
        {
            if (empty($this->_pendingBlocks)) {
                return;
            }
            $existing = $this->Toolbox->getOption('blocked_hosts');
            if (! is_array($existing)) {
                $existing = array();
            }
            $merged = $this->mergeBlockedHosts($existing, $this->_pendingBlocks, time());
            $this->Toolbox->setOption('blocked_hosts', $merged);
            $this->_pendingBlocks = array();
        }

        /**
         * Pure merge of pending blocks into the stored log: dedupe by host, increment count, refresh
         * last_seen/sample_url/last_caller, keep first_seen, and cap at MAX_BLOCKED_HOSTS (dropping the
         * oldest by last_seen).
         *
         * @param array $existing Stored log keyed by host.
         * @param array $pending This request's blocks keyed by host.
         * @param int $now Current timestamp.
         * @return array
         */
        public function mergeBlockedHosts(array $existing, array $pending, int $now): array
        {
            foreach ($pending as $host => $data) {
                $count = intval($data['count'] ?? 0);
                if (isset($existing[ $host ])) {
                    $existing[ $host ]['count'] = intval($existing[ $host ]['count'] ?? 0) + $count;
                    $existing[ $host ]['last_seen'] = $now;
                    $existing[ $host ]['sample_url'] = $data['sample_url']
                        ?? ( $existing[ $host ]['sample_url'] ?? '' );
                    $existing[ $host ]['last_caller'] = $data['last_caller']
                        ?? ( $existing[ $host ]['last_caller'] ?? '' );
                } else {
                    $existing[ $host ] = array(
                        'count' => $count,
                        'first_seen' => $now,
                        'last_seen' => $now,
                        'sample_url' => $data['sample_url'] ?? '',
                        'last_caller' => $data['last_caller'] ?? '',
                    );
                }
            }
            if (count($existing) > self::MAX_BLOCKED_HOSTS) {
                uasort($existing, static fn ($a, $b) => intval($b['last_seen'] ?? 0) <=> intval($a['last_seen'] ?? 0));
                $existing = array_slice($existing, 0, self::MAX_BLOCKED_HOSTS, true);
            }

            return $existing;
        }

        /**
         * Walk a debug_backtrace and return the first "file:line" outside WordPress core and this
         * plugin - i.e. the code that actually called wp_remote_*. This is what turns the log from
         * "a host" into "what tried to call out".
         *
         * @param array $frames debug_backtrace() output.
         */
        public function findExternalCaller(array $frames): string
        {
            foreach ($frames as $frame) {
                if (empty($frame['file'])) {
                    continue;
                }
                if ($this->isInternalFile($frame['file'])) {
                    continue;
                }
                $line = isset($frame['line'])
                    ? intval($frame['line'])
                    : 0;

                return $this->relativePath($frame['file']) . ( $line ? ':' . $line : '' );
            }

            return 'unknown';
        }

        // =====================================================================
        // Admin UI
        // =====================================================================

        /**
         * Register the firewall's read-only status/log panels on the plugin settings page.
         */
        public function addFirewallSettings(): void
        {
            $this->Toolbox->addSetting(array(
                'name' => 'http_firewall_status',
                'label' => 'Environment',
                'type' => 'html',
                'value' => $this->renderStatusPanel(),
                'description' => '',
            ), $this->getLabel());

            $this->Toolbox->addSetting(array(
                'name' => 'http_firewall_blocked',
                'label' => 'Blocked Hosts',
                'type' => 'html',
                'value' => $this->renderBlockedHostsPanel(),
                'description' => '',
            ), $this->getLabel());

            $this->Toolbox->addSetting(array(
                'name' => 'http_firewall_allowlist',
                'label' => 'Allowlist',
                'type' => 'html',
                'value' => $this->renderAllowlistPanel(),
                'description' => '',
            ), $this->getLabel());
        }

        /**
         * Status banner: production vs non-production, the reason, and whether the firewall is active.
         */
        public function renderStatusPanel(): string
        {
            $env = $this->getEnvironment();
            $active = $env['block'] && $this->Toolbox->isHookEnabled('outbound_firewall', 'filterOutboundHttp');
            if (! $env['block']) {
                $banner = '<strong>PRODUCTION</strong> - outbound HTTP is not restricted.';
            } else {
                $banner = '<strong>NON-PRODUCTION</strong> (reason: ' . esc_html($env['reason']) . ') - '
                    . ( $active
                        ? 'the outbound firewall is <strong>active</strong>.'
                        : 'the firewall hook is <strong>disabled</strong> (wp-config kill-switch); '
                            . 'outbound HTTP is NOT restricted.' );
            }

            return '<p>This installation is ' . $banner . '</p>';
        }

        /**
         * Blocked-hosts discovery table with per-row Allow / Dismiss actions and a Clear all button.
         */
        public function renderBlockedHostsPanel(): string
        {
            $blocked = $this->Toolbox->getOption('blocked_hosts');
            if (! is_array($blocked) || empty($blocked)) {
                return '<p>No blocked outbound requests have been recorded.</p>';
            }
            uasort($blocked, static fn ($a, $b) => intval($b['last_seen'] ?? 0) <=> intval($a['last_seen'] ?? 0));

            $rows = '';
            foreach ($blocked as $host => $data) {
                $allowUrl = $this->actionUrl(self::ALLOW_HOST_ACTION, $host);
                $dismissUrl = $this->actionUrl(self::DISMISS_HOST_ACTION, $host);
                $lastSeen = intval($data['last_seen'] ?? 0);
                $rows .= '<tr>'
                    . '<td>' . esc_html($host) . '</td>'
                    . '<td>' . intval($data['count'] ?? 0) . '</td>'
                    . '<td>' . ( $lastSeen
                        ? esc_html(human_time_diff($lastSeen, time())) . ' ago'
                        : '&mdash;' ) . '</td>'
                    . '<td>' . $this->renderBlockedDetails($data) . '</td>'
                    . '<td><a href="' . esc_url($allowUrl) . '" class="button button-small">Allow</a> '
                    . '<a href="' . esc_url($dismissUrl) . '" class="button button-small">Dismiss</a></td>'
                    . '</tr>';
            }

            $clearUrl = $this->actionUrl(self::CLEAR_BLOCKED_ACTION, '');

            return $this->tableStyle()
                . '<table class="widefat striped apex-firewall-table" style="max-width:980px;margin:0 0 12px;">'
                . '<thead><tr><th>Host</th><th>Count</th><th>Last seen</th><th>Details</th>'
                . '<th>Actions</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
                . '<p><a href="' . esc_url($clearUrl) . '" class="button button-secondary">Clear all</a></p>';
        }

        /**
         * Allowlist view: code/filter entries (read-only, badged "from wp-config") + editable DB entries,
         * plus a field to add a host manually.
         */
        public function renderAllowlistPanel(): string
        {
            $default = $this->getDefaultAllowlist();
            $code = $this->getCodeAllowlist();
            $db = $this->getDbAllowlist();

            $html = '<p>Self / loopback hosts (localhost, 127.0.0.1, the site\'s own host, *.ddev.site) '
                . 'are always allowed.</p>';

            $html .= $this->tableStyle()
                . '<table class="widefat striped apex-firewall-table" style="max-width:680px;margin:0 0 12px;">'
                . '<thead><tr><th>Host</th><th>Source</th><th>Action</th></tr></thead><tbody>';
            if (empty($default) && empty($code) && empty($db)) {
                $html .= '<tr><td colspan="3"><em>No additional hosts allowlisted.</em></td></tr>';
            }
            foreach ($default as $host) {
                $html .= '<tr><td>' . esc_html($host) . '</td>'
                    . '<td><span class="dashicons dashicons-lock"></span> toolbox default</td><td>&mdash;</td></tr>';
            }
            foreach ($code as $host) {
                $html .= '<tr><td>' . esc_html($host) . '</td>'
                    . '<td><span class="dashicons dashicons-lock"></span> from wp-config</td><td>&mdash;</td></tr>';
            }
            foreach ($db as $host) {
                $removeUrl = $this->actionUrl(self::REMOVE_HOST_ACTION, $host);
                $html .= '<tr><td>' . esc_html($host) . '</td><td>admin</td>'
                    . '<td><a href="' . esc_url($removeUrl) . '" class="button button-small">Remove</a></td></tr>';
            }
            $html .= '</tbody></table>';

            // These panels render INSIDE the settings page's own <form>, and nested forms are invalid
            // HTML (the browser drops the inner one). So instead of a nested form, the host field lives
            // in the outer form and the button retargets just this submit to admin-post.php via the
            // HTML5 formaction/formmethod attributes. No hidden "action" field - that would otherwise
            // ride along on the normal "Update Settings" submit and misroute it.
            $addAction = esc_url(admin_url('admin-post.php?action=' . self::ALLOW_HOST_ACTION));

            return $html . '<p style="margin:0 0 8px;">'
                . wp_nonce_field(self::ALLOW_HOST_ACTION, '_wpnonce', true, false)
                . '<input type="text" name="host" class="regular-text code" '
                . 'placeholder="api.example.com or *.example.com" /> '
                . '<button type="submit" class="button button-secondary" '
                . 'formmethod="post" formaction="' . $addAction . '">Add host</button>'
                . '</p>';
        }

        /**
         * Admin notice nudging the operator to set the Production URL so the domain-mismatch rule can
         * work. Rules 1-2 (DDEV, kinsta.cloud) protect a clone even without it, but a generic staging
         * server needs production_url to be detected.
         */
        public function maybeShowProductionUrlNotice(): void
        {
            if (! current_user_can('manage_options')) {
                return;
            }
            if ($this->Toolbox->getOption('production_url')) {
                return;
            }
            echo '<div class="notice notice-warning"><p><strong>Apex Toolbox:</strong> No Production URL is set. '
                . 'The outbound HTTP firewall still protects DDEV and *.kinsta.cloud clones, but set the '
                . 'Production URL (Apex Toolbox &rarr; Settings) so generic staging servers are detected '
                . 'too.</p></div>';
        }

        // ---- admin-post handlers ----

        public function handleAllowHost(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::ALLOW_HOST_ACTION);
            $host = $this->requestedHost();
            if ($host !== '') {
                $db = $this->getDbAllowlist();
                if (! in_array($host, $db, true)) {
                    $db[] = $host;
                    $this->Toolbox->setOption('http_allowlist', array_values($db));
                }
                // Allowing a host also clears it from the discovery log.
                $this->removeBlockedHost($host);
            }
            $this->redirectToSettings();
        }

        public function handleRemoveHost(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::REMOVE_HOST_ACTION);
            $host = $this->requestedHost();
            if ($host !== '') {
                $db = array_values(array_filter($this->getDbAllowlist(), static fn ($h) => $h !== $host));
                $this->Toolbox->setOption('http_allowlist', $db);
            }
            $this->redirectToSettings();
        }

        public function handleDismissHost(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::DISMISS_HOST_ACTION);
            $host = $this->requestedHost();
            if ($host !== '') {
                $this->removeBlockedHost($host);
            }
            $this->redirectToSettings();
        }

        public function handleClearBlocked(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::CLEAR_BLOCKED_ACTION);
            $this->Toolbox->setOption('blocked_hosts', array());
            $this->redirectToSettings();
        }

        // =====================================================================
        // Seams (wrapped global state so tests can stub via a subclass)
        // =====================================================================

        protected function isDdev(): bool
        {
            return getenv('IS_DDEV_PROJECT') === 'true';
        }

        protected function getSiteUrl(): string
        {
            return (string) get_site_url();
        }

        /** Raw value of the wp-config force-allowlist constant. @return mixed */
        protected function getConfiguredAllowlistRaw()
        {
            return defined(self::CONFIG_CONSTANT)
                ? constant(self::CONFIG_CONSTANT)
                : '';
        }

        /** Shallow backtrace capture; split out so findExternalCaller stays pure/testable. */
        protected function captureCaller(): string
        {
            return $this->findExternalCaller(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12));
        }

        // =====================================================================
        // Internal helpers
        // =====================================================================

        /** Lowercased host of the site URL (the DB siteurl). */
        protected function getSiteUrlHost(): string
        {
            $url = $this->getSiteUrl();
            if ($url === '') {
                return '';
            }

            return $this->normaliseDomain($url);
        }

        /** Lowercased host part of a URL/domain. */
        private function normaliseDomain(string $url): string
        {
            $url = trim($url);
            if ($url === '') {
                return '';
            }

            return strtolower((string) getDomain($url));
        }

        /** Whether $host ends with $suffix (e.g. ".kinsta.cloud"). */
        private function hostEndsWith(string $host, string $suffix): bool
        {
            $host = strtolower($host);
            $suffix = strtolower($suffix);

            return strlen($host) >= strlen($suffix) && substr($host, -strlen($suffix)) === $suffix;
        }

        /** Host part of a request URL, lowercased; '' when there is none. */
        private function extractHost(string $url): string
        {
            $url = trim($url);
            if ($url === '') {
                return '';
            }
            $host = wp_parse_url($url, PHP_URL_HOST);

            return is_string($host)
                ? strtolower($host)
                : '';
        }

        /**
         * Turn a raw allowlist value (CSV string or array) into a clean host list. Strips scheme/path
         * but preserves leading wildcards and exact hosts.
         *
         * @param mixed $raw
         * @return list<string>
         */
        private function parseHostList($raw): array
        {
            if (is_array($raw)) {
                $parts = $raw;
            } else {
                $parts = explode(',', (string) $raw);
            }

            return $this->normaliseHostList($parts);
        }

        /**
         * Normalise a list of host patterns: strip scheme/path, lowercase, trim, drop blanks, de-dupe.
         *
         * @param array $hosts
         * @return list<string>
         */
        private function normaliseHostList(array $hosts): array
        {
            $clean = array();
            foreach ($hosts as $host) {
                $host = strtolower(trim((string) $host));
                if ($host === '') {
                    continue;
                }
                // Strip a scheme if someone pasted a URL.
                $schemePos = strpos($host, '://');
                if ($schemePos !== false) {
                    $host = substr($host, $schemePos + 3);
                }
                // Strip any path.
                $slashPos = strpos($host, '/');
                if ($slashPos !== false) {
                    $host = substr($host, 0, $slashPos);
                }
                $host = trim($host);
                if ($host !== '' && ! in_array($host, $clean, true)) {
                    $clean[] = $host;
                }
            }

            return $clean;
        }

        /** Whether a backtrace file belongs to WordPress core or this plugin (so it isn't the caller). */
        private function isInternalFile(string $file): bool
        {
            $file = str_replace('\\', '/', $file);
            $needles = array(
                str_replace('\\', '/', ABSPATH . 'wp-includes/'),
                str_replace('\\', '/', ABSPATH . 'wp-admin/'),
                str_replace('\\', '/', APEX_TOOLBOX_PLUGIN_PATH),
            );
            foreach ($needles as $needle) {
                if ($needle !== '' && strpos($file, $needle) !== false) {
                    return true;
                }
            }

            return false;
        }

        /** Shorten an absolute path for the log: relative to wp-content/ when possible. */
        private function relativePath(string $file): string
        {
            $file = str_replace('\\', '/', $file);
            $pos = strpos($file, 'wp-content/');
            if ($pos !== false) {
                return substr($file, $pos);
            }

            return $file;
        }

        /** Register the one-time shutdown flush of pending blocks. */
        private function ensureShutdownFlush(): void
        {
            if ($this->_shutdownHooked) {
                return;
            }
            $this->_shutdownHooked = true;
            add_action('shutdown', array($this, 'flushBlockedHosts'));
        }

        /** Remove a host from the stored discovery log. */
        private function removeBlockedHost(string $host): void
        {
            $blocked = $this->Toolbox->getOption('blocked_hosts');
            if (! is_array($blocked) || ! isset($blocked[ $host ])) {
                return;
            }
            unset($blocked[ $host ]);
            $this->Toolbox->setOption('blocked_hosts', $blocked);
        }

        /** Build a nonced admin-post URL for a host action. */
        private function actionUrl(string $action, string $host): string
        {
            $url = admin_url('admin-post.php?action=' . rawurlencode($action));
            if ($host !== '') {
                $url .= '&host=' . rawurlencode($host);
            }

            return wp_nonce_url($url, $action);
        }

        /**
         * Sanitised host from the current request (GET action link or POST form). The nonce is
         * verified by the calling admin-post handler (check_admin_referer) before this is reached, so
         * the NonceVerification sniff is intentionally suppressed here.
         */
        private function requestedHost(): string
        {
            $raw = '';
            // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            if (isset($_POST['host'])) {
                $raw = sanitize_text_field(wp_unslash($_POST['host']));
            } elseif (isset($_GET['host'])) {
                $raw = sanitize_text_field(wp_unslash($_GET['host']));
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            $list = $this->normaliseHostList(array($raw));

            return $list[0] ?? '';
        }

        private function assertManageOptions(): void
        {
            if (! current_user_can('manage_options')) {
                wp_die('Forbidden', 'Forbidden', array('response' => 403));
            }
        }

        private function redirectToSettings(): void
        {
            wp_safe_redirect(admin_url('admin.php?page=apex_toolbox_settings'));
            exit;
        }

        /**
         * Collapsed "View details" cell for a blocked-host row. The sample URL and caller can be very
         * long, so they live inside a native <details> element (no JS) and only expand on click - and
         * wrap rather than stretch the page when open. Returns "&mdash;" if there's nothing to show.
         */
        private function renderBlockedDetails(array $data): string
        {
            $sampleUrl = (string) ( $data['sample_url'] ?? '' );
            $caller = (string) ( $data['last_caller'] ?? '' );
            if ($sampleUrl === '' && $caller === '') {
                return '&mdash;';
            }

            $body = '';
            if ($sampleUrl !== '') {
                $body .= '<div class="apex-fw-detail-row"><strong>Sample URL:</strong><br>'
                    . '<code>' . esc_html($sampleUrl) . '</code></div>';
            }
            if ($caller !== '') {
                $body .= '<div class="apex-fw-detail-row"><strong>Caller:</strong><br>'
                    . '<code>' . esc_html($caller) . '</code></div>';
            }

            return '<details class="apex-fw-details"><summary>View details</summary>'
                . '<div class="apex-fw-detail-body">' . $body . '</div></details>';
        }

        /**
         * Shared CSS for the firewall admin tables: cell padding matching the Elementor Revisions panel
         * (10px 14px), plus the collapsible "View details" styling that keeps long URLs/callers wrapped
         * inside a bounded width so they never stretch the page.
         */
        private function tableStyle(): string
        {
            return '<style>'
                . '.apex-firewall-table { border-collapse:separate; }'
                . '.apex-firewall-table th, .apex-firewall-table td { padding:10px 14px; vertical-align:top; }'
                . '.apex-firewall-table summary { cursor:pointer; color:#2271b1; }'
                . '.apex-firewall-table .apex-fw-detail-body { margin-top:8px; max-width:600px; }'
                . '.apex-firewall-table .apex-fw-detail-row { margin-bottom:8px; }'
                // Long URLs stay on one line inside a fixed-width box that scrolls horizontally, so a
                // giant query string can never stretch the page or balloon the row height.
                . '.apex-firewall-table .apex-fw-detail-body code {'
                . ' display:block; max-width:600px; overflow-x:auto; white-space:nowrap;'
                . ' padding:4px 8px; background:#f6f7f7; border:1px solid #e0e0e0; }'
                . '</style>';
        }
    }

}
