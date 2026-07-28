<?php

declare(strict_types=1);

namespace ApexToolbox\Lib;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Shortcode
{
    public function __construct(
        protected string $tag,
    ) {
    }

    public function execute(callable $callback): void
    {
        add_shortcode($this->tag, $callback);
    }
}
