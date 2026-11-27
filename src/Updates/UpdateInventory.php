<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

/**
 * Installed plugins and themes and the update each one's updater offers - `wp apex updates list`.
 *
 * THE INVARIANT: this is a superset of `wp plugin list` / `wp theme list`, never a subset. Cue prefers
 * it, so anything it drops is an update Cue could already see and then loses. It therefore reads
 * the same source those commands read, the same way:
 *
 * 1. Refresh exactly as `wp plugin list` does (WP-CLI 2.12, CommandWithUpgrade::_list()): delete the
 *    update transient and run core's wp_update_plugins() / wp_update_themes(). The process is already
 *    woken (WakeUpdaters), so the premium updaters inject during that same check. That write is
 *    WordPress's own update cache and nothing else - the same one `wp plugin list` rewrites on every
 *    run - so this command writes nothing that command does not.
 * 2. Read get_site_transient() and apply WP-CLI's rule: `update_version` is the `new_version` of the
 *    package's `response` entry, whatever it is.
 * 3. List must-use plugins and drop-ins too, exactly as `wp plugin list` does - unchecked, no update.
 *
 * `checked` is the field `wp plugin list` lacks: true when WordPress's update state holds any opinion
 * about the package (an entry in `response` or `no_update`), whoever supplied it; false when nothing
 * answered, or when the package's own updater threw during the check.
 *
 * Do not "optimise" step 1 into reading the stored copy and running the filters in memory. 2.4.0 did,
 * and lost every wordpress.org result the moment anything had cleared the transient - which every
 * plugin install and update does.
 */
class UpdateInventory
{
    /**
     * Pure: one row per package, by WP-CLI's rules for update_version.
     *
     * @param 'plugin'|'theme' $type
     * @param array<string, array<string, mixed>> $installed Keyed by the transient's key (plugin file,
     *        or theme stylesheet); each has name, title and version.
     * @param mixed $transient The update transient.
     * @param array<string, string> $statuses Transient key => status.
     * @param list<string> $failed Names whose updater threw. Reported unchecked whatever the transient says.
     * @return list<array<string, mixed>>
     */
    public static function buildRows(string $type, array $installed, $transient, array $statuses, array $failed): array
    {
        $response = is_object($transient)
            ? (array) ($transient->response ?? array())
            : array();
        $noUpdate = is_object($transient)
            ? (array) ($transient->no_update ?? array())
            : array();

        $rows = array();
        foreach ($installed as $key => $package) {
            $key = (string) $key;
            $offer = self::toArray($response[$key] ?? null);
            $newVersion = $offer !== null
                ? (string) ($offer['new_version'] ?? '')
                : '';

            $rows[] = array(
                'type' => $type,
                'name' => (string) $package['name'],
                'title' => (string) $package['title'],
                'status' => $statuses[$key] ?? 'inactive',
                'version' => (string) $package['version'],
                'update_version' => $newVersion !== ''
                    ? $newVersion
                    : null,
                'checked' => ($offer !== null || isset($noUpdate[$key])) && ! in_array($package['name'], $failed, true),
            );
        }

        return $rows;
    }

    /**
     * Pure: rows for must-use plugins and drop-ins, as `wp plugin list` gives them.
     *
     * Nothing updates these, so they are never checked and never have an update. They are listed
     * anyway because `wp plugin list` lists them and Cue falls back to that on sites without the
     * Toolbox: leaving them out would make rows vanish from a site's inventory as the Toolbox rolls
     * out. Which of them a person should update by hand is Cue's call, not this command's.
     *
     * Mirrors Plugin_Command::get_all_items(): a must-use plugin is named without `.php` and keeps its
     * header version; a drop-in is named by its file name, `.php` included, and has no version.
     *
     * @param array<string, array<string, mixed>> $muPlugins get_mu_plugins(), keyed by file.
     * @param array<string, array<string, mixed>> $dropins get_dropins(), keyed by file.
     * @return list<array<string, mixed>>
     */
    public static function buildUnmanagedRows(array $muPlugins, array $dropins): array
    {
        $rows = array();
        foreach ($muPlugins as $file => $data) {
            $rows[] = self::unmanagedRow(
                self::pluginName((string) $file),
                (string) ($data['Name'] ?? ''),
                'must-use',
                (string) ($data['Version'] ?? ''),
            );
        }

        foreach ($dropins as $file => $data) {
            $rows[] = self::unmanagedRow((string) $file, (string) ($data['Title'] ?? ''), 'dropin', '');
        }

        return $rows;
    }

    /**
     * Pure: the name WP-CLI gives a plugin - its directory, or the file name for a single-file plugin.
     */
    public static function pluginName(string $file): string
    {
        $dir = dirname($file);

        return $dir === '.'
            ? basename($file, '.php')
            : $dir;
    }

    /** @return array<string, mixed> */
    private static function unmanagedRow(string $name, string $title, string $status, string $version): array
    {
        return array(
            'type' => 'plugin',
            'name' => $name,
            'title' => $title,
            'status' => $status,
            'version' => $version,
            'update_version' => null,
            'checked' => false,
        );
    }

