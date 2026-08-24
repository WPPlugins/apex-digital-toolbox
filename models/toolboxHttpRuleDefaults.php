<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHttpRuleDefaults')) {
    /**
     * The curated outbound-firewall rules shipped with the toolbox.
     *
     * DATA ONLY - the matching engine is toolboxHttpRuleModel. This is deliberately its own class:
     * the vendor list is high-churn (it grows with every vendor added across the fleet) while the
     * engine is not, so keeping them apart means a vendor addition never touches matching logic and
     * neither file runs into the phpcs class/file length caps.
     *
     * The agency runs one plugin stack across ~90 sites, so the same vendor hosts are blocked on
     * every one of them. Shipping the rule means each site gets it on the next plugin update instead
     * of learning - and then losing - its own allowlist each time production is cloned down.
     *
     * Rule shape - every key but `host` is optional:
     *   host - exact host or leading-wildcard pattern, same syntax as the allowlist tiers
     *   methods - list of verbs, or toolboxHttpRuleModel::ALL_METHODS. Omitted means all methods.
     *   paths - list of path prefixes, matched on segment boundaries. Omitted means any path.
     *   require - map of parameter name to permitted values. Every named parameter must be present
     *             in the query string or body and hold one of the listed values. An allowlist of
     *             values, never a denylist, so an action the vendor adds later fails CLOSED.
     *   absent - list of parameter names that must NOT appear at all. For vendors whose only safe
     *             endpoint is the parameter-less one. Also not a denylist: a request carrying the
     *             parameter never matches, whatever value it holds.
     *   note - why this endpoint is safe from a clone. Shown on the firewall settings screen.
     *
     * Each vendor block below carries only what an editor needs AT THE POINT OF EDITING: the paths
     * deliberately left blocked, so nobody widens one by accident. The evidence behind each verdict
     * - source citations, endpoint inventories, the reasoning on the marginal calls - lives in
     * docs/firewall-rules.md, which is repo-only and never shipped. Add to both when adding a rule.
     */
    class toolboxHttpRuleDefaults
    {
        /**
         * Every shipped rule, one vendor per method below.
         *
         * Split per vendor rather than kept as one literal so that adding a vendor is a new
         * method plus one line here - which keeps this list readable and keeps any single
         * function clear of the phpcs length cap however long the vendor list grows.
         *
         * @return list<array<string,mixed>>
         */
        public static function rules(): array
        {
            return array_merge(
                self::elementorRules(),
                self::wpRocketRules(),
                self::checkoutWcRules(),
                self::wpDeveloperRules(),
                self::gravityWizRules(),
                self::gravityFormsRules(),
                self::crocoblockRules(),
                self::wpmuDevRules(),
                self::adminMenuEditorRules(),
                self::yithRules(),
                self::flycartRules(),
                self::usersInsightsRules(),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function elementorRules(): array
        {
            // Elementor / Elementor Pro. One helper pushes the licence calls AND the update check,
            // so only the path separates a version check from releasing production's seat.
            // NOT allowed: /api/v2/license/* (activate + deactivate are production-affecting;
            // validate is unconfirmed so it fails closed), /api/v1/feedback*, /api/v1/tracker/,
            // /api/v1/beta_tester/ (telemetry / account writes).
            return array(
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('GET'),
                    'paths' => array('/api/v2/info'),
                    'note' => 'Elementor core remote info: version, upgrade notice, dashboard feed. '
                        . 'Read-only.',
                ),
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('POST'),
                    'paths' => array('/api/v2/pro/info'),
                    'note' => 'Elementor Pro update check: returns new_version plus the package URL. '
                        . 'Read-only. Licence activate/deactivate live under /api/v2/license/ and '
                        . 'stay blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function wpRocketRules(): array
        {
            // WP Rocket. The two allowed endpoints send NO licence key, so a clone calling them is
            // indistinguishable from anyone asking "what is the current version". Every other
            // endpoint on this host carries the consumer key.
            // NOT allowed: /api/wp-rocket/activate-licence.php (licence activation),
            // /api/wp-rocket/plugin-settings.php (POSTs the consumer key),
            // /stat/1.0/wp-rocket/user.php (POSTs account email + key; a read, but unconfirmed
            // against a licence server), /stat/1.0/wp-rocket/pricing-2023.php (upsell feed),
            // /<consumer_key>/wp-rocket_<version>.zip (path starts with the key - no fixed prefix).
            return array(
                array(
                    'host' => 'api.wp-rocket.me',
                    'methods' => array('GET'),
                    'paths' => array('/check_update.php'),
                    'note' => 'WP Rocket update check: returns the stable/user version and package '
                        . 'URL. Read-only, sends no parameters at all. Licence activation lives '
                        . 'under /api/wp-rocket/ and stays blocked.',
                ),
                array(
                    'host' => 'api.wp-rocket.me',
                    'methods' => array('GET'),
                    'paths' => array('/plugin_information.php'),
                    'note' => 'WP Rocket plugin-information feed behind the Plugins > View details '
                        . 'modal. Read-only, sends no parameters.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function checkoutWcRules(): array
        {
            // CheckoutWC (checkout-for-woocommerce). The update check AND licence
            // activate/deactivate are all a request to '/' on this host - same path, and
            // activate/deactivate share the verb with the allowed licence read. ONLY the
            // edd_action parameter separates a status check from releasing production's seat,
            // so `require`/`absent` do all the work: never add a path-only rule to this host.
            // NOT allowed: edd_action=activate_license (consumes a seat, and the plugin's
            // auto_activate_license() fires it unattended), edd_action=deactivate_license
            // (releases production's seat), edd_action=package_download (premium zip, URL
            // carries the licence key).
            // Host is wildcarded because CFW_UPDATE_URL is hard-coded per plugin version and
            // has shipped both with and without the www; edd_action gates every call either way.
            return array(
                array(
                    'host' => '*.checkoutwc.com',
                    'methods' => array('GET'),
                    'require' => array('edd_action' => array('check_license')),
                    'note' => 'CheckoutWC licence status read: returns the key state and seat '
                        . 'limit. Read-only. activate_license and deactivate_license are the same '
                        . 'host, path and method - only edd_action differs - and stay blocked.',
                ),
                array(
                    'host' => '*.checkoutwc.com',
                    'methods' => array('POST'),
                    'require' => array('edd_action' => array('get_version')),
                    'note' => 'CheckoutWC update check: returns new_version plus the package URL. '
                        . 'Read-only, and the only POST the vendor client makes.',
                ),
                array(
                    'host' => '*.checkoutwc.com',
                    'methods' => array('GET'),
                    'absent' => array('edd_action'),
                    'note' => 'CheckoutWC reachability probe: a parameter-less GET the EDD updater '
                        . 'makes before it will check for updates at all - if it fails, the update '
                        . 'check never runs and the clone never sees a new version. Allowed only '
                        . 'while edd_action is absent, so it can never match a licence call.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function wpDeveloperRules(): array
        {
            // WPDeveloper (Essential Addons for Elementor Pro, and the rest of the WPDeveloper
            // stack - they share one licensing SDK). Per-action endpoint routing exists in the
            // SDK but is disabled (`if ( false && ... )`), so EVERY action - update check,
            // licence status, activate, deactivate - is a POST to the bare host. Only the
            // edd_action parameter separates them, so `require` does all the work: never add a
            // path-only rule to this host.
            // NOT allowed: edd_action=activate_license / activate_license_by_otp (consume a
            // seat), edd_action=deactivate_license (releases production's seat),
            // edd_action=resend_otp_for_license (account write),
            // edd_action=package_download (premium zip, URL carries the licence key).
            return array(
                array(
                    'host' => 'api.wpdeveloper.com',
                    'methods' => array('POST'),
                    'require' => array(
                        'edd_action' => array('get_version', 'check_license'),
                    ),
                    'note' => 'WPDeveloper update check (get_version, returns new_version plus the '
                        . 'package URL) and licence status read (check_license). Both read-only. '
                        . 'activate_license and deactivate_license are the same host, path and '
                        . 'method - only edd_action differs - and stay blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function gravityWizRules(): array
        {
            // Gravity Wiz (Spellbook, and the Gravity Perks / Connect / Shop products it
            // licenses). One private GWAPI::request() helper sends the product catalogue AND
            // every licence call to the same /gwapi/ path, so only edd_action separates them -
            // the read set happens to be the GET half. `require` does the work: never add a
            // path-only rule to this host.
            // NOT allowed: edd_action=check_license (POST; a status read on paper, but
            // process_license_data() auto-fires activate_license whenever the response is
            // site_inactive - which is exactly what a clone's URL gets back),
            // edd_action=activate_license and register_product (consume a seat),
            // deactivate_license and deregister_product (release production's seat), and the
            // premium package (server-supplied URL on this host, carries the licence hash).
            // in.gravitywiz.com (telemetry + Spellbook registration) is a separate host and is
            // not covered by this rule.
            return array(
                array(
                    'host' => 'gravitywiz.com',
                    'methods' => array('GET'),
                    'paths' => array('/gwapi'),
                    'require' => array(
                        'edd_action' => array(
                            'get_products',
                            'gw_get_product',
                            'spellbook_announcements',
                            'get_api_status',
                        ),
                    ),
                    'note' => 'Gravity Wiz product catalogue and changelog reads: get_products '
                        . 'returns new_version plus the package URL for every perk and is what '
                        . 'raises the update badge, with no licence check gating it. All four '
                        . 'actions are read-only GETs. Every licence call - including '
                        . 'check_license, which can trigger an unattended activation - is a POST '
                        . 'to this same path and stays blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function gravityFormsRules(): array
        {
            // Gravity Forms. Two APIs on one host. The modern REST licence API under
            // /wp-json/gravityapi/v1/ multiplexes the licence status read and site registration
            // through one helper (Gravity_Api::request), so only the path separates a status
            // check from registering this clone against production's licence. The legacy
            // "Gravity Manager" under /wp-content/plugins/gravitymanager/ is one PHP file per
            // concern, except api.php which multiplexes every lookup through an `op` parameter.
            // NOT allowed: POST /wp-json/gravityapi/v1/sites (registers the calling domain -
            // consumes a licence seat), PUT /wp-json/gravityapi/v1/sites/<site_key> (re-points
            // or unlinks the PRODUCTION site's licence), POST
            // /wp-json/gravityapi/v1/emails/installation/add-to-list (adds the admin's address
            // to the vendor's mailing list), and every api.php `op` outside the five reads below.
            return array(
                array(
                    'host' => 'gravityapi.com',
                    'methods' => array('POST'),
                    // The licence key hash is a path SEGMENT (/licenses/<md5>/check), so no fixed
                    // prefix can pin the trailing /check. The client calls nothing else under
                    // /licenses/, and every licence WRITE lives under /sites - a different prefix.
                    'paths' => array('/wp-json/gravityapi/v1/licenses'),
                    'note' => 'Gravity Forms licence status read: returns the key state, seat count '
                        . 'and expiry for the licence panel. Read-only, unauthenticated, and it '
                        . 'never escalates to an activation. Site registration lives under '
                        . '/wp-json/gravityapi/v1/sites and stays blocked.',
                ),
                array(
                    'host' => 'gravityapi.com',
                    'methods' => array('POST'),
                    'paths' => array(
                        '/wp-content/plugins/gravitymanager/version.php',
                        '/wp-content/plugins/gravitymanager/changelog.php',
                        '/wp-content/plugins/gravitymanager/message.php',
                    ),
                    'note' => 'Gravity Forms update check: version.php returns version_latest plus '
                        . 'the package URL for core and every add-on, and is what raises the update '
                        . 'badge. changelog.php and message.php are the changelog and admin-notice '
                        . 'feeds. All read-only.',
                ),
                array(
                    'host' => 'gravityapi.com',
                    'methods' => array('POST'),
                    'paths' => array('/wp-content/plugins/gravitymanager/api.php'),
                    'require' => array(
                        'op' => array(
                            'get_plugins',
                            'get_plugin',
                            'plugin_browser',
                            'get_key',
                            'upgrade_message',
                        ),
                    ),
                    'note' => 'Gravity Forms add-on catalogue and licence-key lookups: the add-on '
                        . 'list and version data behind Forms > Add-Ons and Plugins > View details. '
                        . 'All five are read-only. api.php is a multiplexer, so `op` is pinned and '
                        . 'an action the vendor adds later fails closed.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function crocoblockRules(): array
        {
            // Crocoblock / JetPlugins (JetEngine, JetWooBuilder, JetTricks, JetWooProductGallery
            // and the rest - every Jet plugin bundles the same jet-dashboard framework and its
            // licensing client). Every call is a request to the BARE HOST with no path at all,
            // so neither path nor verb separates the update check from releasing production's
            // licence: activate_license and deactivate_license are GETs to '/' exactly like the
            // allowed catalogue read. `require` does all the work: never add a path-only rule
            // to this host.
            // NOT allowed: action=activate_license (consumes a seat and registers site_url),
            // action=deactivate_license (releases production's seat),
            // action=plugin_activate_action (POSTs the licence key and site_url to the vendor
            // account every time a Jet plugin is activated),
            // action=get_plugin_update (premium zip, URL carries the licence key).
            return array(
                array(
                    'host' => 'api.crocoblock.com',
                    'methods' => array('GET'),
                    'require' => array('action' => array('get_plugins_data')),
                    'note' => 'Crocoblock plugin catalogue: returns the latest version of every Jet '
                        . 'plugin and is what raises the update badge. Read-only, and the only call '
                        . 'on this host that sends no licence key. activate_license and '
                        . 'deactivate_license are the same host, path and method - only `action` '
                        . 'differs - and stay blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function wpmuDevRules(): array
        {
            // WPMU DEV Dashboard (wpmudev-updates). A single helper posts the public update
            // catalogue AND every Hub write, so only the path separates a version check from
            // handing the vendor account this clone's URLs.
            // NOT allowed: /api/dashboard/v2/hub-sync (registers the calling domain against the
            // membership and POSTs admin_url, home_url, sso_status and encrypted auth cookies),
            // /api/dashboard/v2/grant-access + /revoke-access (open and close WPMU DEV support
            // staff login on the account), /api/dashboard/v2/user-info (account read carrying
            // the API key), /api/dashboard/v2/download/ + /download-dashboard (premium
            // packages), /api/dashboard/v2/site-authenticate, /google-auth, /sso-hub (login).
            return array(
                array(
                    'host' => 'wpmudev.com',
                    'methods' => array('GET'),
                    'paths' => array('/api/dashboard/v2/projects'),
                    'note' => 'WPMU DEV project catalogue: the latest version of every WPMU DEV '
                        . 'plugin and theme, which is what raises the update badge. Read-only, '
                        . 'needs no API key and sends no parameters at all. The Hub sync at '
                        . '/api/dashboard/v2/hub-sync stays blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function adminMenuEditorRules(): array
        {
            // Admin Menu Editor Pro. Two APIs on one host, and the update check is a GET to the
            // BARE HOST with no path - '/' as a prefix means "any path", so a path-only rule
            // here would allow the whole host. `require` on get_metadata_for is what pins the
            // rule to the one metadata endpoint. The licensing API under /licensing_api/ is the
            // vendor's own WSLM server (its server class ships inside the plugin), and it
            // enforces the verb split itself: the licence read is GET-only, while license_site
            // and unlicense_site are POST-only.
            // NOT allowed: POST /licensing_api/products/<slug>/licenses/<key>/license_site
            // (consumes a seat for the clone's URL), POST
            // /licensing_api/products/<slug>/licenses/{<key>|bytoken/<token>}/unlicense_site
            // (releases production's seat - the clone carries production's site token in the
            // cloned DB), GET /licensing_api/products/<slug>/licenses/<key> (a read, but
            // redundant: the allowed metadata response already carries the licence object and
            // the client saves it), and the premium package (server-supplied URL, the client
            // appends the licence key and token to it).
            return array(
                array(
                    'host' => 'adminmenueditor.com',
                    'methods' => array('GET'),
                    'require' => array('get_metadata_for' => array('admin-menu-editor-pro')),
                    'note' => 'Admin Menu Editor Pro update check: returns the new version plus the '
                        . 'package URL, and is what raises the update badge. Read-only, and its '
                        . 'response also refreshes the cached licence, so the separate licence read '
                        . 'is not needed. Seat activation and deactivation live under '
                        . '/licensing_api/ on this same host and stay blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function yithRules(): array
        {
            // YITH, via the yith-plugin-upgrade framework bundled inside every YITH premium plugin.
            // This host is EXACT and must never become *.yithemes.com: the licence API lives on
            // SIBLING hosts under the same apex, so a wildcard here would allow seat activation and
            // deactivation outright. update.yithemes.com itself serves only the XML manifest.
            // Note the download window does not reach YITH: the framework leaves `package` empty and
            // fetches the zip from licence.yithemes.com itself, so a clone sees the update but
            // cannot install it.
            // NOT allowed: licence.yithemes.com/api/{activation,deactivation} (seat writes; the
            // clone carries production's licence key), licence.yithemes.com/api/check,
            // licence.yithemes.com/api/{download,pls/download} (the premium package), and the
            // casper.yithemes.com/wc-api/{software-api,download-api}/ fallbacks for all of those.
            return array(
                array(
                    'host' => 'update.yithemes.com',
                    'methods' => array('GET'),
                    'paths' => array('/plugin-xml.php'),
                    'note' => 'YITH update check: an XML manifest carrying the latest version, '
                        . 'changelog and tested-up-to, and what raises the update badge. Read-only. '
                        . 'Licence activation and deactivation are on licence.yithemes.com and '
                        . 'casper.yithemes.com, which have no rule and stay blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function flycartRules(): array
        {
            // Flycart (Discount Rules Pro for WooCommerce, and the rest of the Flycart premium
            // stack - they share one UpdateHandler). Every call is a GET to the BARE HOST with no
            // path, built by one getUpdateURL($type) helper, so neither path nor verb separates
            // the update check from the licence call: '/' as a prefix would allow the whole host.
            // `require` on wpaction does all the work: never add a path-only rule to this host.
            // Host is EXACT and must never become *.flycart.org - the vendor's store and account
            // area are on this same host, and www./docs.flycart.org are separate sites.
            // NOT allowed: wpaction=licensecheck (the only other action the client knows; it
            // POSTs nothing but sends the download ID against a store account, is fired only when
            // an admin re-saves the key by hand, and is not needed for updates - runUpdater()
            // gates on the LOCALLY stored licence status, which a clone inherits), and the
            // premium package (server-supplied download_url from the manifest).
            return array(
                array(
                    'host' => 'my.flycart.org',
                    'methods' => array('GET'),
                    'require' => array('wpaction' => array('updatecheck')),
                    'note' => 'Flycart update check: the plugin-update-checker metadata request, '
                        . 'returning the new version plus the package URL, and what raises the '
                        . 'update badge. Read-only. The licence call is the same host, path and '
                        . 'method - only wpaction differs - and stays blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function usersInsightsRules(): array
        {
            // Users Insights (users-insights, and its per-module licences). A PEXETO fork of the
            // stock EDD updater. Every call is to the BARE HOST at path '/' - the update check, the
            // reachability probe, the licence calls and the geolocation lookup - so path separates
            // nothing. Verb splits them only partly: the updater POSTs, everything else GETs.
            // Host is EXACT and must never become *.usersinsights.com - the vendor's whole site,
            // including /account/, is this one host; no subdomain is referenced anywhere.
            // NOT allowed: usinr_action=activate_license / deactivate_license (consume and release
            // PRODUCTION's seat), usinr_action=check_license (an account read the update path does
            // not need), usinr_geoip=1 (a per-visitor geolocation lookup metered against
            // production's licence, fired unattended by a front-end beacon), and the premium
            // package (server-supplied download_link from the manifest).
            return array(
                array(
                    'host' => 'usersinsights.com',
                    'methods' => array('POST'),
                    'require' => array('usinr_action' => array('get_version')),
                    'note' => 'Users Insights update check: returns new_version plus the package '
                        . 'URL, and what raises the update badge. Also serves the plugin-information '
                        . 'and changelog modals. Read-only, and the only POST the vendor client '
                        . 'makes - every licence call is a GET and stays blocked.',
                ),
                array(
                    'host' => 'usersinsights.com',
                    'methods' => array('GET'),
                    'absent' => array('usinr_action', 'usinr_geoip', 'license'),
                    'note' => 'Users Insights reachability probe: a parameter-less GET the EDD '
                        . 'updater makes before it will check for updates at all - if it fails, the '
                        . 'update check never runs and the clone never sees a new version. Matched '
                        . 'only while the vendor\'s two GET dispatch keys and the licence key are '
                        . 'all absent, so it can never admit a licence or geolocation call.',
                ),
            );
        }
    }
}
