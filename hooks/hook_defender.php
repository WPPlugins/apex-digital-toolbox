<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookDefender')) {
    class toolboxHookDefender extends toolboxHookController
    {
        /**
         * WP All Import's admin page slug. Its chunked import loop repeatedly requests
         * admin.php?page=pmxi-admin-import&action=process while an import runs.
         */
        public const WP_ALL_IMPORT_PAGE = 'pmxi-admin-import';

        /**
         * Actions used by WP All Import's admin-side import loop.
         *
         * A method rather than an array class constant: phpcbf mangles multi-line array
         * constants (see the phpcs caveat in CLAUDE.md).
         *
         * @return array<string>
         */
        private static function adminImportActions()
        {
            return array('process', 'processing');
        }

        public function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('WP Defender');
            $this->addHook(APEX_TOOLBOX_HOOK_FILTER, 'wp_defender_audit_hooks', 'ignoreAuditHooksDuringImport', array(
                'label' => 'Stop WP Defender Logging Every Imported Row (Audit Log Bloat)',
                'description' => 'Turn this on for any site running WP All Import feeds. '
                    . 'WP Defender writes an audit-log row for every post and attachment an import touches, '
                    . 'which can grow {prefix}_defender_audit_log to tens of gigabytes - its own retention '
                    . 'cleanup does not keep up. This returns an empty audit-hooks list on importer requests '
                    . 'only, so ordinary site activity is still audited. Matches a WP All Import cron request '
                    . 'whose "import_key" is this site\'s real cron key, whatever action it carries, and the '
                    . 'WP All Import admin screen while someone with permission to run imports is processing '
                    . 'rows. A theme or plugin can declare its own importer endpoint via the '
                    . '"apex_toolbox_is_import_request" filter.',
                'args' => 1,
            ));
        }

        /**
         * Suppress WP Defender's audit hooks on importer requests.
         *
         * Defender builds its audit listeners on `after_setup_theme` and applies this filter once
         * per audit class, so returning an empty list means no listener is ever registered for
         * that request. Defender already skips audit listeners entirely under WP-Cron and WP-CLI,
         * so this only has to cover plain HTTP requests - which is exactly what WP All Import's
         * cron endpoints are (they are HTTP calls to /wp-load.php, not WP-Cron events).
         *
         * @param array|null $hooks
         * @return array
         */
        public function ignoreAuditHooksDuringImport($hooks)
        {
            if ($this->isImportRequest()) {
                return array();
            }

            return is_array($hooks)
                ? $hooks
                : array();
        }

        /**
         * Whether the current request is an importer request whose audit noise we suppress.
         *
         * Both branches below are deliberately gated on something the caller cannot simply invent.
         * The audit log is a security control, so a parameter anyone can append to any request
         * must not be able to switch it off - otherwise this becomes an audit-evasion path
         * (`?import_key=x` bolted onto some other auditable request).
         *
         * Sites whose importer endpoint is neither of these - a theme with its own
         * `?some_theme_process_import=1` trigger, say - declare it by returning true from the
         * `apex_toolbox_is_import_request` filter. A theme's functions.php loads before
         * `after_setup_theme` fires, so registering it there is early enough. Note that this makes
         * the site responsible for its own gating, since the filter overrides everything here.
         *
         * @return bool
         */
        protected function isImportRequest()
        {
            $isImport = $this->isWpAllImportCronRequest() || $this->isWpAllImportAdminRequest();

            /**
             * Filters whether the current request is an import run whose WP Defender audit
             * logging should be suppressed.
             *
             * @param bool $isImport Whether the toolbox already recognised this as an import.
             */
            return (bool) apply_filters('apex_toolbox_is_import_request', $isImport);
        }

        /**
         * WP All Import's cron endpoints: an HTTP call to /wp-load.php carrying the cron key.
         *
         * The key is the whole signal - we deliberately do not require a particular `action`,
         * because the endpoint accepts `processing`, `trigger`, `pipe`, `cancel` and `cleanup`
         * (see wp_loaded_99.php) and some importer requests carry none at all. Gating on the
         * action was the original bug.
         *
         * What we do require is that the key actually matches the one WP All Import generated,
         * exactly as WP All Import checks it before running the import. Read from $_GET, not
         * $_REQUEST, both because that is where WP All Import reads it and because $_REQUEST can
         * fold in cookies depending on the server's request_order.
         *
         * @return bool
         */
        protected function isWpAllImportCronRequest()
        {
            $importKey = $this->getQueryParam('import_key');
            if ($importKey === '') {
                return false;
            }

            $cronJobKey = $this->getWpAllImportCronJobKey();

            return $cronJobKey !== '' && hash_equals($cronJobKey, $importKey);
        }

        /**
         * WP All Import's admin screen while it is walking through an import.
         *
         * The `page`/`action` pair is as forgeable as anything else in the query string, so this
         * also requires the capability WP All Import gates its own admin screens on. Anyone who
         * passes that can already switch WP Defender's audit module off outright, so being able
         * to quieten it for one request tells an attacker nothing new.
         *
         * @return bool
         */
        protected function isWpAllImportAdminRequest()
        {
            if ($this->getQueryParam('page') !== self::WP_ALL_IMPORT_PAGE) {
                return false;
            }

            if (! in_array($this->getQueryParam('action'), self::adminImportActions(), true)) {
                return false;
            }

            return $this->currentUserCanManageImports();
        }

        /**
         * The cron key WP All Import generated for this site, or '' when it cannot be read.
         *
         * `getOption()` with no argument returns the whole options array; asking it for a single
         * option throws when the option is unknown, so the array form is the safe one. When WP All
         * Import is not installed there is no legitimate `import_key` on the site at all, and the
         * filter above is the way in for anything else.
         *
         * @return string
         */
        protected function getWpAllImportCronJobKey()
        {
            if (! class_exists('PMXI_Plugin') || ! method_exists('PMXI_Plugin', 'getInstance')) {
                return '';
            }

            $options = PMXI_Plugin::getInstance()->getOption();

            if (! is_array($options) || ! isset($options['cron_job_key']) || ! is_string($options['cron_job_key'])) {
                return '';
            }

            return $options['cron_job_key'];
        }

        /**
         * Whether the current user may drive WP All Import's admin screens.
         *
         * @return bool
         */
        protected function currentUserCanManageImports()
        {
            $capability = class_exists('PMXI_Plugin') && is_string(PMXI_Plugin::$capabilities)
                ? PMXI_Plugin::$capabilities
                : 'manage_options';

            return current_user_can($capability);
        }

        /**
         * Read a scalar query parameter as a trimmed string.
         *
         * Only ever used to recognise the shape of the request - nothing is stored or echoed -
         * so there is no nonce to verify here.
         *
         * @param string $key
         * @return string
         */
        private function getQueryParam($key)
        {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (! isset($_GET[$key]) || ! is_scalar($_GET[$key])) {
                return '';
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return trim(sanitize_text_field(wp_unslash((string) $_GET[$key])));
        }
    }
}
