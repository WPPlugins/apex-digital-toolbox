<?php

declare(strict_types=1);

namespace ApexToolbox\Packages;

use ApexToolbox\Lib\Filter;

/**
 * The WordPress glue that makes an entitled private plugin behave like any other plugin.
 *
 * Updates go through the WP 5.8+ per-plugin mechanism rather than by filtering the update
 * transient wholesale: each private plugin's header declares
 * `Update URI: https://cue.limelightdigital.co.nz/packages/<slug>`, and core then calls
 * `update_plugins_<that host>` for that plugin alone. Two reasons that matters. It cannot collide
 * with any other plugin's updater, and - the security half - a plugin carrying a foreign Update URI
 * can never be updated from wordpress.org, so a public plugin registered under the same slug cannot
 * be served in place of a private one.
 *
 * Every method here is thin. The decisions live in pure statics beside them so the whole contract
 * can be tested without HTTP, without an upgrader and without the admin.
 */
class UpdateController
{
    /**
     * Pure: manifest record -> the array core expects.
     *
     * Core supplies `id`, `plugin` and `new_version` itself, so they are deliberately absent here.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function buildUpdatePayload(array $record, string $pluginFile): array
    {
        return array(
            'slug' => (string) $record['slug'],
            'version' => (string) $record['version'],
            'url' => self::homepageFor($record),
            'package' => (string) $record['download_url'],
            'tested' => (string) $record['tested'],
            'requires' => (string) $record['requires'],
            'requires_php' => (string) $record['requires_php'],
            'upgrade_notice' => (string) $record['upgrade_notice'],
        );
    }

    /**
     * Pure: manifest record -> the object install_plugin_information() renders.
     *
     * The empty rating/installs fields are supplied deliberately rather than left out: the modal
     * reads them unconditionally and would emit notices without them.
     *
     * @param array<string, mixed> $record
     */
    public static function buildInformation(array $record): object
    {
        $slug = (string) $record['slug'];

        return (object) array(
            'name' => (string) $record['name'],
            'slug' => $slug,
            'version' => (string) $record['version'],
            'author' => (string) $record['author'],
            'author_profile' => '',
            'homepage' => self::homepageFor($record),
            'requires' => (string) $record['requires'],
            'requires_php' => (string) $record['requires_php'],
            'tested' => (string) $record['tested'],
            'last_updated' => (string) $record['last_updated'],
            'downloaded' => 0,
            'active_installs' => 0,
            'num_ratings' => 0,
            'rating' => 0,
            'ratings' => array(),
            'contributors' => array(),
            'banners' => array(),
            'external' => true,
            'download_link' => (string) $record['download_url'],
            'sections' => array(
                'description' => (string) $record['description'],
                'changelog' => (string) $record['changelog'],
            ),
        );
    }

    /**
     * Whether the stored URL is the one the manifest currently offers and still in date. Pure.
     *
     * @param array<string, mixed> $record
     */
    public static function needsFreshUrl(string $package, array $record, int $now): bool
    {
        if ($package !== (string) $record['download_url']) {
            return true;
        }

        $expires = $record['download_expires'];

        // A minute of headroom: the download follows this hook, not the other way round.
        return is_int($expires) && $expires <= $now + MINUTE_IN_SECONDS;
    }

    /**
     * Where "View details" and the update row link to: the package's own home page when it has one,
     * otherwise its registry address, which is at least somewhere meaningful.
     *
     * @param array<string, mixed> $record
     */
    private static function homepageFor(array $record): string
    {
        $homepage = (string) $record['homepage'];

        return $homepage !== ''
            ? $homepage
            : PackagesConfig::updateUriFor((string) $record['slug']);
    }

    public function __construct(
        private readonly EntitlementRegistry $registry,
        private readonly PackagesConfig $config,
    ) {
    }

    /**
     * Answer core's per-plugin update question.
     *
     * Fires once for EVERY installed plugin whose Update URI names this host, so it must answer
     * from cache and must never make a request of its own - warming the cache is the first call's
     * job and every later call in the request is memoised.
     *
     * Returns a payload even when the installed version is current: core compares versions itself
     * and files the answer under `no_update`, which is what keeps the auto-update toggle and the
     * "View details" link on the Plugins screen. Returning false there would quietly remove both.
     *
     * @param mixed $update
     * @param mixed $pluginData
     * @param mixed $pluginFile
     * @param mixed $locales
     * @return mixed
     */
    #[Filter('update_plugins_' . PackagesConfig::REGISTRY_HOST)]
    public function filterUpdate($update, $pluginData, $pluginFile, $locales)
    {
        if (! $this->config->isEnabled() || ! is_array($pluginData)) {
            return $update;
        }

        $slug = PackagesConfig::slugFromUpdateUri((string) ($pluginData['UpdateURI'] ?? ''));
        if ($slug === '') {
            return $update;
        }

        $record = $this->registry->get($slug);
        if ($record === null) {
            // Not entitled - either revoked, or never granted. Fail-soft: the plugin keeps running,
            // it simply stops being offered updates. Nothing is deactivated or removed.
            return $update;
        }

        return self::buildUpdatePayload($record, (string) $pluginFile);
    }

    /**
     * Serve the "View details" modal from the manifest.
     *
     * plugins_api also backs the Add Plugins browse and search screens, so anything that is not a
     * plugin_information request for one of our slugs must be returned untouched.
     *
     * @param mixed $result
     * @param mixed $action
     * @param mixed $args
     * @return mixed
     */
    #[Filter('plugins_api')]
    public function filterPluginsApi($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ! $this->config->isEnabled()) {
            return $result;
        }

