<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\System;

use App\Engine\System\Cron\CronTable;

/** A crontab that is a string, and counts how often it was written. */
final class MemoryCronTable implements CronTable
{
    public int $writes = 0;

    public function __construct(public string $contents = '') {}

    public function read(): string
    {
        return $this->contents;
    }

    public function write(string $contents): void
    {
        $this->contents = $contents;
        ++$this->writes;
    }
}
