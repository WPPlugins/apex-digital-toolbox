<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

/**
 * `wp apex changelog get <slug>` - one plugin's changelog, fetched when somebody asks for it.
 *
 * Kept out of `wp apex updates list` because changelogs are large (Elementor Pro's is 244 KB) and
 * most are never opened. Read-only.
 */
class ChangelogCommand
{
    public function __construct(
        private readonly ChangelogResolver $resolver,
        private readonly UpdateInventory $inventory,
    ) {
    }

    /**
     * Gets a plugin's changelog as HTML.
     *
     * Tried in order: the vendor's own feed (vendor-api), plugins_api (wordpress.org and vendors that
     * answer it), then the installed plugin's readme.txt (readme). `changelog` is null when none of
     * them has one.
     *
     * ## OPTIONS
     *
     * <slug>
     * : The plugin's name - its directory, as `wp plugin list` shows it.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: json
     * options:
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp apex changelog get jet-smart-filters --format=json
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function get(array $args, array $assocArgs): void
    {
        \WP_CLI::line((string) wp_json_encode($this->lookup((string) $args[0])));
    }

    /**
     * The changelog for one plugin: `{name, version, changelog, source}`.
     *
     * Private on purpose: WP-CLI registers every public method as a subcommand.
     *
     * @return array{name: string, version: ?string, changelog: ?string, source: ?string}
     */
    private function lookup(string $slug): array
    {
        $file = null;
        foreach ($this->inventory->plugins() as $pluginFile => $plugin) {
            if ($plugin['name'] === $slug) {
                $file = $pluginFile;
                $data = $plugin['data'];

                break;
            }
        }

        $found = $this->resolver->resolve(
            $slug,
            $data ?? array(),
            $file !== null ? $this->inventory->isWporgPlugin($file) : false,
            $file,
        );

        return array('name' => $slug) + $found;
    }
}
