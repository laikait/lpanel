<?php

declare(strict_types=1);

namespace App\Engine\System\SystemInfo;

/** The filesystem a path is on, in bytes. */
final class Disk
{
    public function __construct(
        public readonly string $path,
        public readonly int $total,
        /** Free to this process: what an unprivileged user can still write. */
        public readonly int $free,
    ) {}

    public function used(): int
    {
        return \max(0, $this->total - $this->free);
    }

    /** 0.0 to 1.0. */
    public function usedRatio(): float
    {
        return $this->total > 0 ? $this->used() / $this->total : 0.0;
    }
}
