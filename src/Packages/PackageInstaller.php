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

    /**
     * Pure. Whether a copy installed from elsewhere may be replaced by the registry's build.
     *
     * The same version is allowed, and is the common case: swapping in the registry's build of the
     * version the site already runs, so that updates come from here from now on. A copy newer
     * than the registry is refused for the same reason decideAction() refuses a downgrade.
     */
    public static function decideTakeover(string $existingVersion, string $available): string
    {
        return $existingVersion !== '' && version_compare($available, $existingVersion, '<')
            ? 'downgrade-blocked'
            : 'takeover';
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

    /**
     * Refuses a take-over whose package would unpack into a different folder.
     *
     * Plugin_Upgrader names the destination after the zip's top-level folder, not the slug. A
     * package built as `foo-1.2.0/` would therefore not replace the copy in `foo/` at all: it would
     * land beside it as a second copy of the same plugin. Runs on upgrader_source_selection, after
     * the digest check and before anything in the plugins folder is touched.
     *
     * @param string|\WP_Error $source
     * @return string|\WP_Error
     */
    public static function guardTakeoverFolder($source, string $slug)
    {
        if ($source instanceof \WP_Error) {
            return $source;
        }

        if (basename(untrailingslashit((string) $source)) !== $slug) {
            return new \WP_Error(
                'apex_packages_takeover_folder',
                sprintf(
                    /* translators: %s: plugin folder name. */
                    __(
                        'The package does not unpack into the %s folder, so it cannot replace the existing copy.',
                        'apex_toolbox',
                    ),
                    $slug,
                ),
            );
        }

        return $source;
    }

    /**
     * Pure. A list of active plugin files with $old replaced by $new - or just removed, with null -
     * sorted as WordPress keeps it. A list without $old is returned untouched.
     *
     * @param list<string> $active
     * @return list<string>
     */
    public static function swapActivation(array $active, string $old, ?string $new): array
    {
        if (! in_array($old, $active, true)) {
            return $active;
        }

        $swapped = array_values(array_filter(
            $active,
            static fn (string $file): bool => $file !== $old && $file !== $new,
        ));
        if ($new !== null) {
            $swapped[] = $new;
        }
        sort($swapped);

        return $swapped;
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
        if ($installed === null && $this->registry->unmanagedCopyOf($slug) !== null) {
            // Plugin_Upgrader::install() would only fail on the existing folder,
            // with a message that suggests deleting it - on a live site, the one
            // thing not to do.
            return self::failure(
                __('A copy of that plugin installed from elsewhere is already in its folder.', 'apex_toolbox')
                . ' ' . __('Use Take over to replace it in place.', 'apex_toolbox'),
            );
        }
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

        return $this->finish(
            $record,
            $result,
            $skin,
            $wasActive ? $file : null,
            $wasNetworkActive,
            /* translators: 1: plugin name, 2: version, 3: reason it could not be switched back on. */
            __('%1$s was updated to %2$s but could not be reactivated: %3$s', 'apex_toolbox'),
            sprintf(
                /* translators: 1: plugin name, 2: version number. */
                __('%1$s %2$s installed.', 'apex_toolbox'),
                (string) $record['name'],
                (string) $record['version'],
            ),
        );
    }

    /**
     * Replace a copy of an entitled package that was installed from somewhere else - an old manual
     * upload, a GitHub download, a vendor's own zip - with the registry's build, in place.
     *
     * The alternative is deleting the working plugin first so that Install can run, which on a
     * client's live site means downtime. Here the plugin stays active throughout: the package is
     * downloaded, checked against the manifest digest and unpacked before the old files are
     * touched, and the old folder is only cleared for the new one to be moved straight in.
     *
     * $expectedFingerprint identifies the copy the user was shown on the confirmation screen (see
     * EntitlementRegistry::copyFingerprint()). If the folder holds anything else by the time they
     * confirm, nothing is done - the confirmation was about a different plugin.
     *
     * @return array{ok:bool,message:string}
     */
    public function takeOver(string $slug, string $expectedFingerprint): array
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

        if (isset($this->registry->installedBySlug()[$slug])) {
            return self::failure(
                __(
                    'That package is already managed by the registry, so there is nothing to take over.',
                    'apex_toolbox',
                ),
            );
        }

        $existing = $this->registry->unmanagedCopyOf($slug);
        if ($existing === null) {
            return self::failure(__('There is no existing copy of that package to take over.', 'apex_toolbox'));
        }
        if (! hash_equals(EntitlementRegistry::copyFingerprint($existing), $expectedFingerprint)) {
            return self::failure(
                __('The plugin in that folder has changed since the take-over was confirmed.', 'apex_toolbox')
                . ' ' . __('Nothing was changed; review it and try again.', 'apex_toolbox'),
            );
        }

        if (self::decideTakeover($existing['version'], (string) $record['version']) === 'downgrade-blocked') {
            return self::failure(sprintf(
                /* translators: 1: installed version, 2: registry version. */
                __(
                    'The installed copy (%1$s) is newer than the registry\'s version (%2$s), so nothing was changed.',
                    'apex_toolbox',
                ),
                $existing['version'],
                (string) $record['version'],
            ));
        }

        // Noted before, for the same reason as an update: afterwards the answer
        // is gone. install() does not deactivate, so in the usual case this is
        // belt and braces - but the new build may name a different main file.
        $wasActive = $this->activation($existing['file']);
        $wasNetworkActive = $wasActive && $this->networkActivation($existing['file']);

        $skin = $this->skin();
        $result = $this->runTakeover($record, $skin);

        // The new build names the registry in its Update URI, so from here on it
        // matches like any other managed package - and its main file is read
        // from there, in case it is not the one the old copy used.
        $managed = $result === true
            ? $this->registry->installedBySlug()[$slug] ?? null
            : null;
        $done = sprintf(
            /* translators: 1: plugin name, 2: version number. */
            __('%1$s %2$s installed over the existing copy. Updates now come from the registry.', 'apex_toolbox'),
            (string) $record['name'],
            (string) $record['version'],
        );
        if ($result === true && $managed === null) {
            $done = sprintf(
                /* translators: 1: plugin name, 2: version number. */
                __('%1$s %2$s installed over the existing copy.', 'apex_toolbox'),
                (string) $record['name'],
                (string) $record['version'],
            ) . ' ' . __(
                'The package does not name this registry in its Update URI, so it will not be updated from here.',
                'apex_toolbox',
            );
        }

        $newFile = (string) ($managed['file'] ?? $existing['file']);
        if ($wasActive && $newFile !== $existing['file']) {
            // The folder was cleared, so the old entry points at nothing. Left
            // in place it lingers beside the new one, and on multisite every
            // site that had switched the plugin on would lose it.
            $this->retireActivation($existing['file'], $newFile);
        }

        return $this->finish(
            $record,
            $result,
            $skin,
            $wasActive ? $newFile : null,
            $wasNetworkActive,
            /* translators: 1: plugin name, 2: version, 3: reason it could not be switched back on. */
            __('%1$s was replaced with %2$s but could not be reactivated: %3$s', 'apex_toolbox'),
            $done,
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
     * install() rather than upgrade(): upgrade() deactivates the plugin before it downloads, which
     * leaves the live site without it for the whole download. install() leaves it running, and the
     * package URL is the manifest's own - so it still goes through verifyAndDownload() and its
     * digest check, exactly like a fresh install.
     *
     * @param array<string, mixed> $record
     * @return bool|\WP_Error|string|null
     */
    protected function runTakeover(array $record, InstallerSkin $skin)
    {
        $slug = (string) $record['slug'];
        $guard = static fn ($source) => self::guardTakeoverFolder($source, $slug);
        add_filter('upgrader_source_selection', $guard, 20);

        try {
            return (new Plugin_Upgrader($skin))->install(
                (string) $record['download_url'],
                array('overwrite_package' => true),
            );
        } finally {
            remove_filter('upgrader_source_selection', $guard, 20);
        }
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

    /**
     * Moves every activation of $old off it, after a take-over whose build uses a different main
     * file. The current site's entry and the network one are removed only: reactivate() adds the
     * new file for those, so it is validated there and a failure is reported. On multisite, every
     * other site that had switched the plugin on is moved across directly.
     */
    protected function retireActivation(string $old, string $new): void
    {
        if (! is_multisite()) {
            update_option(
                'active_plugins',
                self::swapActivation(array_map('strval', (array) get_option('active_plugins', array())), $old, null),
            );

            return;
        }

        $network = (array) get_site_option('active_sitewide_plugins', array());
        if (isset($network[$old])) {
            unset($network[$old]);
            update_site_option('active_sitewide_plugins', $network);
        }

        $current = get_current_blog_id();
        foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $siteId) {
            switch_to_blog((int) $siteId);
            $active = array_map('strval', (array) get_option('active_plugins', array()));
            $swapped = self::swapActivation($active, $old, (int) $siteId === $current ? null : $new);
            if ($swapped !== $active) {
                update_option('active_plugins', $swapped);
            }
            restore_current_blog();
        }
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

    /**
     * What install() and takeOver() share once the upgrader has run: turn its result into a
     * message, and switch the plugin back on if it was on before.
     *
     * @param array<string, mixed> $record
     * @param bool|\WP_Error|string|null $result
     * @return array{ok:bool,message:string}
     */
    private function finish(
        array $record,
        $result,
        InstallerSkin $skin,
        ?string $reactivateFile,
        bool $networkWide,
        string $reactivationFailed,
        string $success,
    ): array {
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

        if ($reactivateFile !== null) {
            // Silently, to match core: upgrade()'s deactivation did not fire the
            // plugin's deactivation hooks, so firing its activation hooks now
            // would be a lopsided pair - an upgrade would run setup routines
            // that never had a matching teardown. update.php does the same.
            $reactivated = $this->reactivate($reactivateFile, $networkWide);

            // Reactivation can genuinely fail - the new version may raise its
            // Requires PHP, or move its main file so $file no longer exists.
            // Reporting the update as a plain success would then leave exactly
            // the state this whole path exists to prevent: updated, switched
            // off, and nobody told. The files ARE updated, so say both halves.
            if ($reactivated instanceof \WP_Error) {
                return self::failure(sprintf(
                    $reactivationFailed,
                    (string) $record['name'],
                    (string) $record['version'],
                    $reactivated->get_error_message(),
                ));
            }
        }

        return array('ok' => true, 'message' => $success);
    }
}
