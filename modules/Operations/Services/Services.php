<?php

declare(strict_types=1);

namespace App\Modules\Operations\Services;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * The services the server runs (web servers, PHP, database, DNS, mail, FTP, SSH,
 * the firewall, the panel's own): how each is doing, and starting, stopping
 * and restarting them. The agent holds the list and what each unit allows;
 * what the panel adds is the watchdog's memory of each service (whether it is
 * watched, whether an administrator stopped it on purpose) and the log of
 * what happened.
 */
final class Services
{
    public const ACTIONS = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'];

    /** How long the log of what happened is kept, in seconds. */
    private const KEEP = 2592000;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Every service the agent manages that is installed, each with what the
     * watchdog knows; null when the agent does not answer.
     *
     * @return list<array<string, mixed>>|null
     */
    public function list(): ?array
    {
        try {
            $reply = $this->agent->call('services.status', [], timeout: 30.0);
        } catch (AgentUnavailable) {
            return null;
        }

        if (!$reply->done()) {
            return null;
        }

        $rows = [];

        foreach ($this->table('ops_services')->get() as $r) {
            $rows[(string) $r['unit']] = $r;
        }

        $out = [];

        foreach ((array) ($reply->result['services'] ?? []) as $s) {
            if (!\is_array($s) || !\is_string($s['unit'] ?? null)) {
                continue;
            }

            $row = $rows[$s['unit']] ?? null;
            $s['watch'] = $row === null ? true : (bool) $row['watch'];
            $s['hold'] = $row !== null && (bool) $row['hold'];
            $s['down_since'] = $row['down_since'] ?? null;
            $s['running'] = ($s['active'] ?? '') === 'active';
            $s['transitional'] = \in_array($s['active'] ?? '', ['activating', 'reloading', 'deactivating'], true);
            // Up is what it should be: it starts at boot, and nobody stopped it.
            $s['expected'] = !empty($s['enabled']) && !$s['hold'];
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @return array{0: bool, 1: string|null} done, and why not (a language key, or the agent's words)
     */
    public function act(?Identity $actor, ?Request $request, string $unit, string $action): array
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            return [false, 'Operations.error_action'];
        }

        try {
            $reply = $this->agent->call('services.act', ['unit' => $unit, 'action' => $action], id: Protocol::newId(), timeout: 130.0);
        } catch (AgentUnavailable) {
            return [false, 'Operations.error_unreachable'];
        }

        $ok = $reply->done();
        $why = $ok ? null : (string) ($reply->errorMessage ?? 'Operations.error_failed');
        $this->audit->record('service.' . $action, $actor, $request, $unit, $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? [] : ['error' => $why]);

        if ($ok) {
            // Stopped on purpose: the watchdog leaves it alone until it is started again.
            if ($action === 'stop') {
                $this->setRow($unit, ['hold' => 1, 'down_since' => null]);
            } elseif (\in_array($action, ['start', 'restart'], true)) {
                $this->setRow($unit, ['hold' => 0]);
            }

            // The watchdog's own restarts are logged as such, not as somebody's doing.
            if ($actor !== null) {
                $who = $actor->attribute('username');
                $this->event($unit, 'admin', $action . ' by ' . (\is_string($who) ? $who : (string) $actor->id));
            }
        }

        return [$ok, $why];
    }

    /** Whether the watchdog restarts the service when it goes down. */
    public function setWatch(?Identity $actor, ?Request $request, string $unit, bool $watch): void
    {
        $this->setRow($unit, ['watch' => $watch ? 1 : 0]);
        $this->audit->record('service.watch', $actor, $request, $unit, AuditLog::OK, ['watch' => $watch]);
    }

    /**
     * Keep one thing the watchdog knows about a service.
     *
     * @param array<string, mixed> $changes
     */
    public function setRow(string $unit, array $changes): void
    {
        $table = $this->table('ops_services');

        if ($table->where('unit', $unit)->first() === null) {
            $this->table('ops_services')->insert($changes + ['unit' => $unit, 'watch' => 1, 'hold' => 0]);

            return;
        }

        $this->table('ops_services')->where('unit', $unit)->update($changes);
    }

    public function event(string $unit, string $kind, string $detail = ''): void
    {
        $this->table('ops_service_events')->insert(['unit' => $unit, 'kind' => $kind, 'detail' => \mb_substr($detail, 0, 300), 'at' => \gmdate('Y-m-d H:i:s')]);
    }

    /**
     * The latest things that happened to services.
     *
     * @return list<array<string, mixed>>
     */
    public function events(int $limit = 30, ?string $unit = null): array
    {
        $q = $this->table('ops_service_events');

        if ($unit !== null) {
            $q = $q->where('unit', $unit);
        }

        return \array_values($q->orderByDesc('id')->limit($limit)->get());
    }

    /** How many times the watchdog tried to restart a service in the last hour. */
    public function attempts(string $unit): int
    {
        return $this->table('ops_service_events')->where('unit', $unit)->whereIn('kind', ['restarted', 'restart_failed'])->where('at', '>=', \gmdate('Y-m-d H:i:s', \time() - 3600))->count();
    }

    /** @return int how many events were forgotten for being old */
    public function tidy(): int
    {
        return $this->table('ops_service_events')->where('at', '<', \gmdate('Y-m-d H:i:s', \time() - self::KEEP))->delete();
    }

    /** @return list<array<string, mixed>> services found down that should be up, right now */
    public function down(): array
    {
        return \array_values($this->table('ops_services')->whereNotNull('down_since')->where('hold', 0)->where('watch', 1)->get());
    }

    private function table(string $name): QueryBuilder
    {
        return $this->connections->connection()->table($name);
    }
}
