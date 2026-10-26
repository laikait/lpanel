<?php

declare(strict_types=1);

namespace App\Engine\System\SystemInfo;

/** Physical memory, in bytes, as the kernel reports it. */
final class Memory
{
    public function __construct(
        public readonly int $total,
        /** What can be given to a new process without swapping: MemAvailable, not MemFree. */
        public readonly int $available,
    ) {}

    public function used(): int
    {
        return \max(0, $this->total - $this->available);
    }

    /** 0.0 to 1.0. */
    public function usedRatio(): float
    {
        return $this->total > 0 ? $this->used() / $this->total : 0.0;
    }
}
