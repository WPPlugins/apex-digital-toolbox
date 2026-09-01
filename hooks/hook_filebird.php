<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHookFileBird')) {
    /**
     * FileBird Pro integration.
     *
     * FileBird binds its licence to a domain. Helpers::isActivated() is purely local - the purchase
     * code and email come down with a cloned database, so the plugin considers itself activated -
     * but plugin_updater() sends the vendor an fb_token containing the code, the email AND
     * Helpers::getDomain(), which is get_site_url(). NinjaTeam then sees a code registered to one
     * domain arriving from another and asks the site to authenticate with Envato again, which is
     * why a clone cannot install FileBird updates however the firewall is configured. No allowlist
     * rule can change that: the vendor is judging a domain we are reporting truthfully.
     *
     * getDomain() ends in apply_filters('fbv_domain_for_activate', $url), so a site can tell
     * FileBird which domain to present. This hook does that, using the Production URL the toolbox
     * already stores - the one setting that says "the live site is over there".
     */
    class toolboxHookFileBird extends toolboxHookController
    {
        public const DOMAIN_FILTER = 'fbv_domain_for_activate';

        public function __construct($Toolbox)
        {
            parent::__construct($Toolbox);

            $this->setLabel('FileBird');
            // Deliberately NOT forced on. Everything else this plugin ships reports a clone
            // truthfully and lets the vendor decide; this asserts the clone's licence belongs to the
            // production domain, which is a different kind of claim and should be a decision rather
            // than a default - especially as this plugin is distributed publicly. Enabling it on the
            // production site is harmless (it is a no-op there, see filterLicenceDomain) and carries
            // to every clone with the database, which is how to switch it on across a fleet.
            $this->addHook(APEX_TOOLBOX_HOOK_ACTION, 'init', 'registerLicenceDomainFilter', array(
                'label' => 'Report the Production URL as FileBird\'s licence domain',
                'description' => 'FileBird Pro ties its licence to a domain and re-checks it on every '
                    . 'update, so a development or staging copy is asked to sign in to Envato again '
                    . 'and cannot install updates. With this on, a non-production copy tells FileBird '
                    . 'the Production URL instead of its own address, so the licence it inherited '
                    . 'keeps working. Requires the Production URL setting; does nothing on the '
                    . 'production site itself, or if the two addresses already match.',
            ));
        }

        /** Registered on init so the filter is in place before FileBird builds its update token. */
        public function registerLicenceDomainFilter(): void
        {
            add_filter(self::DOMAIN_FILTER, array($this, 'filterLicenceDomain'));
        }

        /**
         * Present the production domain to FileBird instead of this site's own.
         *
         * Returns the value untouched unless a Production URL is configured AND this site's domain
         * differs from it - so it is a no-op on production, and on any site that has not been told
         * where production is. FileBird's own getDomain() strips the scheme and keeps the host and
         * any path, so this matches that shape rather than inventing one.
         *
         * @param mixed $domain The domain FileBird was about to report.
         * @return mixed
         */
        public function filterLicenceDomain($domain)
        {
            $production = $this->stripScheme((string) $this->Toolbox->getOption('production_url'));
            if ($production === '') {
                return $domain;
            }

            $current = $this->stripScheme($this->getSiteUrl());
            if ($current === '' || $current === $production) {
                return $domain;
            }

            return $production;
        }

        /** Wrapped so tests can drive the site URL without touching global state. */
        protected function getSiteUrl(): string
        {
            return (string) get_site_url();
        }

        /** Scheme stripped and trailing slash trimmed, matching FileBird's own getDomain(). */
        private function stripScheme(string $url): string
        {
            $url = trim($url);
            if ($url === '') {
                return '';
            }

            return rtrim(preg_replace('#^https?://#i', '', $url), '/');
        }
    }
}
