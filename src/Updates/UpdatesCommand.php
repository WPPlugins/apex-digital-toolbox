<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

/**
 * `wp apex updates list` - the plugin and theme inventory Cue reads, including the premium updates
 * `wp plugin list` cannot see.
 *
 * Writes nothing `wp plugin list` does not: it refreshes WordPress's own update cache the same way that
 * command does, and nothing else, so it is as safe as `wp plugin list` over Cue's transport.
 * A site without this command (an older Toolbox) makes WP-CLI say "'apex' is not a registered wp
 * command", which is Cue's cue to fall back to `wp plugin list`.
 */
class UpdatesCommand
{
    /**
     * Pure: the response envelope.
     *
     * @param array<string, mixed> $report UpdateInventory::report(): packages, warnings, checked_at.
     * @param list<string> $woke
     * @param bool $optedOut Whether wp-config turns the wake off, so an empty $woke is a choice.
     * @return array<string, mixed>
     */
    public static function envelope(string $toolboxVersion, array $woke, array $report, bool $optedOut = false): array
    {
        $warnings = $report['warnings'];
        if ($woke === array()) {
            array_unshift(
                $warnings,
                $optedOut
                    ? 'Premium updaters were not woken: this site turns the wake off with '
                        . 'APEX_TOOLBOX_WAKE_UPDATERS. Vendors that only check in wp-admin or WP-Cron are '
                        . 'unchecked.'
                    : 'Premium updaters were not woken (the Toolbox mu-plugin is not installed yet), so vendors '
                        . 'that only check in wp-admin or WP-Cron are unchecked. It is installed on the next '
                        . 'admin or cron request.',
            );
        }

        return array(
            'toolbox_version' => $toolboxVersion,
            'woke' => $woke,
            'warnings' => $warnings,
            'checked_at' => $report['checked_at'],
            'packages' => $report['packages'],
        );
    }

    public function __construct(private readonly UpdateInventory $inventory)
    {
    }

    /**
     * Lists installed plugins and themes with the update each one's updater offers.
     *
     * Premium updaters that only register in wp-admin or WP-Cron are woken for this command by the
     * Toolbox's mu-plugin. Always a superset of `wp plugin list`: it runs the same update check that
     * command runs, then reports every package WordPress holds an opinion about.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : `json` prints the whole response; `table` prints the packages for a person to read.
     * ---
     * default: json
     * options:
     *   - json
     *   - table
     * ---
     *
     * ## RESPONSE
     *
     * toolbox_version, woke (the gates opened: WP_ADMIN, DOING_CRON), warnings, checked_at (when
     * core last asked wordpress.org, per scope) and packages. Each package has type (plugin or
     * theme), name, title, status, version, update_version (null when none) and checked (false
     * when no updater gave any opinion about it, or its updater threw).
     *
     * ## EXAMPLES
     *
     *     wp apex updates list --format=json
     *
     * @subcommand list
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function listUpdates(array $args, array $assocArgs): void
    {
        $envelope = self::envelope(
            $this->toolboxVersion(),
            WakeUpdaters::openGates(),
            $this->inventory->report(),
            WakeUpdaters::optedOut(),
        );

        if (($assocArgs['format'] ?? 'json') === 'table') {
            foreach ($envelope['warnings'] as $warning) {
                \WP_CLI::warning($warning);
            }

            $rows = array_map(static function (array $row): array {
                $row['update_version'] ??= '';
                $row['checked'] = $row['checked']
                    ? 'yes'
                    : 'no';

                return $row;
            }, $envelope['packages']);
            \WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0] ?? array('name' => '')));

            return;
        }

        \WP_CLI::line((string) wp_json_encode($envelope));
    }

    private function toolboxVersion(): string
    {
        $header = get_file_data(APEX_TOOLBOX_PLUGIN_PATH . 'apex-digital-toolbox.php', array('Version' => 'Version'));

        return (string) $header['Version'];
    }
}
