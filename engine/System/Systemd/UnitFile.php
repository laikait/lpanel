<?php

declare(strict_types=1);

namespace App\Engine\System\Systemd;

/**
 * One systemd unit file: the name it is installed under, and what it holds.
 */
final class UnitFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $contents,
    ) {}
}
