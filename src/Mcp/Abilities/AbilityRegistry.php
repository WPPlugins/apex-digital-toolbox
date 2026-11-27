<?php

declare(strict_types=1);

namespace ApexToolbox\Mcp\Abilities;

/**
 * Thin read-only view over the WordPress Abilities API registry — the list of every ability
 * registered by every plugin/theme/core on the site. Used by the manifest (abilities.available)
 * and by exposure-sync (to reject names that aren't registered).
 */
final class AbilityRegistry
{
    /** @return list<string> every registered ability name */
    public static function names(): array
    {
        if (! function_exists('wp_get_abilities')) {
            return array();
        }

        $names = array();
        foreach (wp_get_abilities() as $key => $ability) {
            if (is_object($ability) && method_exists($ability, 'get_name')) {
                $names[] = (string) $ability->get_name();
            } elseif (is_string($key)) {
                $names[] = $key;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Rich descriptors for every registered ability, so the manifest can tell an admin what an
     * (as-yet-unexposed) ability actually does before they approve it in Geoff.
     *
     * @return list<array{name:string,description:string,isWrite:bool,inputSchema:array<string,mixed>,outputSchema:array<string,mixed>}>
     */
    public static function describe(): array
    {
        if (! function_exists('wp_get_abilities')) {
            return array();
        }

        $out = array();
        foreach (wp_get_abilities() as $ability) {
            // wp_get_abilities() yields WP_Ability instances; guard the shape defensively.
            if (! is_object($ability) || ! method_exists($ability, 'get_name')) {
                continue;
            }

            $out[] = array(
                'name' => (string) $ability->get_name(),
                'description' => (string) $ability->get_description(),
                'isWrite' => self::isWrite((array) $ability->get_meta()),
                'inputSchema' => (array) $ability->get_input_schema(),
                'outputSchema' => (array) $ability->get_output_schema(),
            );
        }

        return $out;
    }

    /**
     * Classify an ability as a write. Prefer our own meta.apex.isWrite flag; otherwise fall back to
     * the standard Abilities API annotations (destructiveHint / readOnlyHint). Default: read.
     *
     * @param array<string,mixed> $meta
     */
    private static function isWrite(array $meta): bool
    {
        if (isset($meta['apex']['isWrite'])) {
            return (bool) $meta['apex']['isWrite'];
        }

        $annotations = isset($meta['annotations']) && is_array($meta['annotations'])
            ? $meta['annotations']
            : array();

        if (isset($annotations['destructiveHint'])) {
            return (bool) $annotations['destructiveHint'];
        }

        if (isset($annotations['readOnlyHint'])) {
            return ! (bool) $annotations['readOnlyHint'];
        }

        return false;
    }
}
