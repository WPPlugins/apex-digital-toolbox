<?php

declare(strict_types=1);

namespace ApexToolbox\Support;

/**
 * Single source of truth for the "is this site new enough for the MCP layer?" decision.
 *
 * The MCP server, OAuth provider and Abilities API integration all require the WordPress
 * 7.0 Abilities/MCP infrastructure. This gate is consulted at three points (per the plan):
 * plugin bootstrap, the manifest endpoint, and the OAuth authorize endpoint.
 */
final class WordPressVersionGate
{
    /** Minimum WordPress version that ships the Abilities API + MCP infrastructure. */
    public const MINIMUM_WP_VERSION = '7.0';

    /** @param string|null $version Explicit version (for tests); defaults to the live site version. */
    public static function isSupported(?string $version = null): bool
    {
        $version ??= self::currentVersion();

        // A bare numeric compare so 7.0, 7.0.1, 7.1, 8.x all pass and 6.x fails.
        return version_compare($version, self::MINIMUM_WP_VERSION, '>=');
    }

    public static function currentVersion(): string
    {
        // get_bloginfo('version') is the canonical WP version string.
        return (string) get_bloginfo('version');
    }
}
