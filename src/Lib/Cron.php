<?php

declare(strict_types=1);

namespace ApexToolbox\Lib;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Cron
{
    public function __construct(
        protected string $hook,
        protected string $recurrence = 'hourly',
    ) {
    }

    public function execute(callable $callback): void
    {
        add_action($this->hook, $callback);

        if (wp_next_scheduled($this->hook)) {
            return;
        }

        wp_schedule_event(
            current_time('timestamp', true),
            $this->recurrence,
            $this->hook,
        );
    }
}
