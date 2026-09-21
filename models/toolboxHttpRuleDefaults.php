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
     *   respond - answer the request locally instead of letting it out OR blocking it. `true` gives
     *             an empty 200. Only for endpoints where the client is known to ignore the response
     *             body AND blocking it breaks the site - see toolboxHttpRuleModel::normaliseRespond.
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
                self::fileBirdRules(),
                self::apexRegistryRules(),
                self::wpAllImportRules(),
            );
        }

        /**
         * The Apex Digital Toolbox private package registry.
         *
         * The one FIRST-PARTY host in this file: it is the agency's own registry, serving the
         * private plugins a site is licensed for. That changes the analysis in one direction only -
         * we can state what the endpoint does rather than infer it - and in no other: the rule is
         * still written at the narrowest shape, because a clone reaching further than it needs to
         * is no more acceptable on our own server than on a vendor's.
         *
         * The site-facing API is GET-only and read-only by design, so unlike every vendor block
         * above there is no dangerous sibling endpoint to fence off. The manifest returns the
         * packages this site is entitled to; the site sends nothing and can change nothing. The
         * per-site token travels in an Authorization header, which the firewall never reads or
         * records, so a blocked call leaks nothing into the discovery log.
         *
         * Host is EXACT and must never become *.limelightdigital.co.nz: Geoff
         * (ai.limelightdigital.co.nz) and other agency apps are sibling subdomains of the same
         * apex, and a wildcard here would quietly allow all of them.
         *
         * NOT allowed, deliberately: the signed package download at /api/packages/download/*. It
         * has no rule at all and needs none - the update-package download window already permits
         * exactly that fetch while WordPress's own upgrader is resolving it, and the window is
         * strictly narrower than any rule could be (the armed URL, streaming into the same
         * tmpfile, within a redirect budget WordPress counts down). So a private package can only
         * ever be pulled during a real upgrade, never from arbitrary code on a clone. A rule would
         * only widen that.
         *
         * @return list<array<string,mixed>>
         */
        private static function apexRegistryRules(): array
        {
            return array(
                array(
                    'host' => 'cue.limelightdigital.co.nz',
                    'methods' => array('GET'),
                    'paths' => array('/api/packages/manifest'),
                    'note' => 'Apex Toolbox private package registry: the packages this site is '
                        . 'licensed for, their latest versions and a signed, expiring download '
                        . 'URL. Read-only and GET-only - a copy cannot grant, change or release '
                        . 'anything. The download URL it returns has no rule of its own and is '
                        . 'reachable only while WordPress is installing an update.',
                ),
            );
        }

        /**
         * WP All Import Pro and its add-ons.
         *
         * Host is EXACT, and must stay that way. The vendor splits across sibling subdomains:
         * update.wpallimport.com carries updates AND licensing, www.wpallimport.com is the EDD
         * store and checkout, scheduling.wpallimport.com is the paid cron service (over plain
         * http). A *.wpallimport.com wildcard would open the store as well.
         *
         * The two paths do different jobs and are separated by path alone, which is unusually
         * tidy for an EDD vendor: /check_version/ is only ever the updater (pro and both add-ons
         * build it the same way), while every licence operation is a GET to /check_license
         * differing ONLY by the edd_action value. So the path keeps the update check clear, and
         * `require` is what stands between activation and deactivation - a clone carries
         * production's licence key, so a deactivate from here would release the LIVE site's seat.
         *
         * activate IS allowed and deactivate is not, on the usual terms: activate registers the
         * calling site and leaves production alone, and it only runs from the licence screen
         * behind check_admin_referer('edit-license') plus an explicit Activate button
         * (controllers/admin/settings.php:70-87). The status read cannot cascade into it -
         * LicenseActivator::checkLicense() stores the returned status and nothing more, unlike
         * Gravity Wiz.
         *
         * The update rule pins edd_action too, which its path alone would not require. Whether
         * the vendor's /check_version/ route would honour a licence action is not visible from
         * here, and a rule that leaves the question open answers it wrongly the day they
         * consolidate their routing. All three updaters hard-code get_version.
         *
         * NOT allowed: edd_action=deactivate_license on /check_license (releases production's
         * seat), edd_action=package_download (no rule - the update-package download window
         * covers it, and only during a real upgrade), and every path on the store host.
         *
         * @return list<array<string,mixed>>
         */
        private static function wpAllImportRules(): array
        {
            return array(
                array(
                    'host' => 'update.wpallimport.com',
                    'methods' => array('GET'),
                    'paths' => array('/check_version/'),
                    'require' => array('edd_action' => array('get_version')),
                    'note' => 'WP All Import update check, used by the Pro plugin and every '
                        . 'add-on. Read-only: it returns the new version and the package URL. '
                        . 'Licensing lives on /check_license and is ruled separately.',
                ),
                array(
                    'host' => 'update.wpallimport.com',
                    'methods' => array('GET'),
                    'paths' => array('/check_license'),
                    'require' => array('edd_action' => array('check_license', 'activate_license')),
                    'note' => 'WP All Import licence status, and manual activation from the '
                        . 'licence screen. Activation registers THIS copy and leaves the live '
                        . 'site alone; deactivation is the same path and method and differs only '
                        . 'by this parameter, so it stays blocked.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function elementorRules(): array
        {
            // Elementor / Elementor Pro. One helper pushes the licence calls AND the update check,
            // so only the path separates a version check from releasing production's seat -
            // activate, deactivate and validate are sibling segments under /api/v2/license/, and
            // prefix matching is segment-bounded, so a rule on one cannot reach the others.
            // activate IS allowed and deactivate is not, deliberately: they are not symmetric.
            // activate adds THIS site to the licence, costing a seat and leaving production alone;
            // deactivate removes a site, and a clone carries production's credentials, so it can
            // remove PRODUCTION's. Activation also only ever runs from a human clicking through the
            // connect flow - every unattended write on this fleet stays blocked.
            // NOT allowed: /api/v2/license/deactivate (can release production's seat),
            // /api/v1/feedback*, /api/v1/tracker/, /api/v1/beta_tester/ (telemetry / account
            // writes). /api/connect/v1/activate/disconnect is neither allowed nor blocked - it is
            // answered locally, see its rule below.
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
                        . 'Read-only. Licence deactivation lives under /api/v2/license/ and stays '
                        . 'blocked.',
                ),
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('POST'),
                    'paths' => array('/api/connect/v1/activate/disconnect'),
                    // ANSWERED LOCALLY, not allowed and not blocked. Nothing leaves the site.
                    // Blocking is not viable: the connect client calls wp_die() on any WP_Error
                    // (base-app.php:469), so a block kills the request before disconnect() reaches
                    // its own delete(), and Switch License dies before doing anything at all.
                    // Allowing is worse: the request is signed with PRODUCTION's access_token,
                    // client_id and site_key from the cloned database, so the vendor could drop
                    // production's connection. The client ignores the response body - its only
                    // reaction is to clear local data, which is what a real success would cause.
                    'respond' => true,
                    'note' => 'Elementor connect disconnect, answered locally: the site clears its '
                        . 'own connection data and no request is sent. Needed because the connect '
                        . 'client treats a blocked call as fatal, which left Switch License and '
                        . 'Deactivate unusable on a clone. Not allowed out, because it is signed '
                        . 'with the production site\'s credentials and would ask Elementor to drop '
                        . 'THAT connection.',
                ),
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('POST'),
                    // Segment-bounded, so this cannot reach the sibling `disconnect` action.
                    // The three server-side calls a manual activation makes, in order:
                    // get_client_id registers the OAuth client BEFORE the browser redirect (and
                    // wp_die()s if it fails), get_token exchanges the returned code, and
                    // get_connected_license fetches the key to activate with.
                    'paths' => array(
                        '/api/connect/v1/activate/get_client_id',
                        '/api/connect/v1/activate/get_token',
                        '/api/connect/v1/activate/get_connected_license',
                    ),
                    'note' => 'Elementor Pro connect token exchange: completes a manual licence '
                        . 'activation after the browser has been redirected to Elementor and back. '
                        . 'Authenticates with the one-time code Elementor just issued to that '
                        . 'browser session, not with the cloned credentials. The `disconnect` '
                        . 'action on this same app is never sent either - it is answered locally, '
                        . 'because it signs with production\'s access_token and site_key and would '
                        . 'ask Elementor to drop that connection.',
                ),
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('POST'),
                    // activate and deactivate are sibling segments, not children of this one, so
                    // the segment-bounded prefix reaches validate alone.
                    'paths' => array('/api/v2/license/validate'),
                    'note' => 'Elementor Pro licence status read: returns the key state and seat '
                        . 'counts, cached 12 hours. Not the seat operation - deactivate is a '
                        . 'separate endpoint and stays blocked. Allowed because '
                        . 'is_license_active() gates Base_Widget_Trait::is_editable(), so without '
                        . 'it every Pro widget is uneditable on a clone and the editor cannot be '
                        . 'used to check an update. Reports the clone URL to the vendor - see '
                        . 'docs/firewall-rules.md.',
                ),
                array(
                    'host' => 'my.elementor.com',
                    'methods' => array('POST'),
                    // Segment-bounded: 'deactivate' is a different segment, not a child of this
                    // one, so this rule cannot reach it.
                    'paths' => array('/api/v2/license/activate'),
                    'note' => 'Elementor Pro licence activation. This is a WRITE and is allowed on '
                        . 'purpose: it adds THIS site to the licence, which costs a seat but leaves '
                        . 'the production site untouched, and it only ever runs from a human '
                        . 'clicking through the connect flow. Deactivation is the dangerous half - '
                        . 'a clone carries production\'s credentials, so it could release '
                        . 'production\'s seat - and stays blocked. On a seat-limited licence, '
                        . 'remove this rule with the apex_toolbox_http_default_rules filter.',
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
            // The activation actions are allowed on the same terms as Elementor's and Admin Menu
            // Editor's: they ADD this site and leave production alone, and they only run from the
            // wp_ajax license/activate, license/submit-otp and license/resend-otp handlers - a
            // human on the licence screen, never cron or plugins_loaded. The OTP pair is included
            // because the SERVER decides whether it is needed: activate() returns early when the
            // response is `required_otp`, and the flow then continues through submit-otp.
            // NOT allowed: edd_action=deactivate_license (releases production's seat - the clone
            // carries production's key), edd_action=package_download (premium zip, URL carries the
            // licence key; the download window handles the real fetch).
            return array(
                array(
                    'host' => 'api.wpdeveloper.com',
                    'methods' => array('POST'),
                    'require' => array(
                        'edd_action' => array(
                            'get_version',
                            'check_license',
                            'activate_license',
                            'activate_license_by_otp',
                            'resend_otp_for_license',
                        ),
                    ),
                    'note' => 'WPDeveloper update check (get_version), licence status read '
                        . '(check_license), and manual activation (activate_license, plus the OTP '
                        . 'pair the server may demand instead). Activation is a WRITE and spends a '
                        . 'licence seat: it is allowed because it adds THIS site and leaves the '
                        . 'production site alone, and only ever runs from a human on the licence '
                        . 'screen. deactivate_license releases a seat and the clone carries '
                        . 'production\'s key, so it stays blocked - every action here is the same '
                        . 'host, path and method, and only edd_action separates them. On a '
                        . 'seat-limited licence, drop the activation values with the '
                        . 'apex_toolbox_http_default_rules filter.',
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
            // check_license IS allowed, and it is the one rule in this file that knowingly permits
            // an UNATTENDED seat write. It is a status read on paper, but process_license_data()
            // auto-fires activate_license whenever the response is site_inactive - exactly what a
            // clone's URL gets back - and it runs from the update-check cron, so no human is
            // involved. It is allowed anyway because it is the only thing that backfills the
            // licence ID the package URL needs: without it a clone sees updates it cannot install,
            // and nothing appears in the discovery log because the failure happens at the vendor.
            // This is acceptable only on an unlimited-site licence - see docs/firewall-rules.md.
            // NOT allowed: edd_action=register_product, deactivate_license and deregister_product
            // (release or reassign production's seat), and the premium package (server-supplied URL
            // on this host, carries the licence hash). activate_license IS allowed - see the POST
            // rule below, where it travels with check_license because one without the other leaves
            // the site reporting itself inactive.
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
                    'note' => 'Gravity Wiz catalogue and changelog reads. get_products returns '
                        . 'new_version and the package URL for every perk and is what raises the '
                        . 'update badge. All four are GETs. Every licence call is a POST to this '
                        . 'same path, so the verb separates the two sets and edd_action separates '
                        . 'the calls within each.',
                ),
                array(
                    'host' => 'gravitywiz.com',
                    'methods' => array('POST'),
                    'paths' => array('/gwapi'),
                    // check_license is a POST - GWAPI::request() is called with method => POST and
                    // puts edd_action in the BODY (class-gwapi.php:975). It needs its own rule for
                    // that reason: the read set above is the GET half and can never match it.
                    // Every licence WRITE is also a POST to this same path, so `require` is the
                    // only thing separating them.
                    'require' => array('edd_action' => array('check_license', 'activate_license')),
                    'note' => 'Gravity Wiz licence status check and the activation it triggers. '
                        . 'check_license backfills the licence ID the package URL is built from, '
                        . 'and the vendor answers site_inactive for a clone, at which point '
                        . 'process_license_data() fires activate_license inline. BOTH are needed: '
                        . 'with only the check allowed the site never registers, the status stays '
                        . 'site_inactive and the download returns 403. NOTE this makes the pair an '
                        . 'UNATTENDED seat write - it runs from the update-check cron, so a clone '
                        . 'registers itself with nobody clicking. Allowed only because this fleet '
                        . 'has an unlimited-site licence; see docs/firewall-rules.md. '
                        . 'deactivate_license, register_product and deregister_product are the same '
                        . 'host, path and method and stay blocked',
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
                    'require' => array('action' => array('get_plugins_data', 'activate_license')),
                    'note' => 'Crocoblock plugin catalogue read (get_plugins_data), which is what '
                        . 'raises the update badge, plus manual activation (activate_license). '
                        . 'Activation is a WRITE and spends a seat: allowed because it adds THIS '
                        . 'site and leaves production alone. deactivate_license releases a seat and '
                        . 'the clone carries production\'s key, so it stays blocked - every call '
                        . 'here is the same host, path and verb, and only `action` separates them. '
                        . 'NOTE: unlike the other vendors, one activation caller is unattended - '
                        . 'see docs/firewall-rules.md. On a seat-limited licence, drop '
                        . 'activate_license with the apex_toolbox_http_default_rules filter',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function wpmuDevRules(): array
        {
            // WPMU DEV Dashboard (wpmudev-updates). A single helper posts the public update
            // catalogue AND every Hub write, so only the path separates a version check from
            // handing the vendor account this clone's URLs.
            // hub-sync is the LOGIN, and it is a write - see docs/firewall-rules.md for why it is
            // shipped and what it costs. It registers THIS site's own domain, so it cannot touch
            // production's Hub record; its removal sibling hub-unsync is fenced off by both path
            // and verb. It is NOT attended - a twicedaily cron re-syncs on its own - so a
            // seat-limited membership should strip it with apex_toolbox_http_default_rules.
            // NOT allowed: /api/dashboard/v2/hub-unsync (DELETE - deregisters the site from the
            // Hub), /api/dashboard/v2/grant-access + /revoke-access (open and close WPMU DEV
            // support staff login on the site), /api/dashboard/v2/user-info (account read
            // carrying the API key), /api/dashboard/v2/download/ + /download-dashboard +
            // /install/ (premium packages - the download window covers those), /site-authenticate,
            // /google-auth, /sso-hub (login handshakes), /api/analytics/v1/enable + /disable
            // (turn Hub analytics on and off for the site) and the rest of /api/analytics/v1/
            // (per-site analytics reads carrying the API key).
            return array(
                array(
                    'host' => 'wpmudev.com',
                    'methods' => array('GET'),
                    'paths' => array(
                        '/api/dashboard/v2/projects',
                        '/api/dashboard/v2/changelog',
                    ),
                    'note' => 'WPMU DEV project catalogue: the latest version of every WPMU DEV '
                        . 'plugin and theme, which is what raises the update badge; plus the '
                        . 'per-project changelog behind the "view details" modal. Both read-only '
                        . 'and neither needs an API key.',
                ),
                array(
                    'host' => 'wpmudev.com',
                    'methods' => array('GET'),
                    // Wildcard segment, not a plain prefix: the locale sits mid-path and the
                    // translation ZIP is served from a SERVER-SUPPLIED url under this same
                    // /sets/ tree. Pinning the trailing /projects is what keeps the package out,
                    // and it needs no rule - Language_Pack_Upgrader fetches it inside the
                    // download window.
                    'paths' => array('/api/translations/v1/sets/*/projects'),
                    // Second, independent fence on the same package. A path prefix necessarily
                    // matches longer paths too, so /sets/<locale>/projects/<anything> would ride
                    // in on the pin above; the client appends format=pomo_zip to the package URL
                    // unconditionally, while the set list sends no parameters at all.
                    'absent' => array('format'),
                    'note' => 'WPMU DEV translation set list for the site locale. Read-only; the '
                        . 'API key travels in an Authorization header, which the firewall never '
                        . 'reads or records. The translation package under the same /sets/ tree '
                        . 'stays blocked by rule and is reachable only in the download window.',
                ),
                array(
                    'host' => 'wpmudev.com',
                    'methods' => array('POST'),
                    'paths' => array('/api/dashboard/v2/hub-sync'),
                    'note' => 'WPMU DEV Hub sync, which IS the Dashboard login - the connect '
                        . 'handler calls it and clears the stored API key when it fails, so a '
                        . 'blocked sync logs the clone out rather than merely failing. A WRITE: '
                        . 'it registers the calling domain against the membership and POSTs '
                        . 'admin_url, home_url, sso_status and encrypted auth cookies. Allowed '
                        . 'because the domain it registers is the CLONE\'s own, so production\'s '
                        . 'Hub record is untouched; hub-unsync, which deregisters, is a different '
                        . 'path AND a DELETE. Unlike the other vendor writes this one also runs '
                        . 'unattended on a twicedaily cron, and each synced clone occupies a Hub '
                        . 'site slot - on a seat-limited membership drop this rule with the '
                        . 'apex_toolbox_http_default_rules filter',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function adminMenuEditorRules(): array
        {
            // Admin Menu Editor Pro. Two APIs on one host, and neither one can be pinned the
            // usual way. The update check is a GET to the BARE HOST with no path - and '/' as a
            // prefix means "any path" - so `require` on get_metadata_for is what narrows it. The
            // licensing API puts the licence key or site token in a path SEGMENT
            // (/licenses/<key>, /licenses/bytoken/<token>) with the action appended AFTER it, so
            // no fixed prefix can pin the trailing action. METHOD separates the read from the
            // writes - the vendor's own WSLM server enforces that split too, routing get_license
            // as requireRequestMethod('GET') and both seat writes as POST - and a WILDCARD SEGMENT
            // separates the two writes from each other, which nothing else can do: license_site
            // and unlicense_site are the same host, the same method and identical bodies.
            // The licence read must be allowed or the clone cannot install an update at all: the
            // package URL is nulled unless the cached licence status is 'valid'.
            // NOT allowed:
            // /licensing_api/products/<slug>/licenses/{<key>|bytoken/<token>}/unlicense_site
            // (releases production's seat - the clone carries production's site token in the
            // cloned DB), and the premium package (server-supplied URL, the client appends the
            // licence key and token to it).
            return array(
                array(
                    'host' => 'adminmenueditor.com',
                    'methods' => array('GET'),
                    'require' => array('get_metadata_for' => array('admin-menu-editor-pro')),
                    'note' => 'Admin Menu Editor Pro update check: returns the new version plus the '
                        . 'package URL, and is what raises the update badge. Read-only. Seat '
                        . 'removal lives under /licensing_api/ on this same host and stays '
                        . 'blocked.',
                ),
                array(
                    'host' => 'adminmenueditor.com',
                    'methods' => array('POST'),
                    // The licence key is a path SEGMENT with the action appended after it, so a
                    // fixed prefix cannot pin `license_site` - the wildcard segment can, and that
                    // is the only thing keeping this rule off `unlicense_site`.
                    'paths' => array(
                        '/licensing_api/products/admin-menu-editor-pro/licenses/*/license_site',
                    ),
                    'note' => 'Admin Menu Editor Pro seat activation. A WRITE, allowed on the same '
                        . 'basis as Elementor\'s: it adds THIS site to the licence and leaves the '
                        . 'production site alone, and it only runs from a human activating on the '
                        . 'licence screen. unlicense_site is the dangerous half - a clone carries '
                        . 'production\'s site token, so it could release production\'s seat - and '
                        . 'stays blocked. On a seat-limited licence, remove this rule with the '
                        . 'apex_toolbox_http_default_rules filter.',
                ),
                array(
                    'host' => 'adminmenueditor.com',
                    'methods' => array('GET'),
                    // The key/token is a path SEGMENT and the action is appended after it, so this
                    // prefix necessarily covers the seat writes too - `methods` is what holds them
                    // back, and the vendor's server rejects a GET on them a second time.
                    'paths' => array('/licensing_api/products/admin-menu-editor-pro/licenses'),
                    'note' => 'Admin Menu Editor Pro licence status read: returns the key state and '
                        . 'expiry. Read-only, and REQUIRED for updates - the client nulls the '
                        . 'package URL unless this call reports the licence valid, so blocking it '
                        . 'leaves the clone able to see an update but not install it. This rule '
                        . 'is GET-only; the seat writes are POSTs to this same prefix, of which '
                        . 'only license_site has its own rule and unlicense_site stays blocked.',
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
        private static function fileBirdRules(): array
        {
            // FileBird Pro (NinjaTeam). The host splits cleanly for once: the update manifest has
            // its own path, while every licence operation is admin-ajax.php with an `action` query
            // parameter - so the update rule cannot reach the licence calls at all, and the licence
            // rule is pinned by `require` on that parameter.
            // Activation is a WRITE, allowed on the usual terms: njt_validate_code registers THIS
            // site after an Envato login, and only from ajax_login_envato_success(), which checks a
            // nonce and manage_options. The browser's trip to the vendor's envato-login page is a
            // redirect the firewall never sees.
            // NOT allowed: action=njt_deactivate_code (releases the registration, and a clone
            // carries production's purchase code), action=njt_license_old_site (licence transfer).
            return array(
                array(
                    'host' => 'active.ninjateam.org',
                    'methods' => array('POST'),
                    'paths' => array('/json/filebird.json'),
                    'note' => 'FileBird Pro update manifest: returns the new version and package '
                        . 'URL. Read-only. Licence operations live on admin-ajax.php on this same '
                        . 'host and are gated separately.',
                ),
                array(
                    'host' => 'active.ninjateam.org',
                    'methods' => array('POST'),
                    'paths' => array('/wp-admin/admin-ajax.php'),
                    'require' => array('action' => array('njt_validate_code')),
                    'note' => 'FileBird Pro licence activation, run from the licence screen after an '
                        . 'Envato login. A WRITE that registers THIS site and leaves production '
                        . 'alone. Deactivation and licence transfer are the same path and method - '
                        . 'only `action` differs - and stay blocked. On a seat-limited licence, drop '
                        . 'this rule with the apex_toolbox_http_default_rules filter.',
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
