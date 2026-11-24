<?php

declare(strict_types=1);

namespace App\Modules\Operations\Logs;

use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * The server's logs and the statistics made from the access logs, through the
 * agent. The panel names a log by one of the names the agent lists (never a
 * path), and an account's own pages always ask for that account's: what an
 * owner can read is decided here by the account the page belongs to, not by
 * anything in the request.
 */
final class LogViewer
{
    public const LINES = [100, 200, 500, 1000];

    public const DAYS = [7, 30, 90];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The logs that can be read: the system's, or one account's own.
     *
     * @return array{0: list<array<string, mixed>>|null, 1: string|null} the list, or why not (a language key)
     */
    public function sources(?string $user): array
    {
        [$result, $why] = $this->ask('logs.sources', $user === null ? [] : ['user' => $user], 30.0);

        return $result === null ? [null, $why] : [\array_values(\is_array($result['sources'] ?? null) ? $result['sources'] : []), null];
    }

    /**
     * The latest lines of one log.
     *
     * @return array{0: array{lines: list<string>, more: bool}|null, 1: string|null}
     */
    public function read(?string $user, string $source, int $lines, string $filter): array
    {
        $args = ['source' => $source, 'lines' => \in_array($lines, self::LINES, true) ? $lines : 200];

        if ($user !== null) {
            $args['user'] = $user;
        }

        if (\trim($filter) !== '') {
            $args['filter'] = \mb_substr(\trim($filter), 0, 100);
        }

        [$result, $why, $code] = $this->askWithCode('logs.read', $args, 60.0);

        if ($result === null) {
            // A log with nothing in it yet is an empty log, not an error.
            return $code === 'logs_missing' ? [['lines' => [], 'more' => false], null] : [null, $why];
        }

        return [['lines' => \array_values(\array_map('strval', \is_array($result['lines'] ?? null) ? $result['lines'] : [])), 'more' => (bool) ($result['more'] ?? false)], null];
    }

    /**
     * GoAccess's statistics of one account's sites, or of all of them.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public function report(?string $user, int $days, bool $refresh): array
    {
        $args = ['days' => \in_array($days, self::DAYS, true) ? $days : 30] + ($user === null ? [] : ['user' => $user]) + ($refresh ? ['refresh' => true] : []);
        [$result, $why] = $this->ask('logs.report', $args, 180.0);

        return [$result, $why];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function ask(string $op, array $args, float $timeout): array
    {
        [$result, $why] = $this->askWithCode($op, $args, $timeout);

        return [$result, $why];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: array<string, mixed>|null, 1: string|null, 2: string|null} the result, or why not and the agent's error code
     */
    private function askWithCode(string $op, array $args, float $timeout): array
    {
        try {
            $reply = $this->agent->call($op, $args, timeout: $timeout);
        } catch (AgentUnavailable) {
            return [null, 'Operations.logs_error_unreachable', null];
        }

        if ($reply->done()) {
            return [$reply->result, null, null];
        }

        $code = (string) $reply->errorCode;

        return [null, match ($code) {
            'logs_source' => 'Operations.logs_error_source',
            'bad_args' => 'Operations.logs_error_args',
            'logs_goaccess' => (string) ($reply->errorMessage ?? 'Operations.logs_error_failed'),
            default => 'Operations.logs_error_failed',
        }, $code];
    }
}
