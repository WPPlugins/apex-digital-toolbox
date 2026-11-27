<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookWpmuDev')) {
    /**
     * WPMU DEV Dashboard integration.
     *
     * The outbound firewall ships a rule allowing POST /api/dashboard/v2/hub-sync, because that call
     * IS the Dashboard login and without it a copy cannot connect, install or update anything. The
     * Hub keys its site records on the `domain` the sync reports, so that rule is only safe while a
     * copy reports ITSELF. This hook is what makes that true.
     *
     * It has to exist because a copy does not reliably report itself. The agency's DDEV config
     * deliberately tells WP-CLI it is production so that plugins can still be updated:
     *
     *     if ( ! defined( 'WP_CLI' ) ) {
     *         define( 'WP_SITEURL', WP_HOME . '/' ); // https://gnomes.ddev.site
     *     } else {
     *         define( 'WP_SITEURL', 'https://www.gnomes.co.nz' );
     *     }
     *
     * network_site_url() follows WP_SITEURL, and hub_sync() sends it as `domain`. So under `wp
     * anything` - measured on a real copy - the Dashboard reports the PRODUCTION url, and the sync
     * would overwrite production's Hub record with this copy's plugin state, update state and
     * encrypted auth cookies. In a browser the same site reports itself correctly. Nothing about
     * that is visible from the firewall, which is a URL/method/path filter and cannot see who the
     * request claims to be.
     *
     * WPMUDEV_HUB_SITE_URL is the vendor's own answer: network_site_url(), network_home_url() and
     * network_admin_url() all defer to it (class-wpmudev-dashboard-api.php:211,226,250), so one
     * constant pins the whole identity. We set it to the copy's own address.
     *
     * Same mechanism as toolboxHookFileBird - override what a vendor is told, only on a
     * non-production copy, no-op on production - pointed the other way. FileBird reports the
     * PRODUCTION url because its licence check is a READ and the licence belongs to that domain.
     * This endpoint WRITES, keyed on the value reported, so reporting production here would corrupt
     * production's record by design rather than let a copy read past a domain check.
     */
    class toolboxHookWpmuDev extends toolboxHookController
    {
        public const HUB_SITE_URL_CONSTANT = 'WPMUDEV_HUB_SITE_URL';

        public function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('WPMU DEV');
            // FORCED ON, unlike toolboxHookFileBird. That hook asserts a claim (this copy's licence
            // belongs to production) and is rightly a decision. This one only makes a copy describe
            // itself accurately, and it is the guard on a rule that ships by default - shipping the
            // rule without it would leave every copy able to write to production's Hub record. It is
            // a no-op on production. Priority 1 so the constant is in place before anything can
            // sync; the Dashboard itself only reads it at call time, so this is insurance.
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'plugins_loaded', 'pinHubSiteUrl', array(
                'label' => 'Report This Copy\'s Own Address To The WPMU DEV Hub',
                'description' => 'A development or staging copy tells the WPMU DEV Hub its own '
                    . 'address rather than the live site\'s, so connecting the Dashboard on a copy '
                    . 'adds that copy to the Hub and leaves the live site\'s entry alone. Needed '
                    . 'because a copy reports the live address under WP-CLI on the standard DDEV '
                    . 'configuration. Does nothing on the production site, or where the site has '
                    . 'already defined WPMUDEV_HUB_SITE_URL itself.',
                'force' => true,
                'priority' => 1,
            ));
        }

        /**
         * Thin glue: the side effect only. The decision is getHubSiteUrl(), which is pure and is
         * where the tests live - define() cannot be undone, so it must not hold any logic.
         */
        public function pinHubSiteUrl(): void
        {
            $url = $this->getHubSiteUrl();
            if ($url === null) {
                return;
            }

            define(self::HUB_SITE_URL_CONSTANT, $url);
        }

        /**
         * The address this copy should report to the Hub, or null to leave the Dashboard alone.
         *
         * Null - do nothing - in every case where we are not certain we are improving on what the
         * Dashboard would report by itself:
         *   - the site (or another plugin) already defined the constant: that decision wins;
         *   - the Dashboard is not installed: nothing would read it, so do not add a global;
         *   - this is production: the Dashboard is already reporting the right thing;
         *   - the address is unusable: not something to hand the Hub.
         *
         * Deliberately NOT cross-checked against the Production URL setting. That was the first
         * shape of this method and it was wrong twice over. Measured on `gnomes`, a real copy, that
         * option holds `https://gnomes.ddev.site` - the COPY's own address, saved by someone working
         * on the clone - so the check refused to pin on exactly the site that needed it, and the
         * guard failed OPEN. It was also never buying anything: pinning network_home_url() cannot be
         * worse than not pinning, because without it the Dashboard reports network_site_url(), and a
         * site whose WP_HOME is production's address has a WP_SITEURL that is too.
         */
        public function getHubSiteUrl(): ?string
        {
            if ($this->isConstantDefined() || ! $this->isDashboardPresent()) {
                return null;
            }
            if (! $this->isNonProductionCopy()) {
                return null;
            }

            $url = rtrim(trim($this->getCopyUrl()), '/');
            if ($url === '' || ! preg_match('!^https?://!i', $url)) {
                return null;
            }

            // WP_HOME is not part of the WP-CLI split above, so this is the copy's real address on
            // DDEV, on a *.kinsta.cloud clone and on a configured staging site alike.
            return $url;
        }

        /** Wrapped so a test can exercise both branches without define()ing anything. */
        protected function isConstantDefined(): bool
        {
            return defined(self::HUB_SITE_URL_CONSTANT);
        }

        /** Wrapped for tests; the Dashboard self-instantiates before plugins_loaded. */
        protected function isDashboardPresent(): bool
        {
            return class_exists('WPMUDEV_Dashboard');
        }

        /**
         * The copy's own address. network_home_url() follows WP_HOME, which the DDEV config does
         * NOT switch per SAPI - that is exactly why it is the honest one here.
         */
        protected function getCopyUrl(): string
        {
            return (string) network_home_url();
        }

        /**
         * Delegated to the firewall rather than re-derived, so "is this a copy" has one definition.
         * Constructing the hook only builds the object; it registers nothing by itself.
         */
        protected function isNonProductionCopy(): bool
        {
            if (! class_exists('toolboxHookOutboundFirewall')) {
                return false;
            }
            $firewall = new toolboxHookOutboundFirewall($this->Toolbox);

            return (bool) $firewall->isNonProductionEnvironment()['block'];
        }
    }
}
