<?php

declare(strict_types=1);

namespace ApexToolbox\Vendor\DI\Definition\Source;

use ApexToolbox\Vendor\DI\Definition\Exception\InvalidDefinition;
use ApexToolbox\Vendor\DI\Definition\ObjectDefinition;

/**
 * Source of definitions for entries of the container.
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
interface Autowiring
{
    /**
     * Autowire the given definition.
     *
     * @throws InvalidDefinition An invalid definition was found.
     */
    public function autowire(string $name, ?ObjectDefinition $definition = null) : ?ObjectDefinition;
}
