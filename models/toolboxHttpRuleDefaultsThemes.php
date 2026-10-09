<?php

// Forbid accessing directly
if (! defined('ABSPATH')) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}
// Ensure there are no conflicts
if (! class_exists('toolboxHttpRuleDefaultsThemes')) {
    /**
     * The curated outbound-firewall rules for THEME vendors.
     *
     * A continuation of toolboxHttpRuleDefaults, which merges this list into its own. That class
     * reached the phpcs class-length cap with the plugin vendors, and a premium theme is a
     * different shape anyway: a theme-side updater plus a companion plugin that re-implements it,
     * both talking to the same hosts. Same rule shape and the same rules of engagement - see the
     * docblock on toolboxHttpRuleDefaults and docs/firewall-rules.md. DATA ONLY.
     */
    class toolboxHttpRuleDefaultsThemes
    {
        /** @return list<array<string,mixed>> */
        public static function rules(): array
        {
            return array_merge(
                self::artbeesRules(),
                self::inspiryRules(),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function inspiryRules(): array
        {
            // Inspiry Themes (the RealHomes theme and its companion plugins: Easy Real Estate,
            // RealHomes Elementor Addon, Real Estate CRM). One update server, one POST per product
            // path, and the same body on all of them: `action`, a serialised `request` and an
            // `api-key` that is just md5(home_url()) - there is no licence on this host at all.
            // Purchase verification goes to api.envato.com instead (author/sale, with the vendor's
            // own token), and that is NOT allowed: on an unregistered site a failed verification
            // deletes the stored purchase code, which on a clone is a clone-only reset. Paths are
            // pinned to the four known products and `require` to the three read actions, so a new
            // Inspiry product or a new action keeps appearing in the log. Host is exact: `action`
            // is far too generic a key to carry a wildcard, and the vendor's apex and support
            // hosts serve the account.
            // NOT allowed: GET anything, any other /themes/ or /plugins/ product path, any action
            // beyond the three below, and the package download (the download window handles it).
            return array(
                array(
                    'host' => 'update.inspirythemes.com',
                    'methods' => array('POST'),
                    'paths' => array(
                        '/themes/realhomes',
                        '/plugins/easy-real-estate',
                        '/plugins/realhomes-elementor-addon',
                        '/plugins/real-estate-crm',
                    ),
                    'require' => array(
                        'action' => array('theme_update', 'basic_check', 'plugin_information'),
                    ),
                    'note' => 'Inspiry Themes update check for the RealHomes theme (theme_update) '
                        . 'and its three companion plugins (basic_check), plus the plugin-'
                        . 'information modal (plugin_information). Returns new_version and the '
                        . 'package URL, and is what raises the update badge. Read-only: the api-key '
                        . 'it sends is an md5 of the site URL, not a licence, and nothing on this '
                        . 'host registers or releases anything. The zip itself is fetched inside '
                        . 'the download window.',
                ),
            );
        }

        /** @return list<array<string,mixed>> */
        private static function artbeesRules(): array
        {
            // Artbees (the Jupiter X theme plus the jupiterx-core companion plugin, which
            // re-implements the theme's updater and control panel against the same hosts). Three
            // hosts, and EVERY one of them also serves a licence endpoint, so no host is
            // wildcarded and every rule is path-pinned. The API key is bound to ONE domain on the
            // server ("registered on another domain" is a state the client tracks), so registering
            // a clone can move production's registration and revoking can release it.
            // The theme update is a POST to /api/v1/update-theme where only the `action` body
            // value separates the version check from anything else; the host is exact because
            // `action` is far too generic a key to carry a wildcard. The two package-link actions
            // send the API key plus the clone's domain and return a download URL. They are allowed
            // on the same footing as Elementor's pro/info: without them the update is visible but
            // can never be installed. The zip itself is fetched by WordPress inside the download
            // window.
            // NOT allowed: artbees.net /api/v1/verify (binds the key to this domain - the old
            // control panel and the setup wizard); themes.artbees.net and my.artbees.net
            // /wp-json/artbees_license/* (register, verify/api_key, revoke, and the weekly
            // validate_license cron, which sends the key and the clone's domain unattended);
            // my.artbees.net /wp-json/templates/v1/*, jupiterx.artbees.net presets and
            // artbees.net /api/v2/theme/* (template and widget-preset browsing: read-only but not
            // needed for updates, add deliberately if a clone needs them); artbees.net
            // /wp-json/artbees/v1/latest-posts (control-panel news feed).
            return array(
                array(
                    'host' => 'artbees.net',
                    'methods' => array('POST'),
                    'paths' => array('/api/v1/update-theme'),
                    'require' => array(
                        'action' => array(
                            'check_new_version',
                            'get_release_note',
                            'get_release_notes',
                            'get_release_download_link',
                            'get_theme_package',
                        ),
                    ),
                    'note' => 'Jupiter X theme update check: the release list that raises the '
                        . 'update badge (get_release_note / get_release_notes, check_new_version '
                        . 'on older builds) and the two calls that turn a release into a download '
                        . 'URL (get_release_download_link, get_theme_package). The last two send '
                        . 'the API key and this site\'s domain, as Elementor\'s update check does, '
                        . 'and are allowed because without them the update shows but cannot be '
                        . 'installed. Only the action value separates these from anything else '
                        . 'on this path. The sibling /api/v1/verify registers the key against '
                        . 'this domain and stays blocked: the key is bound to one domain, and '
                        . 'that domain is production\'s.',
                ),
                array(
                    'host' => 'artbees.net',
                    'methods' => array('GET'),
                    'paths' => array('/api/v2/tools/plugin-custom-list'),
                    'note' => 'Jupiter X managed-plugin manifest: the bundled plugins '
                        . '(jupiterx-core and the rest) with their current versions and package '
                        . 'URLs. This is the update check for every Artbees-hosted plugin, run '
                        . 'from WordPress\'s own plugin update check. Read-only; the package '
                        . 'itself is fetched inside the download window.',
                ),
                array(
                    'host' => 'themes.artbees.net',
                    'methods' => array('GET'),
                    'paths' => array('/wp-json/plugins/v1/list'),
                    'note' => 'Jupiter X plugin catalogue, theme side: names, slugs and sources '
                        . 'of the recommended plugins. Read-only, no key. The licence endpoints '
                        . 'on this host live under /wp-json/artbees_license/ and stay blocked.',
                ),
                array(
                    'host' => 'my.artbees.net',
                    'methods' => array('GET'),
                    'paths' => array('/wp-json/plugins/v1/list'),
                    'note' => 'Jupiter X plugin catalogue, jupiterx-core side: the same list the '
                        . 'theme reads from themes.artbees.net. Read-only, no key. The licence '
                        . 'endpoints on this host live under /wp-json/artbees_license/ and stay '
                        . 'blocked.',
                ),
                array(
                    'host' => 'my.artbees.net',
                    'methods' => array('POST'),
                    'paths' => array('/wp-json/artbees-portal-products/v1/products'),
                    'note' => 'Jupiter X product and version catalogue behind the control panel '
                        . 'Updates and Version Control panes, and its /package child, which '
                        . 'returns the download URL for a chosen version. Read-only, no key; the '
                        . 'zip is fetched inside the download window.',
                ),
            );
        }
    }
}
