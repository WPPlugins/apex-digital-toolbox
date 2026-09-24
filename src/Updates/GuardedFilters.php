<?php

declare(strict_types=1);

namespace ApexToolbox\Updates;

use ReflectionFunction;
use ReflectionMethod;

/**
 * Wraps every callback on the update hooks so one that throws cannot take the whole check down.
 *
 * Used around core's own update check: a vendor's updater throwing inside
 * `pre_set_site_transient_update_plugins` would otherwise abort wp_update_plugins() and lose every
 * other plugin's answer with it. A callback that throws is skipped - its filter returns the value it
 * was given - and recorded with the plugin or theme its code lives in, so the caller can mark that
 * package unchecked instead of reporting a partial answer as a complete one.
 *
 * Only exceptions and Errors are catchable. A true fatal still ends the process, as it would under
 * `wp plugin list`.
 */
class GuardedFilters
{
    /**
     * What threw during the current around() call, one entry per vendor and message.
     *
     * @var list<array{owner: ?string, type: ?string, message: string}>
     */
    private array $failures = array();

    /**
     * The plugin or theme a callback's code lives in, from the file that defines it.
     *
     * @return array{owner: ?string, type: ?string}
     */
    public static function ownerOf(mixed $callback): array
    {
        $file = self::fileOf($callback);

        return $file === null
            ? array('owner' => null, 'type' => null)
            : self::ownerOfFile($file, WP_PLUGIN_DIR, get_theme_root());
    }

    /**
     * Pure: which plugin directory (or single-file plugin) or theme a file belongs to.
     *
     * @return array{owner: ?string, type: ?string}
     */
    public static function ownerOfFile(string $file, string $pluginDir, string $themeDir): array
    {
        $file = wp_normalize_path($file);

        foreach (array('plugin' => $pluginDir, 'theme' => $themeDir) as $type => $root) {
            $root = trailingslashit(wp_normalize_path($root));
            if (str_starts_with($file, $root)) {
                $first = explode('/', substr($file, strlen($root)))[0];

                return array('owner' => $type === 'plugin' ? basename($first, '.php') : $first, 'type' => $type);
            }
        }

        return array('owner' => null, 'type' => null);
    }

    /**
     * Pure: whether a hook is one an update check runs through - the transient's write and read
     * filters, and core's per-host `update_{plugins,themes}_<host>` filters for `Update URI:` packages.
     */
    public static function isUpdateHook(string $hook): bool
    {
        foreach (array('plugins', 'themes') as $kind) {
            $exact = array(
                'pre_set_site_transient_update_' . $kind,
                'site_transient_update_' . $kind,
                'pre_site_transient_update_' . $kind,
            );
            if (in_array($hook, $exact, true) || str_starts_with($hook, 'update_' . $kind . '_')) {
                return true;
            }
        }

        return false;
    }

    private static function fileOf(mixed $callback): ?string
    {
        try {
            if (is_array($callback) && count($callback) === 2) {
                $reflection = new ReflectionMethod($callback[0], (string) $callback[1]);
            } elseif (is_string($callback) && str_contains($callback, '::')) {
                $reflection = new ReflectionMethod($callback);
            } elseif ($callback instanceof \Closure || is_string($callback)) {
                $reflection = new ReflectionFunction($callback);
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new ReflectionMethod($callback, '__invoke');
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $file = $reflection->getFileName();

        return is_string($file)
            ? $file
            : null;
    }

    /**
     * Runs $work with every callback on the update hooks guarded, then puts the hooks back exactly
     * as they were.
     *
     * @return list<array{owner: ?string, type: ?string, message: string}> What threw.
     */
    public function around(callable $work): array
    {
        global $wp_filter;

        $this->failures = array();
        $originals = array();

        foreach (array_keys((array) $wp_filter) as $hook) {
            $hookObject = $wp_filter[$hook];
            if (! self::isUpdateHook((string) $hook) || ! $hookObject instanceof \WP_Hook) {
                continue;
            }

            $originals[$hook] = $hookObject->callbacks;
            foreach ($hookObject->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $id => $callback) {
                    $hookObject->callbacks[$priority][$id]['function'] = $this->guard($callback['function']);
                }
            }
        }

        try {
            $work();
        } finally {
            foreach ($originals as $hook => $callbacks) {
                $wp_filter[$hook]->callbacks = $callbacks;
            }
        }

        return $this->failures;
    }

    private function guard(mixed $callback): \Closure
    {
        return function (...$args) use ($callback) {
            try {
                return call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                // Once per vendor and message: core saves the transient twice per check, so the same
                // updater throws twice.
                $failure = self::ownerOf($callback) + array('message' => $e->getMessage());
                if (! in_array($failure, $this->failures, true)) {
                    $this->failures[] = $failure;
                }

                return $args[0] ?? null;
            }
        };
    }
}