        $slug = is_object($args)
            ? (string) ($args->slug ?? '')
            : '';
        if ($slug === '') {
            return $result;
        }

        $record = $this->registry->get($slug);

        return $record === null
            ? $result
            : self::buildInformation($record);
    }

    /**
     * Replace a signed package URL that expired while it sat in the update transient.
     *
     * Core caches the update check for up to twelve hours, and the URL it stored carries a
     * signature and an expiry. Without this, "Update now" on a site that last checked this morning
     * would be refused by the registry - a failure that looks like a broken plugin rather than a
     * stale link. Runs BEFORE download_package(), so the firewall's download window arms on the
     * fresh URL.
     *
     * @param mixed $options
     * @return mixed
     */
    #[Filter('upgrader_package_options')]
    public function refreshPackageUrl($options)
    {
        if (! is_array($options) || ! $this->config->isEnabled()) {
            return $options;
        }

        $package = (string) ($options['package'] ?? '');
        if (! PackageRecord::isRegistryUrl($package, $this->config->registryBase())) {
            return $options;
        }

        $slug = $this->slugForPackageUrl($package);
        if ($slug === '') {
            return $options;
        }

        $record = $this->registry->get($slug);
        if ($record === null || ! self::needsFreshUrl($package, $record, time())) {
            return $options;
        }

        $refreshed = $this->registry->all(true)[$slug] ?? null;
        if ($refreshed !== null) {
            $options['package'] = (string) $refreshed['download_url'];
        }

        return $options;
    }

    /**
     * Download an entitled package and refuse it unless it hashes to what the manifest published.
     *
     * Priority 20 so the firewall (priority 1) has already armed its download window on this exact
     * URL - the request below rides that window, which is what lets a staging clone install a
     * private plugin without the registry's download endpoint being allowlisted at all.
     *
     * This filter fires for EVERY package the site ever installs, including wordpress.org and every
     * third-party vendor, so the guard at the top is load-bearing: anything that is not ours, and
     * anything another filter has already answered, passes straight through untouched.
     *
     * @param mixed $reply
     * @param mixed $package
     * @param mixed $upgrader
     * @param mixed $hookExtra
     * @return mixed
     */
    #[Filter('upgrader_pre_download', 20)]
    public function verifyAndDownload($reply, $package, $upgrader, $hookExtra)
    {
        // Not ours, or already an error: untouched. This filter runs for every
        // package the site ever installs, so anything else must pass straight
        // through.
        if (
            $reply instanceof \WP_Error
            || ! is_string($package)
            || ! PackageRecord::isRegistryUrl($package, $this->config->registryBase())
        ) {
            return $reply;
        }

        $slug = $this->slugForPackageUrl($package);
        $record = $slug === ''
            ? null
            : $this->registry->get($slug);

        // A registry URL we hold no manifest record for cannot be verified, so it
        // is refused rather than handed back to core - which would download and
        // install it with no digest at all. Reached with a stale update transient
        // after a revocation, a removed token, or a cache past its grace period:
        // exactly the moments when there is no trusted digest to check against,
        // and so exactly when failing open would be worst.
        if ($record === null) {
            return new \WP_Error(
                'apex_packages_unknown_package',
                __('That package is no longer offered by the registry.', 'apex_toolbox')
                . ' ' . __('Refresh the package list and try again.', 'apex_toolbox'),
            );
        }

        // Another filter may have supplied the file already - a download cache or
        // a host's own mirror. It still has to match the published digest: this
        // feature promises every private package is verified before it is
        // unpacked, and a file arriving by a different route is not an exemption.
        $supplied = is_string($reply) && $reply !== '';
        $file = $supplied
            ? $reply
            : $this->downloadPackage($package);
        if ($file instanceof \WP_Error || ! is_string($file)) {
            return $file;
        }

        if (! Checksum::verify($file, (string) $record['sha256'])) {
            // Only clean up a file we fetched ourselves. One handed to us by
            // another filter belongs to that filter, and may be its cache.
            if (! $supplied) {
                wp_delete_file($file);
            }
            // Worth a log line in its own right. Every other install failure is a
            // network or filesystem problem; this one says the bytes served did
            // not match what the registry published.
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[Apex Packages] Refused %s %s: the download did not match the published fingerprint.',
                (string) $record['slug'],
                (string) $record['version'],
            ));

            return new \WP_Error(
                'apex_packages_checksum_mismatch',
                sprintf(
                    /* translators: %s: plugin name. */
                    __(
                        '%s was not installed: the download did not match the registry fingerprint.',
                        'apex_toolbox',
                    ),
                    (string) $record['name'],
                ),
            );
        }

        return $file;
    }

    /** @return string|\WP_Error */
    protected function downloadPackage(string $url)
    {
        if (! function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        return download_url($url, 300);
    }

    /**
     * The entitled slug whose current download URL this is, or ''.
     *
     * Matched against the manifest rather than parsed out of the URL: the download path is the
     * registry's business, and a URL we cannot account for is one we should not be fetching.
     */
    private function slugForPackageUrl(string $package): string
    {
        foreach ($this->registry->all() as $slug => $record) {
            if ((string) $record['download_url'] === $package) {
                return (string) $slug;
            }
        }

        // Fall back to the path, so a URL that has merely been re-signed since the manifest was
        // cached is still recognised as ours and re-minted rather than fetched unverified.
        $path = (string) (wp_parse_url($package, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path)));
        $index = array_search('download', $segments, true);
        if ($index !== false && isset($segments[$index + 1])) {
            $slug = (string) $segments[$index + 1];

            return $this->registry->get($slug) !== null
                ? $slug
                : '';
        }

        return '';
    }
}
