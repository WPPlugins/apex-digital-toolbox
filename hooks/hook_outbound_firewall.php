<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// The curated-rule engine and the shared host/method primitives live in the model, so the class
// constants below can reference it. Required directly (rather than relying on the controller's
// library load) so the hook file stays independently includable - the test suite does exactly that.
require_once __DIR__ . '/../models/toolboxHttpRuleModel.php';
require_once __DIR__ . '/../models/toolboxHttpRuleDefaults.php';
require_once __DIR__ . '/../models/toolboxHttpDownloadWindow.php';
require_once __DIR__ . '/../models/toolboxHttpFirewallTable.php';

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

        // Allow/block is decided per (host, method). This sentinel in an allowlist entry's method set
        // means "every method" - used by the always-allow/curated tiers and by back-compat bare-host
        // entries. A specific entry instead lists exact verbs, e.g. array('GET').
        public const ALL_METHODS = toolboxHttpRuleModel::ALL_METHODS;

        // Filter over the curated default RULES (host + method + path + required parameters), the
        // narrow tier that lets a vendor's read-only endpoint through while its licence-mutating
        // endpoints on the SAME host stay blocked. See toolboxHttpRuleModel.
        public const DEFAULT_RULES_FILTER = 'apex_toolbox_http_default_rules';

        // Placeholder written in place of every request value we log. We only ever keep KEY names;
        // values (query string AND body) are dropped, so a clone's DB never stores real payload data.
        public const REDACTED = '[redacted]';

        // Cap the request body we will parse for key names - a base64 upload must not balloon memory
        // or the stored option. Bodies over this are recorded as an opaque size note.
        public const MAX_BODY_PARSE_BYTES = toolboxHttpRuleModel::MAX_BODY_PARSE_BYTES;

        // WordPress.org infrastructure is safe to reach from a clone: it is read-only (update checks,
        // the plugin/theme browser, translations, dashboard feeds) with no real-world side effects, so
        // it is allowlisted by default to keep a dev environment's dashboard working. Deliberately does
        // NOT include *.wordpress.com - that is the Jetpack/Automattic API surface (sync, stats, social,
        // WooPayments) where calls CAN have outward side effects, exactly what this firewall guards
        // against. Filterable via apex_toolbox_http_default_allowlist so a site can strip it.
        private const DEFAULT_ALLOWLIST = array('*.wordpress.org');

        // Memoised environment decision for this request.
        private $_env = null;

        // The open update-package download window, or null. See toolboxHttpDownloadWindow.
        private $_downloadWindow = null;

        // Blocks accumulated this request, flushed once on shutdown (keeps writes cheap).
        private $_pendingBlocks = array();
        private $_shutdownHooked = false;

        /**
         * The HTTP verbs the firewall recognises for per-method rules. A static function (not an array
         * class constant) per the phpcs note in CLAUDE.md.
         *
         * @return list<string>
         */
        public static function httpMethods(): array
        {
            return toolboxHttpRuleModel::httpMethods();
        }

        /**
         * Normalise a request method to an uppercase known verb. Empty/unknown -> 'GET' (the WordPress
         * HTTP API default when a caller omits 'method').
         *
         * @param mixed $method
         */
        public static function normaliseMethod($method): string
        {
            return toolboxHttpRuleModel::normaliseMethod($method);
        }

        /**
         * The server-log line for a newly-seen (host, method). Pure, so the wording is testable
         * without provoking a write. Only the REDACTED url is ever named here.
         */
        public static function buildBlockedLogMessage(string $method, string $host, string $redactedUrl): string
        {
            return '[Apex Outbound Firewall] Blocked outbound ' . $method . ' request to ' . $host
                . ' (' . $redactedUrl . ')';
        }

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

            // Update-package download window, registered directly for the same reason as the admin
            // glue below - it is part of the one firewall feature, not a separate toggle. NOT gated
            // on is_admin(): updates run from WP-CLI and cron too, which is the case that matters.
            add_filter('upgrader_pre_download', array($this, 'armDownloadWindow'), 1, 2);
            add_action('upgrader_process_complete', array($this, 'closeDownloadWindow'), PHP_INT_MAX);
            add_action('shutdown', array($this, 'closeDownloadWindow'), 1);

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
         * or a WP_Error to short-circuit it. Allow/block is decided per (host, method).
         *
         * @param mixed $preempt Whatever the previous filter returned (false = "not handled").
         * @param array $args Parsed request args ('method' + 'body' inform the decision and the log).
         * @param string $url The request URL.
         * @return mixed false|WP_Error
         */
        public function filterOutboundHttp($preempt = false, $args = array(), $url = '')
        {
            // Production fast path: short-circuit before any work so the callback is effectively a no-op.
            if (! $this->getEnvironment()['block']) {
                return $preempt;
            }

            // The update-package download window is checked first and is NOT an allowlist tier: it
            // permits one specific fetch WordPress's own upgrader has already resolved, plus its
            // redirect hops, and nothing else. See toolboxHttpDownloadWindow.
            if ($this->consumeDownloadWindow((string) $url, is_array($args) ? $args : array())) {
                return $preempt;
            }

            $method = self::normaliseMethod(is_array($args) ? ( $args['method'] ?? 'GET' ) : 'GET');
            $body = is_array($args)
                ? ( $args['body'] ?? null )
                : null;
            $decision = $this->getOutboundDecision((string) $url, $method, $body);
            if ($decision['kind'] === 'allow') {
                return $preempt;
            }
            if ($decision['kind'] === 'respond') {
                // Not recorded in the discovery log: nothing was blocked, and nothing went out. The
                // rule and its reasoning are visible in the firewall settings table instead.
                return toolboxHttpRuleModel::syntheticResponse($decision['respond']);
            }

            $this->recordBlockedHost($decision['host'], $method, (string) $url, $body);

            return new \WP_Error(
                self::BLOCK_ERROR_CODE,
                sprintf('Outbound %s request to %s blocked (non-production environment).', $method, $decision['host']),
            );
        }

        /**
         * Pure decision for a single URL + method, assuming a blocking environment. Returns:
         *   ['kind' => 'allow', 'host' => ?string, 'method' => string, 'reason' => string]
         *   ['kind' => 'block', 'host' => string, 'method' => string, 'reason' => string]
         *
         * Method defaults to GET (the WordPress HTTP API default when a caller omits it).
         *
         * $body is only ever READ here, to evaluate a curated rule's `require` parameter constraints;
         * nothing derived from it is stored (the discovery log keeps key names only - see
         * redactRequestShape).
         *
         * @param mixed $body The request body (string or array), for curated-rule parameter matching.
         * @return array{kind:string,host:?string,method:string,reason:string}
         */
        public function getOutboundDecision(string $url, string $method = 'GET', $body = null): array
        {
            $method = self::normaliseMethod($method);
            $host = $this->extractHost($url);
            if ($host === '') {
                // Relative/garbage URL - no external host to block.
                return array('kind' => 'allow', 'host' => null, 'method' => $method, 'reason' => 'no-host');
            }
            if ($this->isMethodAllowed($host, $method)) {
                return array('kind' => 'allow', 'host' => $host, 'method' => $method, 'reason' => 'allowlisted');
            }
            $rule = toolboxHttpRuleModel::firstMatch($this->getDefaultRules(), $host, $method, $url, $body);
            if ($rule !== null) {
                if (! empty($rule['respond'])) {
                    // Answered locally - nothing leaves the site. See toolboxHttpRuleModel::normaliseRespond.
                    return array(
                        'kind' => 'respond',
                        'host' => $host,
                        'method' => $method,
                        'reason' => 'curated-rule-respond',
                        'respond' => $rule['respond'],
                    );
                }

                return array('kind' => 'allow', 'host' => $host, 'method' => $method, 'reason' => 'curated-rule');
            }

            return array('kind' => 'block', 'host' => $host, 'method' => $method, 'reason' => 'blocked');
        }

        // =====================================================================
        // Update-package download window
        // =====================================================================

        /**
         * Arm the window: WP_Upgrader::download_package() is about to fetch $package. Registered on
         * upgrader_pre_download at priority 1 and passes $reply straight through - this observes the
         * upgrade, it never short-circuits it.
         *
         * Re-arming per package is deliberate: a bulk update calls download_package() once per
         * plugin, and each gets its own single-package window rather than one wide one.
         *
         * @param mixed $reply Whatever an earlier filter returned; returned untouched.
         * @param mixed $package The resolved package URL.
         * @return mixed
         */
        public function armDownloadWindow($reply = false, $package = '')
        {
            $this->_downloadWindow = toolboxHttpDownloadWindow::open($package);

            return $reply;
        }

        /**
         * Close the window. Registered on upgrader_process_complete and on shutdown as a backstop, so
         * a window can never outlive the request that opened it even if an upgrade dies mid-flight.
         */
        public function closeDownloadWindow(): void
        {
            $this->_downloadWindow = null;
        }

        /**
         * Whether this request is the armed package fetch (or one of its redirect hops), advancing
         * the window's hop count when it is. Named "consume" because it is not a pure predicate: a
         * permitted hop spends one of the window's bounded allowance.
         *
         * @param array $args The parsed request args.
         */
        public function consumeDownloadWindow(string $url, array $args): bool
        {
            if ($this->_downloadWindow === null) {
                return false;
            }
            $result = toolboxHttpDownloadWindow::evaluate($this->_downloadWindow, $url, $args);
            $this->_downloadWindow = $result['window'];

            return (bool) $result['allow'];
        }

        /** The open download window, or null. Exposed for tests. */
        public function getDownloadWindow()
        {
            return $this->_downloadWindow;
        }

        // =====================================================================
        // Allowlist (always-allowed self/loopback + code force-list + DB list)
        // =====================================================================

        /**
         * Whether a (host, method) pair passes any allowlist tier. A tier entry allows the method when
         * its method set contains the exact verb or the ALL_METHODS sentinel.
         */
        public function isMethodAllowed(string $host, string $method): bool
        {
            $host = strtolower(trim($host));
            $method = self::normaliseMethod($method);
            if ($host === '') {
                return false;
            }
            foreach ($this->getEffectiveAllowlist() as $entry) {
                if (! $this->hostMatchesPattern($host, $entry['host'])) {
                    continue;
                }
                $methods = $entry['methods'];
                $allowed = in_array(self::ALL_METHODS, $methods, true)
                    || in_array($method, $methods, true);
                if ($allowed) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Every allowlist entry in effect as {host, methods} records: always-allowed self/loopback
         * hosts and the curated toolbox defaults (wordpress.org infra) - both ALL_METHODS - plus the
         * code force-list (wp-config constant + filter) and the admin DB allowlist, which each carry
         * their own per-method sets. isMethodAllowed() unions across every matching entry.
         *
         * @return list<array{host:string,methods:list<string>}>
         */
        public function getEffectiveAllowlist(): array
        {
            return array_merge(
                $this->normaliseAllowlistEntries($this->getAlwaysAllowedHosts()),
                $this->normaliseAllowlistEntries($this->getDefaultAllowlist()),
                $this->getCodeAllowlist(),
                $this->getDbAllowlist(),
            );
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
         * The curated default RULES in effect, normalised. Unlike the host-level default allowlist,
         * a rule pins the exact methods, path prefixes and parameter values a vendor host may be
         * reached on, so a read-only update check can be allowed while a licence deactivate on the
         * SAME host stays blocked. Anything a rule does not match stays blocked and keeps appearing
         * in the discovery log.
         *
         * Filterable via apex_toolbox_http_default_rules so a site can strip or extend the set,
         * mirroring the default allowlist tier. See toolboxHttpRuleModel for the rule shape.
         *
         * @return list<array{host:string,methods:list<string>,paths:list<string>,require:array<string,list<string>>,note:string}>
         */
        public function getDefaultRules(): array
        {
            $rules = toolboxHttpRuleDefaults::rules();
            $filtered = apply_filters(self::DEFAULT_RULES_FILTER, $rules);
            if (is_array($filtered)) {
                $rules = $filtered;
            }

            return toolboxHttpRuleModel::normalise($rules);
        }

        /**
         * Whether a request is permitted by a curated rule. $body is read only to evaluate a rule's
         * `require` parameter constraints and is never stored - the discovery log keeps key names only.
         *
         * @param mixed $body
         */
        public function matchesCuratedRule(string $host, string $method, string $url, $body = null): bool
        {
            return toolboxHttpRuleModel::matches($this->getDefaultRules(), $host, $method, $url, $body);
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
         * Code force-allowlist: the APEX_TOOLBOX_HTTP_ALLOWLIST wp-config constant plus anything added
         * via the apex_toolbox_http_allowlist filter. Authoritative and read-only in the UI so a
         * per-project "always allow X" can't be toggled off by accident.
         *
         * The constant carries per-method scope using the same shapes as the DB tier (see
         * normaliseAllowlistEntries) - a host => methods map is the readable one:
         *
         *     define('APEX_TOOLBOX_HTTP_ALLOWLIST', array('api.example.com' => array('GET')));
         *
         * A comma-separated string ('a.example.com, *.b.example.com') or a flat array of bare hosts
         * still means "all methods", so existing wp-config entries are unaffected.
         *
         * The filter both receives and returns {host, methods} entries. Appending a bare host string
         * to that list also works (the list may be mixed), so a filter that only adds hosts keeps
         * working unchanged.
         *
         * @return list<array{host:string,methods:list<string>}>
         */
        public function getCodeAllowlist(): array
        {
            $entries = $this->normaliseAllowlistEntries($this->getConfiguredAllowlistRaw());
            $filtered = apply_filters(self::ALLOWLIST_FILTER, $entries);
            if (is_array($filtered)) {
                $entries = $this->normaliseAllowlistEntries($filtered);
            }

            return $entries;
        }

        /**
         * Admin-managed DB allowlist (option apex_toolbox_http_allowlist) as {host, methods} entries.
         *
         * Accepts both the legacy flat list of bare host strings (migrated to ALL_METHODS) and the
         * current shape - an assoc map host => list<method>. Migration is read-time, so an upgrade
         * needs no DB rewrite: existing allowed hosts keep behaving as "all methods", exactly as before.
         *
         * @return list<array{host:string,methods:list<string>}>
         */
        public function getDbAllowlist(): array
        {
            return $this->normaliseAllowlistEntries($this->Toolbox->getOption('http_allowlist'));
        }

        /**
         * Match a host against an allowlist pattern. Supports an exact host (api.example.com) and a
         * leading-wildcard domain (*.example.com matches any subdomain AND the apex example.com).
         */
        public function hostMatchesPattern(string $host, string $pattern): bool
        {
            return toolboxHttpRuleModel::hostMatchesPattern($host, $pattern);
        }

        // =====================================================================
        // Blocked-host discovery log
        // =====================================================================

        /**
         * Record a blocked request for this request cycle, keyed by (host, method). Only the redacted
         * SHAPE is kept (URL with query values masked, plus query/body key names) - never any values.
         * Deduped into a pending buffer, persisted once on shutdown (see flushBlockedHosts).
         *
         * @param mixed $body The request body (string or array), used only to derive key names.
         */
        public function recordBlockedHost(?string $host, string $method, string $url, $body = null): void
        {
            $host = strtolower(trim((string) $host));
            if ($host === '') {
                return;
            }
            $method = self::normaliseMethod($method);
            $key = $host . '|' . $method;
            $shape = $this->redactRequestShape($url, $body);

            if (! isset($this->_pendingBlocks[ $key ])) {
                $this->_pendingBlocks[ $key ] = array('host' => $host, 'method' => $method, 'count' => 0);
                // Server-log visibility for each newly-seen (host, method); log the REDACTED url only.
                $this->logBlockedHost(
                    self::buildBlockedLogMessage($method, $host, $shape['redacted_url']),
                );
            }
            $this->_pendingBlocks[ $key ]['count'] += 1;
            $this->_pendingBlocks[ $key ]['redacted_url'] = $shape['redacted_url'];
            $this->_pendingBlocks[ $key ]['query_keys'] = $shape['query_keys'];
            $this->_pendingBlocks[ $key ]['body_keys'] = $shape['body_keys'];
            $this->_pendingBlocks[ $key ]['last_caller'] = $this->captureCaller();

            $this->ensureShutdownFlush();
        }

        /**
         * Redact a request to its shape: the URL with query VALUES masked, plus the key names of the
         * query string and body. Returns key names only - no values, ever - so a production-data clone
         * never persists real payload/secret data (and it closes the old raw sample_url query leak).
         *
         * @param mixed $body WP HTTP API body: string (form/JSON/raw) or array. Key names only.
         * @return array{redacted_url:string,query_keys:list<string>,body_keys:list<string>}
         */
        public function redactRequestShape(string $url, $body = null): array
        {
            $queryKeys = array();
            $redactedUrl = $url;

            $parts = wp_parse_url($url);
            if (is_array($parts) && ! empty($parts['query'])) {
                $pairs = array();
                parse_str((string) $parts['query'], $pairs);
                $queryKeys = array_map('strval', array_keys($pairs));

                $masked = array();
                foreach ($queryKeys as $k) {
                    $masked[] = rawurlencode($k) . '=' . self::REDACTED;
                }
                $scheme = isset($parts['scheme'])
                    ? $parts['scheme'] . '://'
                    : '';
                $port = isset($parts['port'])
                    ? ':' . $parts['port']
                    : '';
                $redactedUrl = $scheme . ( $parts['host'] ?? '' ) . $port
                    . ( $parts['path'] ?? '' ) . '?' . implode('&', $masked);
            }

            return array(
                'redacted_url' => $redactedUrl,
                'query_keys' => $queryKeys,
                'body_keys' => $this->extractBodyKeys($body),
            );
        }

        /**
         * Write the block line to the server log - except under WP-CLI, where it must not be written
         * at all by default.
         *
         * PHP CLI has no error_log destination configured, so error_log() falls back to STDERR and the
         * line lands in the middle of command output. That is not cosmetic: it breaks any script
         * parsing `wp plugin list`, and a blocked outbound call is entirely expected on a clone, so
         * every such script would break on every run. Automating the monthly update pass is the whole
         * point of this feature, so the firewall must be silent on the channel that automation reads.
         *
         * The diagnostic is not lost - it is routed to WP_CLI::debug(), so `--debug` (or
         * `--debug=apex-firewall`) still shows it, on stderr, only when asked for. The blocked-request
         * log in the database records every block regardless of environment, and is the real record.
         */
        public function logBlockedHost(string $message): void
        {
            if ($this->isWpCli()) {
                if (class_exists('\WP_CLI')) {
                    \WP_CLI::debug($message, 'apex-firewall');
                }

                return;
            }

            error_log($message);
        }

        /**
         * Persist the pending blocks into the apex_toolbox_blocked_hosts option. Registered on shutdown.
         *
         * Reads the CURRENT option straight from the DB (not the Toolbox's per-instance cache): this
         * runs on shutdown, after a handler in the same request may have removed/allowed a row, and a
         * stale cache read here would resurrect that just-removed row. See readBlockedHostsOption().
         */
        public function flushBlockedHosts(): void
        {
            if (empty($this->_pendingBlocks)) {
                return;
            }
            $merged = $this->mergeBlockedHosts($this->readBlockedHostsOption(), $this->_pendingBlocks, time());
            $this->writeBlockedHostsOption($merged);
            $this->_pendingBlocks = array();
        }

        /**
         * Pure merge of pending blocks into the stored log. Keyed by "host|METHOD", so each verb to an
         * endpoint is its own row: increment count, refresh last_seen + redacted shape + caller, keep
         * first_seen, and cap at MAX_BLOCKED_HOSTS (dropping the oldest by last_seen).
         *
         * @param array $existing Stored log keyed by "host|METHOD".
         * @param array $pending This request's blocks keyed by "host|METHOD".
         * @param int $now Current timestamp.
         * @return array
         */
        public function mergeBlockedHosts(array $existing, array $pending, int $now): array
        {
            $carry = array('host', 'method', 'redacted_url', 'query_keys', 'body_keys', 'last_caller');

            foreach ($pending as $key => $data) {
                $count = intval($data['count'] ?? 0);
                if (isset($existing[ $key ])) {
                    $existing[ $key ]['count'] = intval($existing[ $key ]['count'] ?? 0) + $count;
                    $existing[ $key ]['last_seen'] = $now;
                    foreach ($carry as $field) {
                        if (isset($data[ $field ])) {
                            $existing[ $key ][ $field ] = $data[ $field ];
                        }
                    }
                } else {
                    $existing[ $key ] = array(
                        'host' => $data['host'] ?? '',
                        'method' => $data['method'] ?? '',
                        'count' => $count,
                        'first_seen' => $now,
                        'last_seen' => $now,
                        'redacted_url' => $data['redacted_url'] ?? '',
                        'query_keys' => $data['query_keys'] ?? array(),
                        'body_keys' => $data['body_keys'] ?? array(),
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
            $blocked = $this->readBlockedHostsOption();
            if (empty($blocked)) {
                return '<p>No blocked outbound requests have been recorded.</p>';
            }
            uasort($blocked, static fn ($a, $b) => intval($b['last_seen'] ?? 0) <=> intval($a['last_seen'] ?? 0));

            $rows = '';
            foreach ($blocked as $data) {
                $host = (string) ( $data['host'] ?? '' );
                $method = (string) ( $data['method'] ?? '' );
                // Skip any legacy/malformed row without an explicit host+method.
                if ($host === '' || $method === '') {
                    continue;
                }
                $allowUrl = $this->actionUrl(self::ALLOW_HOST_ACTION, $host, $method);
                $dismissUrl = $this->actionUrl(self::DISMISS_HOST_ACTION, $host, $method);
                $lastSeen = intval($data['last_seen'] ?? 0);
                $rows .= '<tr>'
                    . '<td>' . esc_html($host) . '</td>'
                    . '<td><code>' . esc_html($method) . '</code></td>'
                    . '<td>' . intval($data['count'] ?? 0) . '</td>'
                    . '<td>' . ( $lastSeen
                        ? esc_html(human_time_diff($lastSeen, time())) . ' ago'
                        : '&mdash;' ) . '</td>'
                    . '<td>' . $this->renderBlockedDetails($data) . '</td>'
                    . '<td><a href="' . esc_url($allowUrl) . '" class="button button-small">Allow '
                    . esc_html($method) . '</a> '
                    . '<a href="' . esc_url($dismissUrl) . '" class="button button-small">Dismiss</a></td>'
                    . '</tr>';
            }

            $clearUrl = $this->actionUrl(self::CLEAR_BLOCKED_ACTION, '', '');

            return toolboxHttpFirewallTable::style()
                . '<p>Values are never stored - only which request <em>keys</em> were sent. '
                . '&ldquo;Allow&rdquo; opens exactly that host + method; other verbs stay blocked.</p>'
                . '<table class="widefat striped apex-firewall-table" style="max-width:1040px;margin:0 0 12px;">'
                . '<thead><tr><th>Host</th><th>Method</th><th>Count</th><th>Last seen</th><th>Request</th>'
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
            $dbMap = $this->dbAllowlistMap();

            $html = '<p>Self / loopback hosts (localhost, 127.0.0.1, the site\'s own host, *.ddev.site) '
                . 'are always allowed (all methods). Allowlisting is per <em>host + method</em>: allowing '
                . 'a GET never opens POST/PUT/DELETE to the same host.</p>';

            $html .= toolboxHttpFirewallTable::style()
                . '<table class="widefat striped apex-firewall-table" style="max-width:760px;margin:0 0 12px;">'
                . '<thead><tr><th>Host</th><th>Methods</th><th>Source</th><th>Action</th></tr></thead><tbody>';
            if (empty($default) && empty($code) && empty($dbMap)) {
                $html .= '<tr><td colspan="4"><em>No additional hosts allowlisted.</em></td></tr>';
            }
            foreach ($default as $host) {
                $html .= '<tr><td>' . esc_html($host) . '</td><td><code>ALL</code></td>'
                    . '<td><span class="dashicons dashicons-lock"></span> toolbox default</td><td>&mdash;</td></tr>';
            }
            foreach ($code as $entry) {
                foreach ($entry['methods'] as $method) {
                    $label = $method === self::ALL_METHODS
                        ? 'ALL'
                        : $method;
                    $html .= '<tr><td>' . esc_html($entry['host']) . '</td>'
                        . '<td><code>' . esc_html($label) . '</code></td>'
                        . '<td><span class="dashicons dashicons-lock"></span> from wp-config</td>'
                        . '<td>&mdash;</td></tr>';
                }
            }
            foreach ($dbMap as $host => $methods) {
                foreach ($methods as $method) {
                    $isAll = $method === self::ALL_METHODS;
                    $removeUrl = $this->actionUrl(self::REMOVE_HOST_ACTION, (string) $host, $isAll ? '' : $method);
                    $html .= '<tr><td>' . esc_html((string) $host) . '</td>'
                        . '<td><code>' . esc_html($isAll ? 'ALL' : $method) . '</code></td><td>admin</td>'
                        . '<td><a href="' . esc_url($removeUrl) . '" class="button button-small">Remove</a></td></tr>';
                }
            }
            $html .= '</tbody></table>';

            return $html . toolboxHttpFirewallTable::curatedRules($this->getDefaultRules())
                . $this->renderAddHostForm();
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
            $methods = $this->requestedMethods();
            if ($host !== '' && ! empty($methods)) {
                $map = $this->dbAllowlistMap();
                $existing = $map[ $host ] ?? array();
                $merged = $this->normaliseMethodList(array_merge($existing, $methods));
                // requestedMethods() only ever returns known verbs, so this cannot normally be empty
                // - but never persist an empty set, which would read back as "no methods supplied"
                // and therefore ALL_METHODS. Nothing to save means nothing to unblock either.
                if (! empty($merged)) {
                    $map[ $host ] = $merged;
                    $this->Toolbox->setOption('http_allowlist', $map);
                    // Allowing a (host, method) also clears its matching discovery-log rows.
                    foreach ($methods as $method) {
                        $this->removeBlockedHost($host, $method);
                    }
                }
            }
            $this->redirectToSettings();
        }

        public function handleRemoveHost(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::REMOVE_HOST_ACTION);
            $host = $this->requestedHost();
            if ($host !== '') {
                $method = $this->requestedMethod();
                $map = $this->dbAllowlistMap();
                if (isset($map[ $host ])) {
                    if ($method === '' || in_array(self::ALL_METHODS, $map[ $host ], true)) {
                        unset($map[ $host ]);
                    } else {
                        $map[ $host ] = array_values(
                            array_filter($map[ $host ], static fn ($m) => $m !== $method),
                        );
                        if (empty($map[ $host ])) {
                            unset($map[ $host ]);
                        }
                    }
                    $this->Toolbox->setOption('http_allowlist', $map);
                }
            }
            $this->redirectToSettings();
        }

        public function handleDismissHost(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::DISMISS_HOST_ACTION);
            $host = $this->requestedHost();
            if ($host !== '') {
                $this->removeBlockedHost($host, $this->requestedMethod());
            }
            $this->redirectToSettings();
        }

        public function handleClearBlocked(): void
        {
            $this->assertManageOptions();
            check_admin_referer(self::CLEAR_BLOCKED_ACTION);
            $this->writeBlockedHostsOption(array());
            $this->redirectToSettings();
        }

        /** Whether this request is running under WP-CLI. Wrapped so tests can drive both paths. */
        protected function isWpCli(): bool
        {
            return defined('WP_CLI') && constant('WP_CLI');
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
         * Normalise any allowlist input into a list of {host, methods} entries. Accepts (and may mix):
         *   - a CSV string or a flat list of bare hosts -> each host, ALL_METHODS (back-compat)
         *   - an assoc map host => list<method> -> per-method
         *   - a list of {host, methods} records -> per-method
         * The shapes may be mixed in one list. Unknown/blank hosts are dropped, as is any entry with
         * no host to read at all (e.g. a bare method list under a numeric key). This is the single
         * migration point for the legacy flat DB allowlist (bare strings) -> all methods, and the
         * single entry point for the wp-config constant and its filter.
         *
         * @param mixed $raw
         * @return list<array{host:string,methods:list<string>}>
         */
        private function normaliseAllowlistEntries($raw): array
        {
            if (is_string($raw)) {
                $raw = explode(',', $raw);
            }
            if (! is_array($raw)) {
                return array();
            }

            $entries = array();
            foreach ($raw as $key => $value) {
                if (is_string($key)) {
                    // A string key is always the host (PHP casts numeric-string keys to int, so this
                    // never catches a flat list). The value is its method set - array or single verb.
                    $host = $key;
                    $methods = (array) $value;
                } elseif (is_array($value) && isset($value['host']) && is_scalar($value['host'])) {
                    $host = (string) $value['host'];
                    $methods = $value['methods'] ?? array(self::ALL_METHODS);
                } elseif (is_scalar($value)) {
                    $host = (string) $value;
                    $methods = array(self::ALL_METHODS);
                } else {
                    // No host to read (e.g. a bare method list under a numeric key) - never cast an
                    // array to the string "Array" and allowlist that.
                    continue;
                }

                $host = $this->normaliseHostList(array($host))[0] ?? '';
                if ($host === '') {
                    continue;
                }

                $methods = (array) $methods;
                $clean = $this->normaliseMethodList($methods);
                if (empty($clean)) {
                    if (! empty($methods)) {
                        // A method set WAS supplied and not one verb in it was recognised - a typo.
                        // Drop the entry: this tier's whole purpose is to withhold methods, so
                        // widening it to ALL_METHODS here would invert the operator's intent. The
                        // host simply stays blocked, and shows up in the discovery log.
                        continue;
                    }
                    // No method set at all (a legacy bare host) - as permissive as it always was.
                    $clean = array(self::ALL_METHODS);
                }
                $entries[] = array('host' => $host, 'methods' => $clean);
            }

            return $entries;
        }

        /**
         * Clean a method set - see toolboxHttpRuleModel::normaliseMethodList for the semantics of an
         * empty result (they are load-bearing: a typo'd method set must drop its entry, not widen it).
         *
         * @param array $methods
         * @return list<string>
         */
        private function normaliseMethodList(array $methods): array
        {
            return toolboxHttpRuleModel::normaliseMethodList($methods);
        }

        /**
         * Key names of a request body, values dropped. Handles an array body, a JSON string, and a
         * form-encoded string; an oversized or unparseable body is recorded as an opaque size note.
         *
         * @param mixed $body
         * @return list<string>
         */
        private function extractBodyKeys($body): array
        {
            if (is_array($body)) {
                return array_map('strval', array_keys($body));
            }
            if (! is_string($body) || $body === '') {
                return array();
            }
            if (strlen($body) > self::MAX_BODY_PARSE_BYTES) {
                return array('(body too large: ' . strlen($body) . ' bytes)');
            }

            $trimmed = ltrim($body);
            if ($trimmed !== '' && ( $trimmed[0] === '{' || $trimmed[0] === '[' )) {
                $decoded = json_decode($body, true);

                return is_array($decoded)
                    ? array_map('strval', array_keys($decoded))
                    : array('(opaque body: ' . strlen($body) . ' bytes)');
            }

            $pairs = array();
            parse_str($body, $pairs);

            return empty($pairs)
                ? array('(opaque body: ' . strlen($body) . ' bytes)')
                : array_map('strval', array_keys($pairs));
        }

        /**
         * Normalise a list of host patterns - see toolboxHttpRuleModel::normaliseHostList. Callers that
         * need per-method scope must go through normaliseAllowlistEntries(), which reads the key before
         * handing the host here.
         *
         * @param array $hosts
         * @return list<string>
         */
        private function normaliseHostList(array $hosts): array
        {
            return toolboxHttpRuleModel::normaliseHostList($hosts);
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

        /**
         * Blocked-host log read straight from the DB, bypassing the Toolbox's per-instance option
         * cache. This option is mutated more than once per request - a handler's removeBlockedHost,
         * then the shutdown flush - and setOption() does NOT refresh that cache, so a cached read
         * would operate on stale data and resurrect a just-removed row. update_option keeps WP's own
         * cache coherent within the request, so these raw reads/writes stay consistent.
         *
         * @return array<string,mixed>
         */
        private function readBlockedHostsOption(): array
        {
            $value = get_option($this->Toolbox->getPrefix() . 'blocked_hosts', array());

            return is_array($value)
                ? $value
                : array();
        }

        /** @param array<string,mixed> $value */
        private function writeBlockedHostsOption(array $value): void
        {
            update_option($this->Toolbox->getPrefix() . 'blocked_hosts', $value, false);
        }

        /**
         * Remove matching rows from the stored discovery log. With a method, removes just that
         * "host|METHOD" row; without one, removes every row for the host (any method).
         */
        private function removeBlockedHost(string $host, string $method = ''): void
        {
            $blocked = $this->readBlockedHostsOption();
            $changed = false;
            foreach ($blocked as $key => $data) {
                if ((string) ( $data['host'] ?? '' ) !== $host) {
                    continue;
                }
                if ($method !== '' && (string) ( $data['method'] ?? '' ) !== $method) {
                    continue;
                }
                unset($blocked[ $key ]);
                $changed = true;
            }
            if ($changed) {
                $this->writeBlockedHostsOption($blocked);
            }
        }

        /**
         * The DB allowlist collapsed to a host => list<method> map (one entry per host). Entries
         * arrive already normalised, so a method set here is never empty; the guard keeps a host out
         * of the map rather than storing one, since an empty set reads back as ALL_METHODS.
         */
        private function dbAllowlistMap(): array
        {
            $map = array();
            foreach ($this->getDbAllowlist() as $entry) {
                $host = $entry['host'];
                $existing = $map[ $host ] ?? array();
                $merged = $this->normaliseMethodList(array_merge($existing, $entry['methods']));
                if (! empty($merged)) {
                    $map[ $host ] = $merged;
                }
            }

            return $map;
        }

        /** Build a nonced admin-post URL for a (host, method) action. */
        private function actionUrl(string $action, string $host, string $method = ''): string
        {
            $url = admin_url('admin-post.php?action=' . rawurlencode($action));
            if ($host !== '') {
                $url .= '&host=' . rawurlencode($host);
            }
            if ($method !== '') {
                $url .= '&method=' . rawurlencode($method);
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

        /**
         * A single requested method (the log's "Allow GET" / "Dismiss" links). '' when absent or not a
         * known verb - callers treat '' as "all methods / any row". Nonce verified by the handler.
         */
        private function requestedMethod(): string
        {
            $raw = '';
            // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            if (isset($_POST['method'])) {
                $raw = sanitize_text_field(wp_unslash($_POST['method']));
            } elseif (isset($_GET['method'])) {
                $raw = sanitize_text_field(wp_unslash($_GET['method']));
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            $raw = strtoupper(trim($raw));

            return in_array($raw, self::httpMethods(), true)
                ? $raw
                : '';
        }

        /**
         * The requested method set for an allow: the "Add host" form's methods[] checkboxes, or the
         * single method from an "Allow {VERB}" link. Empty when none are valid. Nonce verified by the
         * handler.
         *
         * @return list<string>
         */
        private function requestedMethods(): array
        {
            $raw = array();
            // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            if (isset($_POST['methods']) && is_array($_POST['methods'])) {
                $raw = array_map('sanitize_text_field', wp_unslash($_POST['methods']));
            }
            // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
            $single = $this->requestedMethod();
            if ($single !== '') {
                $raw[] = $single;
            }

            $methods = array();
            foreach ($raw as $method) {
                $method = strtoupper(trim((string) $method));
                if (in_array($method, self::httpMethods(), true) && ! in_array($method, $methods, true)) {
                    $methods[] = $method;
                }
            }

            return $methods;
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
         * Collapsed "View details" cell for a blocked row: the redacted URL (query VALUES masked), the
         * query + body KEY names (values never stored), and the caller. All tucked inside a native
         * <details> (no JS) so long values wrap rather than stretch the page. "&mdash;" if empty.
         */
        private function renderBlockedDetails(array $data): string
        {
            $redactedUrl = (string) ( $data['redacted_url'] ?? '' );
            $queryKeys = is_array($data['query_keys'] ?? null)
                ? $data['query_keys']
                : array();
            $bodyKeys = is_array($data['body_keys'] ?? null)
                ? $data['body_keys']
                : array();
            $caller = (string) ( $data['last_caller'] ?? '' );

            if ($redactedUrl === '' && empty($queryKeys) && empty($bodyKeys) && $caller === '') {
                return '&mdash;';
            }

            $body = '';
            if ($redactedUrl !== '') {
                $body .= '<div class="apex-fw-detail-row"><strong>Request (values redacted):</strong><br>'
                    . '<code>' . esc_html($redactedUrl) . '</code></div>';
            }
            if (! empty($queryKeys)) {
                $body .= '<div class="apex-fw-detail-row"><strong>Query keys:</strong> '
                    . '<code>' . esc_html(implode(', ', array_map('strval', $queryKeys))) . '</code></div>';
            }
            if (! empty($bodyKeys)) {
                $body .= '<div class="apex-fw-detail-row"><strong>Body keys:</strong> '
                    . '<code>' . esc_html(implode(', ', array_map('strval', $bodyKeys))) . '</code></div>';
            }
            if ($caller !== '') {
                $body .= '<div class="apex-fw-detail-row"><strong>Caller:</strong><br>'
                    . '<code>' . esc_html($caller) . '</code></div>';
            }

            return '<details class="apex-fw-details"><summary>View details</summary>'
                . '<div class="apex-fw-detail-body">' . $body . '</div></details>';
        }

        /**
         * The "Add host" row: a host field + a checkbox per HTTP verb. Renders INSIDE the settings
         * page's own <form> (a nested <form> is invalid HTML and misfires), so the button retargets its
         * submit to admin-post.php via the HTML5 formaction/formmethod attributes.
         */
        private function renderAddHostForm(): string
        {
            $addAction = esc_url(admin_url('admin-post.php?action=' . self::ALLOW_HOST_ACTION));

            $boxes = '';
            foreach (self::httpMethods() as $method) {
                $checked = $method === 'GET'
                    ? ' checked'
                    : '';
                $boxes .= '<label style="margin-right:10px;white-space:nowrap;">'
                    . '<input type="checkbox" name="methods[]" value="' . esc_attr($method) . '"' . $checked . '> '
                    . esc_html($method) . '</label>';
            }

            return '<p style="margin:0 0 6px;">'
                . wp_nonce_field(self::ALLOW_HOST_ACTION, '_wpnonce', true, false)
                . '<input type="text" name="host" class="regular-text code" '
                . 'placeholder="api.example.com or *.example.com" /></p>'
                . '<p style="margin:0 0 8px;">' . $boxes
                . '<button type="submit" class="button button-secondary" '
                . 'formmethod="post" formaction="' . $addAction . '">Add host</button>'
                . '</p>';
        }
    }

}