    /**
     * @param list<array{owner: ?string, type: ?string, message: string}> $failures
     * @return list<string>
     */
    private static function failedOwners(array $failures, string $type): array
    {
        $owners = array();
        foreach ($failures as $failure) {
            if ($failure['type'] === $type && $failure['owner'] !== null) {
                $owners[] = $failure['owner'];
            }
        }

        return $owners;
    }

    /** @param array{owner: ?string, type: ?string, message: string} $failure */
    private static function describeFailure(array $failure): string
    {
        $who = $failure['owner'] ?? 'an update filter outside any plugin or theme';

        return $who . ': update check failed - ' . $failure['message'];
    }

    /**
     * @param mixed $entry
     * @return array<string, mixed>|null
     */
    private static function toArray($entry): ?array
    {
        return is_object($entry) || is_array($entry)
            ? (array) $entry
            : null;
    }

    private static function lastChecked(mixed $transient): ?int
    {
        return is_object($transient) && isset($transient->last_checked)
            ? (int) $transient->last_checked
            : null;
    }

    public function __construct(private readonly GuardedFilters $filters)
    {
    }

    /** @return array{packages: list<array<string, mixed>>, warnings: list<string>, checked_at: array<string, ?int>} */
    public function report(): array
    {
        // The reads happen inside the guard too: get_site_transient() runs the read filter, where a
        // vendor can throw just as it can on write.
        $read = new \stdClass();
        $failures = $this->filters->around(function () use ($read): void {
            $this->refreshCoreUpdateData();
            $read->plugins = get_site_transient('update_plugins');
            $read->themes = get_site_transient('update_themes');
        });
        $pluginTransient = $read->plugins ?? false;
        $themeTransient = $read->themes ?? false;

        $plugins = $this->plugins();
        $themes = $this->themes();

        return array(
            'packages' => array_merge(
                self::buildRows(
                    'plugin',
                    $plugins,
                    $pluginTransient,
                    $this->pluginStatuses($plugins),
                    self::failedOwners($failures, 'plugin'),
                ),
                self::buildUnmanagedRows($this->muPlugins(), $this->dropins()),
                self::buildRows(
                    'theme',
                    $themes,
                    $themeTransient,
                    $this->themeStatuses(),
                    self::failedOwners($failures, 'theme'),
                ),
            ),
            'warnings' => array_map(array(self::class, 'describeFailure'), $failures),
            'checked_at' => array(
                'plugins' => self::lastChecked($pluginTransient),
                'themes' => self::lastChecked($themeTransient),
            ),
        );
    }

    /**
     * Whether WordPress's update data says a plugin comes from wordpress.org.
     */
    public function isWporgPlugin(string $file): bool
    {
        $transient = get_site_transient('update_plugins');
        $entry = null;
        if (is_object($transient)) {
            $entry = self::toArray(((array) ($transient->response ?? array()))[$file] ?? null)
                ?? self::toArray(((array) ($transient->no_update ?? array()))[$file] ?? null);
        }

        return $entry !== null && str_starts_with((string) ($entry['id'] ?? ''), 'w.org/plugins/');
    }

    /**
     * Installed plugins, keyed by file. Each: name, title, version, and the raw header data.
     *
     * @return array<string, array<string, mixed>>
     */
    public function plugins(): array
    {
        $plugins = array();
        foreach ($this->installedPlugins() as $file => $data) {
            $plugins[(string) $file] = array(
                'name' => self::pluginName((string) $file),
                'title' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'data' => $data,
            );
        }

        return $plugins;
    }

    /**
     * What `wp plugin list` and `wp theme list` do before reading: clear the update transient and
     * make core check again, so a stale or missing transient is never what gets reported.
     */
    protected function refreshCoreUpdateData(): void
    {
        delete_site_transient('update_plugins');
        wp_update_plugins();

        delete_site_transient('update_themes');
        wp_update_themes();
    }

    /** @return array<string, array<string, mixed>> */
    protected function installedPlugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }

    /** @return array<string, array<string, mixed>> */
    protected function muPlugins(): array
    {
        if (! function_exists('get_mu_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_mu_plugins();
    }

    /** @return array<string, array<string, mixed>> */
    protected function dropins(): array
    {
        if (! function_exists('get_dropins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_dropins();
    }

    /** @return array<string, array<string, mixed>> Keyed by stylesheet; each: name, title, version. */
    protected function themes(): array
    {
        $themes = array();
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[(string) $stylesheet] = array(
                'name' => (string) $stylesheet,
                'title' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
            );
        }

        return $themes;
    }

    /**
     * @param array<string, array<string, mixed>> $plugins
     * @return array<string, string>
     */
    private function pluginStatuses(array $plugins): array
    {
        $statuses = array();
        foreach (array_keys($plugins) as $file) {
            $statuses[$file] = match (true) {
                is_multisite() && is_plugin_active_for_network($file) => 'active-network',
                is_plugin_active($file) => 'active',
                default => 'inactive',
            };
        }

        return $statuses;
    }

    /** @return array<string, string> */
    private function themeStatuses(): array
    {
        $statuses = array(get_stylesheet() => 'active');
        if (get_template() !== get_stylesheet()) {
            $statuses[get_template()] = 'parent';
        }

        return $statuses;
    }
}
