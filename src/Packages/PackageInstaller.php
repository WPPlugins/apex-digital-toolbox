<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

use Plugin_Upgrader;

/**
 * One-click install and update of an entitled package, driven from the Packages admin screen.
 *
 * Everything that can fail before any work happens is decided up front by the pure helpers below,
 * so a site that cannot install (file modifications disabled, or a filesystem WordPress can only
 * reach over FTP) shows a disabled button and the reason, instead of a long request that ends in a
 * credentials prompt no automated skin can answer.
 *
 * The download itself is not performed here. It goes through the upgrader, which means it passes
 * UpdateController::verifyAndDownload() and is checked against the manifest's digest - the same
 * path a background update takes, so there is only one place that decides a package is genuine.
 */
class PackageInstaller
{
    /**
     * Pure. A downgrade is refused rather than performed: the registry offering an older version
     * than the site runs means something is wrong upstream, and quietly rolling a site back is a
     * worse outcome than doing nothing.
     */
    public static function decideAction(?string $installedVersion, string $available): string
    {
        if ($installedVersion === null || $installedVersion === '') {
            return 'install';
        }
        if (version_compare($available, $installedVersion, '>')) {
            return 'update';
        }

        return version_compare($available, $installedVersion, '<')
            ? 'downgrade-blocked'
            : 'current';
    }

    /** Pure. Why this site cannot install anything at all, or null when it can. */
    public static function blockingReason(bool $fileModsAllowed, string $filesystemMethod): ?string
    {
        if (! $fileModsAllowed) {
            return __('Plugin installation is disabled on this site (DISALLOW_FILE_MODS).', 'apex_toolbox');
        }

        if ($filesystemMethod !== 'direct') {
            return __('WordPress cannot write to the plugins folder on this site.', 'apex_toolbox')
                . ' ' . __('One-click install is unavailable here.', 'apex_toolbox');
        }

        return null;
    }

    /** @return array{ok:bool,message:string} */
    private static function failure(string $message): array
    {
        return array('ok' => false, 'message' => $message);
    }

    public function __construct(private readonly EntitlementRegistry $registry)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function install(string $slug): array
    {
        $record = $this->registry->get($slug);
        if ($record === null) {
            return self::failure(__('That package is not available to this site.', 'apex_toolbox'));
        }

        $reason = self::blockingReason($this->fileModsAllowed(), $this->filesystemMethod());
        if ($reason !== null) {
            return self::failure($reason);
        }

        if (! PackageRecord::isCompatible($record, $this->wordPressVersion(), PHP_VERSION)) {
            return self::failure(__('This site does not meet that package\'s requirements.', 'apex_toolbox'));
        }

        $installed = $this->registry->installedBySlug()[$slug] ?? null;
        $action = self::decideAction($installed['version'] ?? null, (string) $record['version']);
        if ($action === 'current') {
            return self::failure(__('That package is already up to date.', 'apex_toolbox'));
        }
        if ($action === 'downgrade-blocked') {
            return self::failure(
                __(
                    'The registry offers an older version than the one installed, so nothing was changed.',
                    'apex_toolbox',
                ),
            );
        }

        // Plugin_Upgrader::upgrade() silently deactivates the plugin first
        // (deactivate_plugin_before_upgrade) and core never turns it back on.
        // On the Plugins screen the browser skin papers over that with a hidden
        // iframe to update.php?action=activate-plugin; an automatic skin has no
        // such thing, so without this an update would leave the plugin off and
        // the site missing whatever it does. Noted BEFORE the upgrade, because
        // by the time it finishes the state has already been thrown away.
        $file = $installed['file'] ?? null;
        $wasActive = $file !== null && $this->activation($file);
        $wasNetworkActive = $wasActive && $this->networkActivation((string) $file);

        $skin = $this->skin();
        $result = $this->runUpgrader($record, $file, $skin);

        if ($result instanceof \WP_Error) {
            return self::failure($result->get_error_message());
        }
        if ($result !== true) {
            // Plugin_Upgrader::upgrade() drops the WP_Error a failed download returned, so without
            // the skin every failure reads the same - including a refused checksum, which is the
            // one worth investigating.
            return self::failure(
                $skin->firstError() ?? __('WordPress could not install the package.', 'apex_toolbox'),
            );
        }

        if ($wasActive && $file !== null) {
            // Silently, to match core: the deactivation above did not fire the
            // plugin's deactivation hooks, so firing its activation hooks now
            // would be a lopsided pair - an upgrade would run setup routines
            // that never had a matching teardown. update.php does the same.
            $reactivated = $this->reactivate($file, $wasNetworkActive);

            // Reactivation can genuinely fail - the new version may raise its
            // Requires PHP, or move its main file so $file no longer exists.
            // Reporting the update as a plain success would then leave exactly
            // the state this whole path exists to prevent: updated, switched
            // off, and nobody told. The files ARE updated, so say both halves.
            if ($reactivated instanceof \WP_Error) {
                return self::failure(sprintf(
                    /* translators: 1: plugin name, 2: version, 3: reason it could not be switched back on. */
                    __('%1$s was updated to %2$s but could not be reactivated: %3$s', 'apex_toolbox'),
                    (string) $record['name'],
                    (string) $record['version'],
                    $reactivated->get_error_message(),
                ));
            }
        }

        return array(
            'ok' => true,
            'message' => sprintf(
                /* translators: 1: plugin name, 2: version number. */
                __('%1$s %2$s installed.', 'apex_toolbox'),
                (string) $record['name'],
                (string) $record['version'],
            ),
        );
    }

