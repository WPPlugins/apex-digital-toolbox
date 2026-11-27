<?php

declare(strict_types=1);

namespace ApexToolbox\Lib;

use ReflectionMethod;

/**
 * Base class for the hook-registration attributes.
 *
 * Ported from the agency child-theme DI pattern so new namespaced code in this plugin
 * registers WordPress hooks the same way as every other Limelight Digital project.
 */
abstract class Hook
{
    protected function getParameterCount(callable $callable): int
    {
        /** @var array{object, string} $callable */

        $reflection_method = new ReflectionMethod($callable[0], $callable[1]);

        return count($reflection_method->getParameters());
    }
}
