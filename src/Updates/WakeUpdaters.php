<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

/**
 * Wakes the premium plugin and theme updaters that stay dormant under WP-CLI.
 *
 * Several vendors register their update check only in contexts WP-CLI is not in, so
 * `wp plugin list` reports no update for a plugin wp-admin shows one for, and `wp theme list` the
 * same for a theme. Two gates cover the bulk of the fleet, each opened by a constant:
 *
 * - WP_ADMIN - Crocoblock. Every Jet plugin boots its jet-dashboard only `if ( is_admin() )`, and the
 *   dashboard is what hooks `pre_set_site_transient_update_plugins`. Jupiter X is the theme case:
 *   lib/init.php requires its update-theme class only `if ( is_admin() )`, so `wp theme list` shows
 *   no update and `wp theme update jupiterx` answers "already updated" with the updater asleep.
 * - DOING_CRON - EDD Software Licensing (WPDeveloper's Essential Addons and others). The updater
 *   bails unless `current_user_can( 'manage_options' ) || DOING_CRON`. `--user=1` does not get
 *   through: WP-CLI sets the user after the vendor's init hook has already run.
 *
 * `--context=admin` is the documented lever and is NOT used: it loads wp-admin/menu.php, and
 * Elementor Pro's licence page fatals there (47 of 92 fleet sites run it).
 *
 * Both constants have to exist before regular plugins load, so this runs from an mu-plugin
 * (see MuPluginInstaller) and is required by path, before the Composer autoloader exists. Keep
 * it free of dependencies on anything else in the plugin.
 *
 * Only the commands Cue uses to read and apply updates are woken - plugin and theme list, update
 * and status, and the Toolbox's own read commands. The constants make is_admin()
 * and wp_doing_cron() true for the rest of the process, which is exactly right for an update
 * check and wrong for a system-cron `wp cron event run`, an import or a search-replace - every
 * front-end query guarded by `! is_admin()` would change behaviour.
 */
final class WakeUpdaters
{
    /** Set to false in wp-config to leave every WP-CLI command as WordPress would run it. */
    public const OPT_OUT_CONSTANT = 'APEX_TOOLBOX_WAKE_UPDATERS';

    /**
     * Called from the mu-plugin. Wakes the updaters when this process is one of the update commands.
     */
    public static function maybeWake(string $toolboxBasename): void
    {
        $isCli = defined('WP_CLI') && constant('WP_CLI');
        if (! $isCli || self::optedOut() || ! self::toolboxIsActive($toolboxBasename)) {
            return;
        }

        if (self::commandWakes(self::cliArguments())) {
            self::wake();
        }
    }

    /**
     * Whether wp-config turns the wake off (`define('APEX_TOOLBOX_WAKE_UPDATERS', false)`).
     */
    public static function optedOut(): bool
    {
        return defined(self::OPT_OUT_CONSTANT) && constant(self::OPT_OUT_CONSTANT) === false;
    }

    /**
     * Pure: whether a WP-CLI command (its positional arguments) is one that reads or applies updates.
     *
     * @param array<mixed> $args
     */
    public static function commandWakes(array $args): bool
    {
        $args = array_values(array_map('strval', $args));
        $command = $args[0] ?? '';
        $subcommand = $args[1] ?? '';

        // `apex changelog` too: EDD's updater registers its plugins_api filter behind the same gate.
        if ($command === 'apex') {
            return in_array($subcommand, array('updates', 'changelog'), true);
        }

        return in_array($command, array('plugin', 'theme'), true)
            && in_array($subcommand, array('list', 'update', 'status'), true);
    }

    /**
     * Pure: whether a WP-CLI command is one of the Toolbox's own read commands (`wp apex ...`).
     *
     * @param array<mixed> $args
     */
    public static function isApexCommand(array $args): bool
    {
        return (string) (array_values($args)[0] ?? '') === 'apex';
    }

    /**
     * The gates open in this process, for the command to report.
     *
     * Empty on the first run after the Toolbox itself updates: the mu-plugin is not there yet, so
     * that run reads the dormant picture, and says so.
     *
     * @return list<string>
     */
    public static function openGates(): array
    {
        return array_values(array_filter(
            array('WP_ADMIN', 'DOING_CRON'),
            static fn (string $name): bool => defined($name) && (bool) constant($name),
        ));
    }

    /**
     * Whether the Toolbox is active, read straight from the options.
     *
     * The mu-plugin outlives a Toolbox deactivated behind WordPress's back (a database edit, a
     * migration), and must then do nothing. Options are loaded by the time mu-plugins run.
     */
    public static function toolboxIsActive(string $basename): bool
    {
        if (in_array($basename, (array) get_option('active_plugins', array()), true)) {
            return true;
        }

        return is_multisite() && isset(((array) get_site_option('active_sitewide_plugins', array()))[$basename]);
    }

    /**
     * The running WP-CLI command's positional arguments, or none outside WP-CLI.
     *
     * @return array<mixed>
     */
    public static function cliArguments(): array
    {
        if (! class_exists('WP_CLI') || ! method_exists('WP_CLI', 'get_runner')) {
            return array();
        }

        return (array) \WP_CLI::get_runner()->arguments;
    }

    private static function wake(): void
    {
        if (! defined('WP_ADMIN')) {
            define('WP_ADMIN', true);
        }

        if (! defined('DOING_CRON')) {
            define('DOING_CRON', true);
        }
    }
}