    /** Loads the upgrader includes and returns the skin that will record any failure. */
    protected function skin(): InstallerSkin
    {
        foreach (array('file', 'misc', 'plugin', 'class-wp-upgrader') as $include) {
            require_once ABSPATH . 'wp-admin/includes/' . $include . '.php';
        }

        return new InstallerSkin();
    }

    /**
     * @param array<string, mixed> $record
     * @return bool|\WP_Error|string|null
     */
    protected function runUpgrader(array $record, ?string $pluginFile, InstallerSkin $skin)
    {
        $upgrader = new Plugin_Upgrader($skin);

        if ($pluginFile === null) {
            return $upgrader->install((string) $record['download_url']);
        }

        // upgrade() reads the package URL out of the update transient rather than taking one, so
        // prime it - the site may not have run an update check since this version was published.
        $this->primeUpdateTransient($record, $pluginFile);

        return $upgrader->upgrade($pluginFile);
    }

    /** @param array<string, mixed> $record */
    protected function primeUpdateTransient(array $record, string $pluginFile): void
    {
        $transient = get_site_transient('update_plugins');
        if (! is_object($transient)) {
            $transient = (object) array('response' => array(), 'no_update' => array());
        }
        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = array();
        }

        $payload = UpdateController::buildUpdatePayload($record, $pluginFile);
        $payload['id'] = PackagesConfig::updateUriFor((string) $record['slug']);
        $payload['plugin'] = $pluginFile;
        $payload['new_version'] = (string) $record['version'];

        $transient->response[$pluginFile] = (object) $payload;
        unset($transient->no_update[$pluginFile]);

        set_site_transient('update_plugins', $transient);
    }

    protected function activation(string $file): bool
    {
        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active($file);
    }

    protected function networkActivation(string $file): bool
    {
        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network($file);
    }

    /** @return \WP_Error|null Null on success, as activate_plugin() returns. */
    protected function reactivate(string $file, bool $networkWide)
    {
        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return activate_plugin($file, '', $networkWide, true);
    }

    protected function fileModsAllowed(): bool
    {
        return ! (defined('DISALLOW_FILE_MODS') && constant('DISALLOW_FILE_MODS'));
    }

    protected function filesystemMethod(): string
    {
        if (! function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return (string) get_filesystem_method();
    }

    protected function wordPressVersion(): string
    {
        return (string) get_bloginfo('version');
    }
}
