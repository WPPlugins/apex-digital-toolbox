<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\OAuth;

/**
 * The fixed set of OAuth scopes Geoff can be granted on a client site.
 *
 * Token scope constrains blast radius: even though Geoff authenticates as the shared agency
 * admin user, a leaked token is bounded to the scopes it carries.
 */
final class Scopes
{
    public const READ_ABILITIES = 'read:abilities';
    public const WRITE_SEO = 'write:seo';
    public const WRITE_SEO_SETTINGS = 'write:seo-settings';
    public const WRITE_CACHE = 'write:cache';
    public const WRITE_TRANSIENTS = 'write:transients';
    public const ADMIN_SECURITY = 'admin:security';
    public const ADMIN_EXPOSURE = 'admin:exposure';

    /** @return array<string, string> scope => human-readable description (shown on the consent screen) */
    public static function all(): array
    {
        return array(
            self::READ_ABILITIES => __('Read site abilities, logs, SEO meta and health probes', 'apex_toolbox'),
            self::WRITE_SEO => __('Update SEO meta (title, description, canonical, robots)', 'apex_toolbox'),
            self::WRITE_SEO_SETTINGS => __('Change site-wide SEO settings (indexing, templates)', 'apex_toolbox'),
            self::WRITE_CACHE => __('Flush caches and regenerate critical CSS', 'apex_toolbox'),
            self::WRITE_TRANSIENTS => __('Clear plugin transients', 'apex_toolbox'),
            self::ADMIN_SECURITY => __('Apply security lockdowns (IP allowlist, maintenance mode)', 'apex_toolbox'),
            self::ADMIN_EXPOSURE => __('Manage which abilities are exposed to Geoff', 'apex_toolbox'),
        );
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function isValid(string $scope): bool
    {
        return array_key_exists($scope, self::all());
    }

    /**
     * Reduce a requested scope set to only the scopes that exist. Unknown scopes are dropped.
     *
     * @param list<string> $requested
     * @return list<string>
     */
    public static function filter(array $requested): array
    {
        return array_values(array_filter($requested, static fn (string $s): bool => self::isValid($s)));
    }
}
