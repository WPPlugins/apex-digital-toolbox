<?php

declare(strict_types=1);

namespace ApexToolbox\Lib;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Filter extends Hook
{
    public function __construct(
        protected string $hook,
        protected int $priority = 10,
    ) {
    }

    public function execute(callable $callback): void
    {
        add_filter($this->hook, $callback, $this->priority, $this->getParameterCount($callback));
    }
}
