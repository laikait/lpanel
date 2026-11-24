<?php

declare(strict_types=1);

namespace App\Modules\Operations;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Routing\Router;
use App\Modules\Accounts\Accounts;
use App\Modules\Operations\Cron\CronJobs;
use App\Modules\Operations\Services\Services;
use App\Modules\Operations\Updates\Updates;

/** What Operations says to the dashboard and the bell. */
final class OperationsListeners
{
    public function __construct(
        private readonly Services $services,
        private readonly Updates $updates,
        private readonly CronJobs $cron,
        private readonly Accounts $accounts,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
    ) {}

    /**
     * accounts.changed: the jobs follow the account (suspended runs nothing, a package without cron
     * runs nothing, removed takes them away).
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        match ($op) {
            'account.terminate' => $this->cron->forget($row),
            'account.suspend', 'account.unsuspend', 'account.modify', 'account.restore' => $this->cron->sync($row),
            default => null,
        };
    }

    /**
     * packages.updated: a package that gained or lost the cron feature changes what its accounts run.
     *
     * @param array<string, mixed> $package
     */
    public function packageUpdated(array $package): void
    {
        foreach ($this->accounts->onPackage((int) $package['id']) as $account) {
            $this->cron->sync($account);
        }
    }

    /**
     * panel.attention: services that are down and should not be.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public function attention(array $items, string $area, Identity $identity): array
    {
        if ($area !== 'admin') {
            return $items;
        }

        if ($this->authorizer->allows($identity, 'server.updates')) {
            $items = $this->updateAttention($items);
        }

        if (!$this->authorizer->allows($identity, 'server.services')) {
            return $items;
        }

        $down = $this->services->down();

        if ($down !== []) {
            $items[] = [
                'tone' => 'bad',
                'title' => 'Operations.attention_title',
                'text' => 'Operations.attention_down',
                'url' => $this->router->url('admin.services'),
            ];
        }

        return $items;
    }

    /**
     * panel.dashboard: the services at a glance.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area !== 'admin' || !$this->authorizer->allows($identity, 'server.services')) {
            return $cards;
        }

        $list = $this->services->list();

        if ($list === null) {
            return $cards;
        }

        $cards[] = [
            'template' => 'Operations/card',
            'order' => 60,
            'data' => [
                'services' => \array_slice(\array_values(\array_filter($list, static fn(array $s): bool => \in_array($s['group'], ['web', 'php', 'database', 'dns', 'mail', 'ftp'], true))), 0, 12),
                'running' => \count(\array_filter($list, static fn(array $s): bool => $s['running'])),
                'total' => \count($list),
                'link' => $this->router->url('admin.services'),
            ],
        ];

        return $cards;
    }
    /**
     * Updates that need somebody: one that failed, security updates waiting,
     * a reboot, a new release of LPanel.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function updateAttention(array $items): array
    {
        $url = $this->router->url('admin.updates');

        if ($this->updates->recentFailure() !== null) {
            $items[] = ['tone' => 'bad', 'title' => 'Operations.updates_attention_failed_title', 'text' => 'Operations.updates_attention_failed', 'url' => $url];
        }

        $latest = $this->updates->latest();

        if ($latest === null) {
            return $items;
        }

        if ($latest['security'] > 0 && $this->updates->busy() === null) {
            $items[] = ['tone' => 'warn', 'title' => 'Operations.updates_attention_security_title', 'text' => 'Operations.updates_attention_security', 'url' => $url];
        }

        if ($latest['reboot']['needed'] && !$latest['reboot']['scheduled']) {
            $items[] = ['tone' => 'warn', 'title' => 'Operations.updates_attention_reboot_title', 'text' => 'Operations.updates_attention_reboot', 'url' => $url];
        }

        if ($latest['panel']['newer']) {
            $items[] = ['tone' => 'info', 'title' => 'Operations.updates_attention_panel_title', 'text' => 'Operations.updates_attention_panel', 'url' => $url];
        }

        return $items;
    }
}
