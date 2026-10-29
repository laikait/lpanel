<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Backup\Jobs;

use App\Engine\Queue\Job;
use App\Tests\Fixtures\Modules\System\Plugins\Backup\Services\BackupService;

/**
 * A backup, made later by a worker.
 *
 * Data in the constructor, collaborators in handle(), as every job. What it
 * carries is who asked -- for the record -- and not their identity: the
 * decision that they may was made, and audited, when they asked. A worker
 * cannot rebuild an identity from an id without the application's user store,
 * and re-deciding an hour later against roles that may have changed would make
 * the queue a second place where access is decided.
 */
final class CreateBackup implements Job
{
    public function __construct(
        public readonly string $name,
        public readonly string $requestedBy,
    ) {}

    public function handle(BackupService $backups): void
    {
        $backups->perform($this->name, $this->requestedBy);
    }
}
