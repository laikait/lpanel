<?php

declare(strict_types=1);

namespace App\Engine\Core;

/**
 * An immutable description of how this process was started.
 *
 * The same bootstrap builds the application for both HTTP and CLI; this is the
 * one thing that differs between them, and it is data rather than a branch
 * scattered through the engine.
 */
final class ExecutionContext
{
    /**
     * @param list<string>         $argv
     * @param array<string, mixed> $server
     */
    private function __construct(
        public readonly ExecutionMode $mode,
        public readonly string $sapi,
        public readonly array $argv,
        public readonly array $server,
        public readonly float $startedAt,
    ) {}

    /** @param array<string, mixed> $server */
    public static function http(array $server = [], ?float $startedAt = null): self
    {
        return new self(
            ExecutionMode::Http,
            \PHP_SAPI,
            [],
            $server,
            $startedAt ?? self::requestTime($server),
        );
    }

    /**
     * @param list<string>         $argv
     * @param array<string, mixed> $server
     */
    public static function cli(array $argv = [], array $server = [], ?float $startedAt = null): self
    {
        return new self(
            ExecutionMode::Cli,
            \PHP_SAPI,
            \array_values($argv),
            $server,
            $startedAt ?? self::requestTime($server),
        );
    }

    public function isHttp(): bool
    {
        return $this->mode === ExecutionMode::Http;
    }

    public function isCli(): bool
    {
        return $this->mode === ExecutionMode::Cli;
    }

    /** The command name, or null when none was given. */
    public function command(): ?string
    {
        return $this->argv[1] ?? null;
    }

    /** @return list<string> everything after the command name */
    public function arguments(): array
    {
        return \array_values(\array_slice($this->argv, 2));
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /** Seconds since the process started. */
    public function elapsed(): float
    {
        return \microtime(true) - $this->startedAt;
    }

    /** @param array<string, mixed> $server */
    private static function requestTime(array $server): float
    {
        $time = $server['REQUEST_TIME_FLOAT'] ?? null;

        return \is_numeric($time) ? (float) $time : \microtime(true);
    }
}
