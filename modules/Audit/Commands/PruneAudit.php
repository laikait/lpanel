<?php

declare(strict_types=1);

namespace App\Modules\Audit\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Modules\Audit\AuditLog;

/** audit:prune -- delete what is older than Audit.retention_days. Scheduled daily. */
final class PruneAudit
{
    public function __construct(private readonly AuditLog $audit, private readonly Config $config) {}

    public function __invoke(Output $output): int
    {
        $days = $this->config->int('Audit.retention_days', 365) ?? 365;
        $removed = $this->audit->prune($days);
        $output->line(\sprintf('Deleted %d audit events older than %d days.', $removed, $days));

        return 0;
    }
}
