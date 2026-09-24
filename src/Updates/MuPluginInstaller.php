<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

use ApexToolbox\Lib\Action;

/**
 * Keeps the mu-plugin that wakes premium updaters (WakeUpdaters) in place.
 *
 * The file in mu-plugins is a loader only: it requires WakeUpdaters from the Toolbox itself, so the
 * logic ships with every Toolbox release and the loader almost never has to change. It is rewritten
 * whenever its content differs from what this release would write, and removed when the Toolbox is
 * deactivated.
 *
 * Maintained from admin, cron and WP-CLI requests - never on a front-end page view, and never from
 * the `wp apex` commands, which Cue runs as reads. Until it is in place, `wp apex updates list` reports `woke: []`.
 */
class MuPluginInstaller
{
    public const FILE_NAME = 'apex-toolbox-wake-updaters.php';

    /** First line after `<?php`. Identifies the file as ours before anything deletes it. */
    public const MARKER = '// Apex Digital Toolbox: wake premium updaters under WP-CLI.';

    /**
     * Pure: the loader's source.
     *
     * The Toolbox's directory is baked in, not an absolute path: a site cloned to another host keeps
     * working, because WP_PLUGIN_DIR is resolved on the copy.
     */
    public static function loaderSource(string $toolboxBasename): string
    {
        $wakeFile = dirname($toolboxBasename) . '/src/Updates/WakeUpdaters.php';

        return "<?php\n"
            . self::MARKER . "\n"
            . "/**\n"
            . " * Plugin Name: Apex Toolbox - wake premium updaters under WP-CLI\n"
            . " * Description: Lets `wp plugin list` and `wp plugin update` see premium-plugin updates"
            . " (Crocoblock, WPDeveloper and others). Managed by the Apex Digital Toolbox, which"
            . " rewrites this file when it changes and deletes it on deactivation.\n"
            . " */\n"
            . "\n"
            . "if (! defined('ABSPATH') || ! defined('WP_CLI') || ! WP_CLI) {\n"
            . "    return;\n"
            . "}\n"
            . "\n"
            . '$apexToolboxWakeFile = WP_PLUGIN_DIR . ' . var_export('/' . $wakeFile, true) . ";\n"
            . "if (is_readable(\$apexToolboxWakeFile)) {\n"
            . "    require_once \$apexToolboxWakeFile;\n"
            . '    \\ApexToolbox\\Updates\\WakeUpdaters::maybeWake(' . var_export($toolboxBasename, true) . ");\n"
            . "}\n"
            . "unset(\$apexToolboxWakeFile);\n";
    }

    public static function targetPath(): string
    {
        return WPMU_PLUGIN_DIR . '/' . self::FILE_NAME;
    }

    /**
     * Deactivation hook. Removes the loader, but only if the file there is ours.
     */
    public static function removeInstalled(): void
    {
        self::removeFrom(self::targetPath());
    }

    public static function removeFrom(string $target): void
    {
        // A local file in mu-plugins, never a URL.
        // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
        if (is_file($target) && str_contains((string) file_get_contents($target), self::MARKER)) {
            wp_delete_file($target);
        }
    }

    #[Action('init')]
    public function maintain(): void
    {
        if (! $this->isMaintenanceContext()) {
            return;
        }

        $this->ensure($this->target(), self::loaderSource($this->toolboxBasename()));
    }

    /**
     * Writes the loader if it is missing or out of date. Returns whether it is now in place.
     *
     * Written to a temporary file and renamed over the target, so a WP-CLI process starting
     * alongside can never include a half-written mu-plugin.
     */
    public function ensure(string $target, string $source): bool
    {
        // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
        if (is_file($target) && file_get_contents($target) === $source) {
            return true;
        }

        $dir = dirname($target);
        if (! wp_mkdir_p($dir) || ! wp_is_writable($dir)) {
            return false;
        }

        $temp = $target . '.' . wp_generate_password(8, false) . '.tmp';

        // Direct filesystem access is deliberate: WP_Filesystem may demand FTP credentials, and there
        // is nobody to supply them on a cron or WP-CLI run. This is how caching plugins write drop-ins.
        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
        if (file_put_contents($temp, $source) === false) {
            // A partial write can still leave the file behind; this runs on every admin request, so
            // a persistent failure must not accumulate them.
            wp_delete_file($temp);

            return false;
        }

        // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename
        if (! rename($temp, $target)) {
            wp_delete_file($temp);

            return false;
        }

        return true;
    }

    /**
     * Admin, cron and WP-CLI - except the Toolbox's own `wp apex` commands, which Cue runs as reads and
     * must stay so: Cue runs them against production over a transport that refuses writes. Checked
     * first, because a woken `wp apex` run is is_admin() and wp_doing_cron() as well.
     */
    protected function isMaintenanceContext(): bool
    {
        $isCli = defined('WP_CLI') && constant('WP_CLI');
        if ($isCli && WakeUpdaters::isApexCommand(WakeUpdaters::cliArguments())) {
            return false;
        }

        return $isCli || is_admin() || wp_doing_cron();
    }

    protected function target(): string
    {
        return self::targetPath();
    }

    protected function toolboxBasename(): string
    {
        return plugin_basename(APEX_TOOLBOX_PLUGIN_PATH . 'apex-digital-toolbox.php');
    }
}
