<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\System\Plugins\Server\Services;

use App\Engine\Auth\Identity;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Security\SystemAuthorizer;
use App\Engine\System\Security\SystemCapability;
use App\Engine\System\Service\ServiceManager;
use App\Engine\System\Service\ServicePolicy;
use App\Engine\System\SystemInfo\SystemInfo;

/**
 * The operations a server administration screen -- or, later, an MCP tool --
 * offers, and nothing more: information, a service's status, restarting one,
 * the application's cron jobs.
 *
 * Every method takes the identity asking, authorizes it for exactly that
 * operation on exactly that target, and only then asks engine/System, whose
 * policies still decide what the machine will do. There is no method that runs
 * a command it was given: the demo's surface is the list above.
 *
 * Returns plain arrays, the normalized shape every interface can send.
 */
final class ServerService
{
    public function __construct(
        private readonly SystemAuthorizer $system,
        private readonly ServiceManager $services,
        private readonly CronManager $cron,
    ) {}

    /** @return array<string, mixed> */
    public function info(Identity $who): array
    {
        $this->system->authorize($who, SystemCapability::InfoRead);

        $info = new SystemInfo();
        $memory = $info->memory();

        return [
            'os' => $info->os(),
            'kernel' => $info->kernel(),
            'architecture' => $info->architecture(),
            'php' => $info->phpVersion(),
            'cpus' => $info->cpuCount(),
            'memory' => $memory === null ? null : ['total' => $memory->total, 'available' => $memory->available],
            'load' => $info->loadAverage(),
            'uptime' => $info->uptime(),
        ];
    }

    /** @return array{unit: string, exists: bool, active: string, sub: string, at_boot: string} */
    public function serviceStatus(Identity $who, string $service): array
    {
        $unit = ServicePolicy::unit($service);
        $this->system->authorize($who, SystemCapability::ServiceRead, $unit);

        $status = $this->services->status($unit);

        return [
            'unit' => $status->unit,
            'exists' => $status->exists(),
            'active' => $status->activeState,
            'sub' => $status->subState,
            'at_boot' => $status->unitFileState,
        ];
    }

    public function restartService(Identity $who, string $service): void
    {
        $unit = ServicePolicy::unit($service);
        $this->system->authorize($who, SystemCapability::ServiceRestart, $unit);

        $this->services->restart($unit);
    }

    /** @return list<array{id: string, schedule: string}> */
    public function cronJobs(Identity $who): array
    {
        $this->system->authorize($who, SystemCapability::CronRead);

        $jobs = [];

        foreach ($this->cron->jobs() as $job) {
            $jobs[] = ['id' => $job->id(), 'schedule' => $job->schedule()];
        }

        return $jobs;
    }
}
