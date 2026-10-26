<?php

declare(strict_types=1);

namespace App\Engine\Data;

/** One ordering clause. */
final class Order
{
    public function __construct(
        public readonly string $field,
        public readonly Direction $direction = Direction::Asc,
    ) {}

    public function isDescending(): bool
    {
        return $this->direction === Direction::Desc;
    }
}
