<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

/**
 * A plugin's changelog as HTML, from wherever its vendor publishes one - `wp apex changelog get`.
 *
 * Every vendor quirk lives here so Cue never has to learn one. HTML, never plain text: Cue's
 * parseChangelog() strips the tags itself and splits releases on the headings. Routes, in order:
 *
 * 1. `vendor-api` - Crocoblock publishes a static, unauthenticated JSON file per plugin. Their
 *    plugins_api filter needs admin_init, which WP_ADMIN alone does not fire.
 * 2. `plugins_api` - wordpress.org plugins, and any vendor whose information filter registers under
 *    WP-CLI (Elementor Pro does, and so does this Toolbox's own package registry).
 * 3. `readme` - the installed plugin's own readme.txt. Last, because it describes the version that
 *    is installed and so can never say what the pending update changes.
 *
 * Gravity Forms, WPMU DEV and Admin Menu Editor Pro answer none of these reliably; they come back
 * with a null changelog rather than a wrong one.
 */
class ChangelogResolver
{
    public const CROCOBLOCK_CHANGELOG_URL = 'https://crocoblock.com/wp-content/uploads/jet-changelog/%s.json';

    public const SOURCE_VENDOR_API = 'vendor-api';
    public const SOURCE_PLUGINS_API = 'plugins_api';
    public const SOURCE_README = 'readme';

    /** Run from WP-CLI, never a page view, and a changelog is one static file. */
    private const TIMEOUT = 15;

    /**
     * Pure: whether a plugin is Crocoblock's, from its header.
     *
     * @param array<string, mixed> $pluginData
     */
    public static function isCrocoblock(array $pluginData): bool
    {
        foreach (array('AuthorURI', 'PluginURI') as $key) {
            $host = strtolower((string) wp_parse_url((string) ($pluginData[$key] ?? ''), PHP_URL_HOST));
            if ($host === 'crocoblock.com' || str_ends_with($host, '.crocoblock.com')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pure: version and changelog HTML from Crocoblock's `{current_version, changelog}` JSON.
     *
     * @return array{version: ?string, changelog: string}|null
     */
    public static function parseCrocoblock(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! is_string($decoded['changelog'] ?? null) || trim($decoded['changelog']) === '') {
            return null;
        }

        $version = $decoded['current_version'] ?? null;

        return array(
            'version' => is_scalar($version) && (string) $version !== '' ? (string) $version : null,
            'changelog' => $decoded['changelog'],
        );
    }

    /**
     * Pure: version and changelog from a plugins_api() answer.
     *
     * With nothing registered for a slug, plugins_api() asks wordpress.org - which answers for
     * whatever public plugin holds that slug. For a premium plugin that is somebody else's
     * changelog, so an answer from wordpress.org is only taken for a plugin WordPress already knows
     * to be hosted there.
     *
     * @param mixed $info
     * @return array{version: ?string, changelog: string}|null
     */
    public static function fromInformation($info, bool $wporg): ?array
    {
        if (! is_object($info) || $info instanceof \WP_Error) {
            return null;
        }

        $changelog = ((array) ($info->sections ?? array()))['changelog'] ?? null;
        if (! is_string($changelog) || trim($changelog) === '') {
            return null;
        }

        $download = strtolower((string) wp_parse_url((string) ($info->download_link ?? ''), PHP_URL_HOST));
        if (! $wporg && $download === 'downloads.wordpress.org') {
            return null;
        }

        $version = $info->version ?? null;

        return array(
            'version' => is_scalar($version) && (string) $version !== '' ? (string) $version : null,
            'changelog' => $changelog,
        );
    }

    /**
     * Pure: the `== Changelog ==` section of a readme.txt, as the HTML wordpress.org would render.
     *
     * `= 1.2.3 =` becomes a heading (which is what Cue splits releases on), `*` and `-` lines become
     * list items, anything else a paragraph. Everything is escaped - a readme is plain text.
     */
    public static function readmeChangelogHtml(string $readme): ?string
    {
        if (preg_match('/^==\s*Changelog\s*==\s*$(.*?)(?=^==[^=]|\z)/ims', $readme, $match) !== 1) {
            return null;
        }

        $html = '';
        $inList = false;
        $lines = preg_split('/\R/', $match[1]);
        foreach (is_array($lines) ? $lines : array() as $line) {
            $line = trim($line);
            $item = preg_match('/^[*-]\s+(.*)$/', $line, $bullet) === 1;

            if ($inList && ! $item) {
                $html .= "</ul>\n";
                $inList = false;
            }

            if ($line === '') {
                continue;
            }

            if (preg_match('/^=+\s*(.*?)\s*=+$/', $line, $heading) === 1) {
                $html .= '<h4>' . esc_html($heading[1]) . "</h4>\n";
            } elseif ($item) {
                $html .= ($inList ? '' : "<ul>\n") . '<li>' . esc_html($bullet[1]) . "</li>\n";
                $inList = true;
            } else {
                $html .= '<p>' . esc_html($line) . "</p>\n";
            }
        }

        if ($inList) {
            $html .= "</ul>\n";
        }

        return trim($html) === ''
            ? null
            : $html;
    }

    /**
     * @param array<string, mixed> $pluginData get_plugins() header data; empty when not installed.
     * @param bool $wporg Whether WordPress's update data says the plugin comes from wordpress.org.
     * @param string|null $pluginFile The installed plugin's file, for its readme.
     * @return array{version: ?string, changelog: ?string, source: ?string}
     */
    public function resolve(string $slug, array $pluginData, bool $wporg, ?string $pluginFile): array
    {
        if (self::isCrocoblock($pluginData)) {
            $found = $this->fromCrocoblock($slug);
            if ($found !== null) {
                return $found + array('source' => self::SOURCE_VENDOR_API);
            }
        }

        $found = self::fromInformation($this->pluginInformation($slug), $wporg);
        if ($found !== null) {
            return $found + array('source' => self::SOURCE_PLUGINS_API);
        }

        $readme = $pluginFile === null
            ? null
            : $this->readReadme($pluginFile);
        $changelog = $readme === null
            ? null
            : self::readmeChangelogHtml($readme);
        if ($changelog !== null) {
            return array(
                'version' => isset($pluginData['Version']) ? (string) $pluginData['Version'] : null,
                'changelog' => $changelog,
                'source' => self::SOURCE_README,
            );
        }

        return array('version' => null, 'changelog' => null, 'source' => null);
    }

    /** @return array{version: ?string, changelog: string}|null */
    protected function fromCrocoblock(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9_-]+$/', $slug) !== 1) {
            return null;
        }

        $response = $this->fetch(sprintf(self::CROCOBLOCK_CHANGELOG_URL, $slug));
        if ($response instanceof \WP_Error || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return self::parseCrocoblock((string) wp_remote_retrieve_body($response));
    }

    /** @return array<string, mixed>|\WP_Error */
    protected function fetch(string $url)
    {
        return wp_remote_get($url, array('timeout' => self::TIMEOUT));
    }

    /** @return mixed */
    protected function pluginInformation(string $slug)
    {
        if (! function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        return plugins_api('plugin_information', array('slug' => $slug, 'fields' => array('sections' => true)));
    }

    protected function readReadme(string $pluginFile): ?string
    {
        $dir = dirname(WP_PLUGIN_DIR . '/' . $pluginFile);
        if ($dir === WP_PLUGIN_DIR) {
            return null;
        }

        foreach (array('readme.txt', 'README.txt', 'readme.md', 'README.md') as $name) {
            if (is_readable($dir . '/' . $name)) {
                return (string) file_get_contents($dir . '/' . $name);
            }
        }

        return null;
    }
}
