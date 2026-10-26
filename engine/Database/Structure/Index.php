<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

/** An index over one or more columns, unique or not. Named by Table::nameFor(). */
final class Index
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly array $columns,
        public readonly bool $unique,
    ) {}
}
