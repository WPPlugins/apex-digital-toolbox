<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

/**
 * The read model the rest of the feature talks to: what is this site entitled to, and what of that
 * is already on disk.
 *
 * Installed packages are matched by the plugin's own `Update URI` header rather than by guessing a
 * folder name from the slug. That header is the same thing WordPress itself keys the update filter
 * on, so the two can never disagree, and a plugin renamed on disk still matches.
 */
class EntitlementRegistry
{
    /**
     * Pure: feed it the shape get_plugins() returns, plus the active plugin files.
     *
     * @param array<string, array<string, mixed>> $plugins
     * @param list<string> $active
     * @return array<string, array{file:string, version:string, name:string, active:bool}>
     */
    public static function matchInstalled(array $plugins, array $active = array()): array
    {
        $matched = array();
        foreach ($plugins as $file => $data) {
            $slug = PackagesConfig::slugFromUpdateUri((string) ($data['UpdateURI'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $matched[$slug] = array(
                'file' => (string) $file,
                'version' => (string) ($data['Version'] ?? ''),
                'name' => (string) ($data['Name'] ?? $slug),
                'active' => in_array((string) $file, $active, true),
            );
        }

        return $matched;
    }

    /**
     * Pure: a copy of the package installed from somewhere else, sitting in the slug's own folder.
     *
     * The Update URI stays the primary rule; this is only consulted for an entitled package that
     * rule did not match. A folder name is weaker evidence than a header - anything could be
     * sitting in it - which is why a match here is only ever offered as a take-over the user
     * confirms, never acted on silently. When the folder holds several plugin files the one named
     * after the slug wins, as it would be the main file of the package itself.
     *
     * A folder in which ANY plugin already names this registry is not a candidate: that plugin is
     * managed, and overwriting the folder would clobber it.
     *
     * @param array<string, array<string, mixed>> $plugins
     * @param list<string> $active
     * @return array{file:string, version:string, name:string, active:bool, update_uri:string}|null
     */
    public static function findUnmanagedCopy(array $plugins, string $slug, array $active = array()): ?array
    {
        if ($slug === '') {
            return null;
        }

        $inFolder = array();
        foreach ($plugins as $file => $data) {
            if (strpos((string) $file, $slug . '/') !== 0) {
                continue;
            }
            if (PackagesConfig::slugFromUpdateUri((string) ($data['UpdateURI'] ?? '')) !== '') {
                return null;
            }
            $inFolder[(string) $file] = $data;
        }

        if ($inFolder === array()) {
            return null;
        }

        ksort($inFolder);
        $file = isset($inFolder[$slug . '/' . $slug . '.php'])
            ? $slug . '/' . $slug . '.php'
            : (string) array_key_first($inFolder);
        $data = $inFolder[$file];

        return array(
            'file' => $file,
            'version' => (string) ($data['Version'] ?? ''),
            'name' => (string) ($data['Name'] ?? $slug),
            'active' => in_array($file, $active, true),
            'update_uri' => trim((string) ($data['UpdateURI'] ?? '')),
        );
    }

    /**
     * Pure: identifies the copy a take-over confirmation showed, so a copy that changed before the
     * user confirmed - a vendor update in between, a different plugin dropped into the folder - is
     * refused rather than overwritten. Covers everything the confirmation displays. It does not
     * cover the files' contents; a same-version edit on disk is indistinguishable here.
     *
     * @param array{file:string, version:string, name:string, active:bool, update_uri:string} $copy
     */
    public static function copyFingerprint(array $copy): string
    {
        return hash(
            'sha256',
            (string) wp_json_encode(array($copy['file'], $copy['name'], $copy['version'], $copy['update_uri'])),
        );
    }

    public function __construct(private readonly ManifestCache $cache)
    {
    }

    /** @return array<string, array<string, mixed>> Entitled packages, keyed by slug. */
    public function all(bool $force = false): array
    {
        return $this->cache->packages($force);
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        $all = $this->all();

        return $all[$slug] ?? null;
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->cache->status();
    }

    /**
     * Re-read the registry now, and make WordPress re-read it too.
     *
     * Core's update answer is cached for twelve hours, so refreshing only our own
     * view would leave the Plugins screen, the Updates screen and `wp plugin list`
     * all still reporting what the registry said this morning - which is what the
     * fleet's update tooling actually reads.
     */
    public function refresh(): void
    {
        $this->cache->flush();
        $this->all(true);
        delete_site_transient('update_plugins');
    }

    /**
     * Installed plugins that belong to this registry, keyed by slug.
     *
     * @return array<string, array{file:string, version:string, name:string, active:bool}>
     */
    public function installedBySlug(): array
    {
        return self::matchInstalled($this->allPlugins(), $this->activePluginFiles());
    }

    /**
     * A copy of $slug installed from elsewhere, or null. Only meaningful for a slug that
     * installedBySlug() did not match; see findUnmanagedCopy().
     *
     * @return array{file:string, version:string, name:string, active:bool, update_uri:string}|null
     */
    public function unmanagedCopyOf(string $slug): ?array
    {
        return self::findUnmanagedCopy($this->allPlugins(), $slug, $this->activePluginFiles());
    }

    /**
     * Every plugin file active on this site.
     *
     * Network-active plugins count: on multisite they run on every site, so a
     * package the network has switched on is not "installed but inactive" from
     * any single site's point of view.
     *
     * @return list<string>
     */
    protected function activePluginFiles(): array
    {
        $active = (array) get_option('active_plugins', array());

        if (is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }

        return array_values(array_map('strval', $active));
    }

    /** @return array<string, array<string, mixed>> */
    protected function allPlugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }
}
