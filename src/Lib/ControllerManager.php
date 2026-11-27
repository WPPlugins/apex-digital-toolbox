<?php

declare(strict_types=1);

namespace ApexToolbox\Lib;

use ApexToolbox\Vendor\DI\Container;
use ReflectionClass;

/**
 * Resolves each controller from the container and registers its attribute-declared
 * WordPress hooks (Action / Filter / Cron / Shortcode).
 *
 * Ported from the agency child-theme DI pattern. NOTE: this registers every controller's
 * hooks unconditionally — that is correct for the always-on MCP/admin controllers in this
 * plugin. The legacy per-site opt-in hooks remain on their own lazy-loaded path in
 * toolboxController and are intentionally NOT routed through here (see CLAUDE.md / the
 * "Phase 2" hook-migration notes for how a gated registrar would extend this later).
 */
final class ControllerManager
{
    /** @param array<class-string> $classes The classes to register as controllers. */
    public static function register(Container $container, array $classes): void
    {
        foreach ($classes as $class) {
            $instance = $container->get($class);
            $reflection_class = new ReflectionClass($class);

            foreach ($reflection_class->getMethods() as $method) {
                foreach ($method->getAttributes() as $attribute) {
                    $attribute_instance = $attribute->newInstance();

                    if (
                        ! ( $attribute_instance instanceof Action ) &&
                        ! ( $attribute_instance instanceof Filter ) &&
                        ! ( $attribute_instance instanceof Cron ) &&
                        ! ( $attribute_instance instanceof Shortcode )
                    ) {
                        continue;
                    }

                    $callback = [$instance, $method->getName()];
                    assert(is_callable($callback));
                    $attribute_instance->execute($callback);
                }
            }
        }
    }
}
