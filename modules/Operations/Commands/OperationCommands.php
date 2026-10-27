<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Modules\Operations\OperationException;
use App\Modules\Operations\OperationRequests;
use App\Modules\Operations\Store\Operation;
use App\Modules\Operations\Store\OperationStore;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * operations:run, operations:list, operations:show -- for an administrator
 * with a shell on the panel host, and for the end-to-end test. Audited as
 * the console.
 */
final class OperationCommands
{
    public function __construct(
        private readonly OperationRequests $requests,
        private readonly OperationStore $operations,
        private readonly ServerStore $servers,
    ) {}

    /** Queue $op for $server; with --wait, until it is final (exit 0 only if it succeeded). */
    public function run(Output $output, string $server, string $op, ?int $opVersion = null, bool $wait = false, bool $json = false, int $timeout = 60): int
    {
        $found = $this->find($output, $server);

        if ($found === null) {
            return 1;
        }

        try {
            [$queued] = $this->requests->request(self::console(), $found, $op, $opVersion, new \stdClass());
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        $deadline = \time() + \max(1, \min(3600, $timeout));

        while ($wait && $queued->isLive() && \time() < $deadline) {
            \usleep(250000);
            $queued = $this->operations->byUid($queued->uid) ?? $queued;
        }

        $this->print($output, $queued, $json);

        return !$wait || $queued->status === Operation::SUCCEEDED ? 0 : 1;
    }

    public function list(Output $output, ?string $server = null, bool $json = false): int
    {
        $found = $server === null ? null : $this->find($output, $server);

        if ($server !== null && $found === null) {
            return 1;
        }

        $page = $this->operations->page(50, null, null, $found?->id);

        if ($json) {
            $output->line(\json_encode(\array_map(static fn(Operation $op): array => $op->toArray(), $page), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return 0;
        }

        foreach ($page as $op) {
            $output->line(\sprintf('%s  %-22s %-16s %-2d %s', $op->uid, $op->op, $op->status, $op->attempt, $op->queuedAt));
        }

        return 0;
    }

    public function show(Output $output, string $id, bool $json = false): int
    {
        $op = $this->operations->byUid($id);

        if ($op === null) {
            $output->line('No such operation.');

            return 1;
        }

        $this->print($output, $op, $json);

        return 0;
    }

    private function print(Output $output, Operation $op, bool $json): void
    {
        if ($json) {
            $output->line(\json_encode($op->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return;
        }

        $output->line(\sprintf('%s %s v%d: %s (attempt %d of %d)', $op->uid, $op->op, $op->opVersion, $op->status, $op->attempt, $op->maxAttempts));

        if ($op->errorCode !== null) {
            $output->line(\sprintf('  %s: %s', $op->errorCode, (string) $op->message));
        }

        if ($op->result !== null) {
            $output->line('  ' . \json_encode($op->resultValue(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));
        }
    }

    /** By id, or by name when exactly one server has it. */
    private function find(Output $output, string $server): ?Server
    {
        $found = $this->servers->byUid($server);

        if ($found !== null) {
            return $found;
        }

        $named = \array_values(\array_filter($this->servers->all(), static fn(Server $s): bool => $s->name === $server));

        if (\count($named) !== 1) {
            $output->line(\count($named) === 0 ? 'No such server.' : 'More than one server has that name; use its id.');

            return null;
        }

        return $named[0];
    }

    private static function console(): Identity
    {
        return new Identity('console', 'Panel console', [], ['system' => true]);
    }
}
