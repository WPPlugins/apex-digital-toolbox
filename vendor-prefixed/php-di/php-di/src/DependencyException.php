<?php

declare(strict_types=1);

namespace ApexToolbox\Vendor\DI;

use ApexToolbox\Vendor\Psr\Container\ContainerExceptionInterface;

/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
