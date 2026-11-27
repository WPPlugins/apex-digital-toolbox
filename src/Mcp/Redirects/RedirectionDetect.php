<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Redirects;

/**
 * Detects the Redirection plugin (johngodley/redirection).
 *
 * The redirect abilities are registered only when this reports active, so a site without the
 * plugin never advertises tools it cannot service. Mirrors the SEO Detect gating.
 *
 * Red_Item is the model the whole feature is built on, so its presence - not the plugin's
 * constant - is what we key off: it is what would actually break if the plugin were restructured.
 */
final class RedirectionDetect
{
    public static function isActive(): bool
    {
        return class_exists('Red_Item') && class_exists('Red_Group');
    }

    /**
     * Installed plugin version, or null when unavailable. Reported by the list ability so an agent
     * (and our own logs) can tell which plugin build produced a given response.
     */
    public static function version(): ?string
    {
        if (! defined('REDIRECTION_VERSION')) {
            return null;
        }

        return (string) constant('REDIRECTION_VERSION');
    }

    /**
     * Whether the plugin's tables are actually present. Redirection installs its schema on
     * activation; if the plugin is present but was never activated (or an upgrade is mid-flight)
     * every query would fail with a confusing SQL error instead of a clean message.
     */
    public static function isInstalled(): bool
    {
        global $wpdb;

        if (! self::isActive()) {
            return false;
        }

        $table = $wpdb->prefix . 'redirection_items';

        // esc_like matters: LIKE treats `_` as a single-character wildcard, and the default `wp_`
        // prefix is full of them. Unescaped, `wptests_redirection_items` also matches tables like
        // `wptestsXredirectionYitems`; get_var returns only the first row, so an unrelated table
        // sorting first would make the `=== $table` check below report the schema as missing.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        // phpcs:enable

        return $found === $table;
    }
}
