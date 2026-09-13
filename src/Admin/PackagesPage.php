<?php

declare(strict_types=1);

namespace ApexToolbox\Admin;

use ApexToolbox\Lib\Action;
use ApexToolbox\Packages\EntitlementRegistry;
use ApexToolbox\Packages\ManifestClient;
use ApexToolbox\Packages\PackageInstaller;
use ApexToolbox\Packages\PackageRecord;
use ApexToolbox\Packages\PackagesConfig;
use toolboxController;

/**
 * Apex Toolbox -> Packages: the private plugins this site is licensed for.
 *
 * Sits under the plugin's own top-level menu rather than under Settings (where the MCP diagnostics
 * page lives), because this one is used rather than merely inspected. The parent slug is derived
 * from the legacy admin hook instead of hard-coded, since that hook owns the menu and can in
 * principle be switched off.
 *
 * The screen reads the manifest directly rather than the update transient. That matters: core's
 * update check bails early if wordpress.org is unreachable, and the Update URI loop runs after
 * that point - so on a site that cannot reach wordpress.org the Updates screen would show nothing
 * while this page still shows the truth.
 */
final class PackagesPage
{
    public const SLUG = 'apex_toolbox_packages';

    public const REFRESH_ACTION = 'apex_toolbox_packages_refresh';

    public const INSTALL_ACTION = 'apex_toolbox_packages_install';

    public const TOKEN_ACTION = 'apex_toolbox_packages_save_token';

    public const ACTIVATE_ACTION = 'apex_toolbox_packages_activate';

    public const DISCONNECT_ACTION = 'apex_toolbox_packages_disconnect';

    private const CAPABILITY = 'manage_options';

    /**
     * The slug of the plugin's top-level menu, derived from the hook that registers it.
     */
    public static function parentSlug(toolboxController $toolbox): string
    {
        $adminHook = $toolbox->getObject('word_press_admin');
        $slug = is_object($adminHook) && isset($adminHook->_menu[0]['slug'])
            ? (string) $adminHook->_menu[0]['slug']
            : 'hooks';

        return $toolbox->getPrefix() . $slug;
    }

    /**
     * The capability needed to install. Plugin files are network-wide, so on multisite this is a
     * network decision and a site administrator must not be able to make it.
     */
    public static function installCapability(bool $isMultisite): string
    {
        return $isMultisite
            ? 'manage_network_plugins'
            : 'update_plugins';
    }

    /**
     * One row per package the site either holds or is entitled to.
     *
     * A plugin that is installed but absent from a successful manifest is REVOKED - shown, still
     * running, and simply not offered updates. That is the whole of what revocation does on the
     * site; nothing is deactivated or deleted.
     *
     * @param array<string, array<string, mixed>> $entitled
     * @param array<string, array{file:string,version:string,name:string}> $installed
     * @return list<array<string, mixed>>
     */
    public static function buildRows(
        array $entitled,
        array $installed,
        string $wpVersion,
        string $phpVersion,
        bool $manifestTrusted,
    ): array {
        $rows = array();

        foreach ($entitled as $slug => $record) {
            $have = $installed[$slug] ?? null;
            $compatible = PackageRecord::isCompatible($record, $wpVersion, $phpVersion);
            $action = PackageInstaller::decideAction($have['version'] ?? null, (string) $record['version']);

            $active = (bool) ($have['active'] ?? false);
            $offer = self::offeredAction($action, $active, $compatible);

            $rows[] = array(
                'slug' => (string) $slug,
                'name' => (string) $record['name'],
                'description' => (string) $record['description'],
                'installed' => $have['version'] ?? '',
                'available' => (string) $record['version'],
                'requires' => (string) $record['requires'],
                'requires_php' => (string) $record['requires_php'],
                'active' => $have !== null && $active,
                'status' => self::rowStatus($action, $compatible, $have !== null, $active),
                'action' => $offer,
                'can_act' => $offer !== '',
                'action_label' => self::actionLabel($offer),
            );
        }

        // Installed but not entitled. Only meaningful when the manifest is actually trusted - a
        // registry we could not reach must never be reported as a revocation.
        if ($manifestTrusted) {
            foreach ($installed as $slug => $have) {
                if (isset($entitled[$slug])) {
                    continue;
                }
                $rows[] = array(
                    'slug' => (string) $slug,
                    'name' => $have['name'],
                    'description' => '',
                    'installed' => $have['version'],
                    'available' => '',
                    'requires' => '',
                    'requires_php' => '',
                    'active' => (bool) ($have['active'] ?? false),
                    'status' => __('No longer licensed', 'apex_toolbox'),
                    'action' => '',
                    'can_act' => false,
                    'action_label' => '',
                );
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * @param array<string, mixed> $status
     * @return array{level:string,text:string}
     */
    public static function statusBanner(array $status, int $now): array
    {
        if (! (bool) ($status['has_token'] ?? false)) {
            return array(
                'level' => 'warning',
                'text' => __('This site has no registry token, so no private packages are available.', 'apex_toolbox')
                    . ' ' . __('Ask for one to be issued, then paste it below.', 'apex_toolbox'),
            );
        }

        $checked = (int) ($status['checked_at'] ?? 0);
        $ago = $checked > 0
            ? sprintf(
                /* translators: %s: human-readable time difference, e.g. "20 mins". */
                __('Last checked %s ago.', 'apex_toolbox'),
                human_time_diff($checked, $now),
            )
            : __('Not checked yet.', 'apex_toolbox');

        $state = (string) ($status['state'] ?? 'never');
        $message = (string) ($status['message'] ?? '');

        if ($state === ManifestClient::STATE_OK) {
            return array('level' => 'success', 'text' => $ago);
        }
        if ($state === 'never') {
            return array('level' => 'info', 'text' => $ago);
        }

        if ($state === ManifestClient::STATE_REJECTED) {
            $prefix = __('The registry rejected this site\'s token.', 'apex_toolbox');
        } elseif ($state === ManifestClient::STATE_BLOCKED) {
            $prefix = __('The request was stopped by this site\'s outbound firewall.', 'apex_toolbox')
                . ' ' . __('That is normal on a development or staging copy.', 'apex_toolbox');
        } else {
            $prefix = __('The registry could not be reached.', 'apex_toolbox');
        }

        return array(
            'level' => $state === ManifestClient::STATE_REJECTED ? 'error' : 'warning',
            'text' => trim($prefix . ' ' . $message . ' ' . $ago),
        );
    }

    /**
     * Which single action this row offers, or '' for none.
     *
     * Precedence matters when a package is both out of date and switched off:
     * updating comes first, because activating an old copy only to update it a
     * moment later is the wrong order. Activate then appears on the next render.
     */
    public static function offeredAction(string $versionAction, bool $active, bool $compatible): string
    {
        if (! $compatible) {
            return '';
        }
        if (in_array($versionAction, array('install', 'update'), true)) {
            return $versionAction;
        }

        return $active
            ? ''
            : 'activate';
    }

    /** @param array<string, mixed> $row */
    private static function requirementText(array $row): string
    {
        $parts = array();
        if ((string) $row['requires'] !== '') {
            $parts[] = 'WP ' . (string) $row['requires'] . '+';
        }
        if ((string) $row['requires_php'] !== '') {
            $parts[] = 'PHP ' . (string) $row['requires_php'] . '+';
        }

        return $parts === array()
            ? '—'
            : implode(', ', $parts);
    }

    /** What a button says while its request is in flight. */
    private static function busyLabel(string $action): string
    {
        switch ($action) {
            case 'install':
                return __('Installing…', 'apex_toolbox');
            case 'update':
                return __('Updating…', 'apex_toolbox');
            case 'activate':
                return __('Activating…', 'apex_toolbox');
            default:
                return __('Working…', 'apex_toolbox');
        }
    }

    private static function actionLabel(string $action): string
    {
        switch ($action) {
            case 'install':
                return __('Install', 'apex_toolbox');
            case 'update':
                return __('Update', 'apex_toolbox');
            case 'activate':
                return __('Activate', 'apex_toolbox');
            default:
                return '';
        }
    }

    private static function rowStatus(string $action, bool $compatible, bool $installed, bool $active): string
    {
        if (! $compatible) {
            return __('Not compatible with this site', 'apex_toolbox');
        }

        switch ($action) {
            case 'install':
                return __('Not installed', 'apex_toolbox');
            case 'update':
                return __('Update available', 'apex_toolbox');
            case 'downgrade-blocked':
                return __('Newer than the registry', 'apex_toolbox');
            default:
                if ($installed && ! $active) {
                    return __('Installed, not active', 'apex_toolbox');
                }

                return __('Active', 'apex_toolbox');
        }
    }

    public function __construct(
        private readonly EntitlementRegistry $registry,
        private readonly PackageInstaller $installer,
        private readonly PackagesConfig $config,
        private readonly toolboxController $toolbox,
    ) {
    }

    /**
     * Priority 20: the legacy createAdminMenu() runs at 10 and is what creates the parent menu.
     */
    /**
     * The page's own script, on the package screen only.
     *
     * Install and update are slow - a download, a checksum, an unzip - and with
     * no feedback the natural reaction is to click again. The script marks the
     * button busy and disables the rest, so a second install cannot be started
     * on top of the first.
     */
    #[Action('admin_enqueue_scripts')]
    public function enqueueAssets(string $hookSuffix): void
    {
        if (! str_contains($hookSuffix, self::SLUG)) {
            return;
        }

        $relative = 'assets/js/apex-packages.js';
        $absolute = APEX_TOOLBOX_PLUGIN_PATH . $relative;
        wp_enqueue_script(
            'apex-toolbox-packages',
            APEX_TOOLBOX_PLUGIN_URL . $relative,
            array(),
            file_exists($absolute) ? (string) filemtime($absolute) : false,
            true,
        );
    }

    #[Action('admin_menu', 20)]
    public function registerMenu(): void
    {
        add_submenu_page(
            self::parentSlug($this->toolbox),
            __('Apex Toolbox | Packages', 'apex_toolbox'),
            __('Packages', 'apex_toolbox'),
            self::CAPABILITY,
            self::SLUG,
            array($this, 'render'),
        );
    }

    #[Action('admin_post_' . self::REFRESH_ACTION)]
    public function handleRefresh(): void
    {
        $this->authorise(self::CAPABILITY, self::REFRESH_ACTION);
        $this->registry->refresh();
        $this->redirect(__('Package list refreshed.', 'apex_toolbox'), 'updated');
    }

    #[Action('admin_post_' . self::INSTALL_ACTION)]
    public function handleInstall(): void
    {
        $this->authorise(self::installCapability(is_multisite()), self::INSTALL_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() verified the nonce.
        $slug = '';
        if (isset($_POST['slug'])) {
            $slug = sanitize_key(wp_unslash((string) $_POST['slug']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $result = $this->installer->install($slug);

        $this->redirect($result['message'], $result['ok'] ? 'updated' : 'error');
    }

    /**
     * Activate a package that is already installed.
     *
     * activate_plugin() is given a redirect target so core runs its own sandbox
     * scrape: a plugin that fatals on activation is caught and left switched
     * off, rather than taking the admin down with it. That is what the Plugins
     * screen does, and it is the whole reason not to just flip the option.
     */
    #[Action('admin_post_' . self::ACTIVATE_ACTION)]
    public function handleActivate(): void
    {
        $this->authorise('activate_plugins', self::ACTIVATE_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() verified the nonce.
        $slug = '';
        if (isset($_POST['slug'])) {
            $slug = sanitize_key(wp_unslash((string) $_POST['slug']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Only ever a package this site is entitled to. A revoked-but-installed
        // plugin is left alone: it keeps running if it was running, and this
        // screen does not offer to switch it back on.
        $record = $this->registry->get($slug);
        $installed = $this->registry->installedBySlug()[$slug] ?? null;
        if ($record === null || $installed === null) {
            $this->redirect(__('That package is not available to activate.', 'apex_toolbox'), 'error');
        }

        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin(
            (string) $installed['file'],
            add_query_arg('page', self::SLUG, admin_url('admin.php')),
        );

        if ($result instanceof \WP_Error) {
            $this->redirect($result->get_error_message(), 'error');
        }

        $this->redirect(
            sprintf(
                /* translators: %s: plugin name. */
                __('%s activated.', 'apex_toolbox'),
                (string) $record['name'],
            ),
            'updated',
        );
    }

    /**
     * Forget the saved token.
     *
     * Kept separate from saving an empty one so the screen never has to show a
     * text field for a credential that is already in place - replacing a token
     * means disconnecting first, which makes the destructive half explicit
     * rather than something that happens when a field is cleared by accident.
     */
    #[Action('admin_post_' . self::DISCONNECT_ACTION)]
    public function handleDisconnect(): void
    {
        $this->authorise(self::CAPABILITY, self::DISCONNECT_ACTION);

        delete_option(PackagesConfig::TOKEN_OPTION);
        $this->registry->refresh();

        $this->redirect(
            __('Disconnected from the package registry.', 'apex_toolbox'),
            'updated',
        );
    }

    #[Action('admin_post_' . self::TOKEN_ACTION)]
    public function handleSaveToken(): void
    {
        $this->authorise(self::CAPABILITY, self::TOKEN_ACTION);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- authorise() verified the nonce.
        $token = '';
        if (isset($_POST['token'])) {
            $token = sanitize_text_field(wp_unslash((string) $_POST['token']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // autoload=false deliberately: this is a credential, and nothing on a front-end request
        // needs it. The legacy settings page is not used for the same reason - setOption() cannot
        // express that.
        update_option(PackagesConfig::TOKEN_OPTION, $token, false);
        $this->registry->refresh();

        $this->redirect(
            $token === ''
                ? __('Registry token removed.', 'apex_toolbox')
                : __('Registry token saved.', 'apex_toolbox'),
            'updated',
        );
    }

    public function render(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }

        // all() may fetch, which changes the status - so read the status AFTER it.
        // The other way round, a fetch that fails and leaves the cache past its
        // grace period gives an empty entitlement list beside a stale "ok", and
        // every installed package is then labelled as no longer licensed.
        $entitled = $this->registry->all();
        $status = $this->registry->status();
        $installed = $this->registry->installedBySlug();
        $trusted = (string) ($status['state'] ?? '') === ManifestClient::STATE_OK;
        $rows = self::buildRows($entitled, $installed, (string) get_bloginfo('version'), PHP_VERSION, $trusted);
        $banner = self::statusBanner($status, time());
        $blocked = PackageInstaller::blockingReason(
            ! (defined('DISALLOW_FILE_MODS') && constant('DISALLOW_FILE_MODS')),
            function_exists('get_filesystem_method') ? (string) get_filesystem_method() : 'direct',
        );

        // This page needs manage_options; installing needs more, and on multisite
        // that is a network capability a site administrator does not hold. Without
        // this they would be shown buttons that can only ever end in a 403.
        $mayInstall = current_user_can(self::installCapability(is_multisite()));
        if ($blocked === null && ! $mayInstall) {
            $blocked = __('You do not have permission to install plugins on this site.', 'apex_toolbox');
        }
        // Activating is a separate capability, and one a site administrator can
        // hold on a network where installing is reserved to the network admin.
        $mayActivate = current_user_can('activate_plugins');

        // The wrapper class scopes assets/js/apex-packages.js to this screen's forms.
        echo '<div class="wrap apex-packages">';
        echo '<style>.apex-packages-connection{display:flex;align-items:center;gap:.35em;font-weight:600}'
            . '.apex-packages-connection .dashicons{color:#00a32a}'
            . '.apex-packages table.widefat{margin-top:.5em}</style>';
        echo '<h1>' . esc_html__('Packages', 'apex_toolbox') . '</h1>';

        $this->renderActionNotice();

        echo '<p>'
            . esc_html__('Private plugins licensed to this website.', 'apex_toolbox') . ' '
            . esc_html__(
                'Updates for anything installed here also appear on the WordPress Updates screen.',
                'apex_toolbox',
            )
            . '</p>';

        printf(
            '<div class="notice notice-%s inline"><p>%s</p></div>',
            esc_attr($banner['level']),
            esc_html($banner['text']),
        );

        if ($blocked !== null) {
            printf('<div class="notice notice-warning inline"><p>%s</p></div>', esc_html($blocked));
        }

        // Nothing to refresh without a token, and a button that cannot do anything
        // is worse than no button.
        if ((bool) ($status['has_token'] ?? false)) {
            $this->renderRefreshButton();
        }
        $this->renderTable($rows, $blocked === null, $mayActivate);
        $this->renderTokenForm($status);

        echo '</div>';
    }

    /**
     * The outcome of the last admin-post action, carried back through the redirect.
     *
     * Read-only display of a value this class put in the URL itself, so there is nothing to verify
     * with a nonce - but it is escaped on the way out regardless, because a URL is user-editable.
     */
    private function renderActionNotice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, escaped below.
        $message = '';
        if (isset($_GET['apex_packages_message'])) {
            $message = sanitize_text_field(wp_unslash((string) $_GET['apex_packages_message']));
        }

        $type = 'updated';
        if (isset($_GET['apex_packages_type'])) {
            $type = sanitize_key(wp_unslash((string) $_GET['apex_packages_type']));
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($message === '') {
            return;
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type === 'error' ? 'error' : 'success'),
            esc_html($message),
        );
    }

    private function renderRefreshButton(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:1em 0">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::REFRESH_ACTION) . '">';
        wp_nonce_field(self::REFRESH_ACTION);
        echo '<button class="button" data-apex-busy="' . esc_attr__('Checking…', 'apex_toolbox') . '">'
            . esc_html__('Refresh now', 'apex_toolbox') . '</button>';
        echo '</form>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function renderTable(array $rows, bool $canInstall, bool $canActivate): void
    {
        echo '<table class="widefat striped"><thead><tr>';
        foreach (
            array(
                __('Package', 'apex_toolbox'),
                __('Installed', 'apex_toolbox'),
                __('Available', 'apex_toolbox'),
                __('Requires', 'apex_toolbox'),
                __('Status', 'apex_toolbox'),
                '',
            ) as $heading
        ) {
            echo '<th>' . esc_html((string) $heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows === array()) {
            echo '<tr><td colspan="6"><em>'
                . esc_html__('No private packages are licensed to this site.', 'apex_toolbox')
                . '</em></td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) $row['name']) . '</strong>';
            if ((string) $row['description'] !== '') {
                echo '<br><span class="description">' . esc_html((string) $row['description']) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) $row['installed'] !== '' ? (string) $row['installed'] : '—') . '</td>';
            echo '<td>' . esc_html((string) $row['available'] !== '' ? (string) $row['available'] : '—') . '</td>';
            echo '<td>' . esc_html(self::requirementText($row)) . '</td>';
            echo '<td>' . esc_html((string) $row['status']) . '</td>';
            echo '<td>';
            // Activating needs no filesystem write, so it stays available on a
            // site where installing does not.
            $isActivate = (string) $row['action'] === 'activate';
            $allowed = $isActivate
                ? $canActivate
                : $canInstall;
            if ((bool) $row['can_act'] && $allowed) {
                $formAction = $isActivate
                    ? self::ACTIVATE_ACTION
                    : self::INSTALL_ACTION;
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr($formAction) . '">';
                echo '<input type="hidden" name="slug" value="' . esc_attr((string) $row['slug']) . '">';
                wp_nonce_field($formAction);
                echo '<button class="button button-primary" data-apex-busy="'
                    . esc_attr(self::busyLabel((string) $row['action'])) . '">'
                    . esc_html((string) $row['action_label']) . '</button>';
                echo '</form>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /** @param array<string, mixed> $status */
    private function renderTokenForm(array $status): void
    {
        echo '<h2>' . esc_html__('Registry connection', 'apex_toolbox') . '</h2>';

        if ((bool) ($status['token_from_constant'] ?? false)) {
            echo '<p class="apex-packages-connection">'
                . '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
                . esc_html__('Connected. The token for this site is set in wp-config.php.', 'apex_toolbox')
                . '</p><p class="description"><code>APEX_TOOLBOX_PACKAGES_TOKEN</code> '
                . esc_html__('takes precedence, so it cannot be changed from here.', 'apex_toolbox')
                . '</p>';

            return;
        }

        if ((bool) ($status['has_token'] ?? false)) {
            // Connected: no text field at all. Replacing a token means
            // disconnecting first, so the destructive step is deliberate.
            echo '<p class="apex-packages-connection">'
                . '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
                . esc_html__('Connected to the package registry.', 'apex_toolbox')
                . ' <code>' . esc_html((string) ($status['token_hint'] ?? '')) . '</code></p>';
            echo '<p class="description">'
                . esc_html__('Disconnect to enter a different token.', 'apex_toolbox') . ' '
                . esc_html__(
                    'Packages already installed keep working; they stop being offered updates.',
                    'apex_toolbox',
                )
                . '</p>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::DISCONNECT_ACTION) . '">';
            wp_nonce_field(self::DISCONNECT_ACTION);
            echo '<button class="button" data-apex-busy="' . esc_attr__('Disconnecting…', 'apex_toolbox') . '">'
                . esc_html__('Disconnect', 'apex_toolbox') . '</button>';
            echo '</form>';

            return;
        }

        echo '<p class="description">'
            . esc_html__(
                'Paste the token issued for this website to see the packages it is licensed for.',
                'apex_toolbox',
            )
            . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::TOKEN_ACTION) . '">';
        wp_nonce_field(self::TOKEN_ACTION);
        echo '<p><input type="password" class="regular-text code" name="token" autocomplete="off" '
            . 'spellcheck="false" value="" placeholder="'
            . esc_attr__('Registry token', 'apex_toolbox') . '"></p>';
        echo '<button class="button button-primary" data-apex-busy="'
            . esc_attr__('Connecting…', 'apex_toolbox') . '">'
            . esc_html__('Connect', 'apex_toolbox') . '</button>';
        echo '</form>';
    }

    private function authorise(string $capability, string $action): void
    {
        if (! current_user_can($capability)) {
            wp_die(esc_html__('Insufficient permissions.', 'apex_toolbox'), '', array('response' => 403));
        }

        check_admin_referer($action);
    }

    private function redirect(string $message, string $type): void
    {
        $url = add_query_arg(
            array(
                'page' => self::SLUG,
                // add_query_arg() encodes values itself; pre-encoding here would
                // double it and put literal %20 into the notice.
                'apex_packages_message' => $message,
                'apex_packages_type' => $type,
            ),
            admin_url('admin.php'),
        );

        wp_safe_redirect($url);
        exit;
    }
}
