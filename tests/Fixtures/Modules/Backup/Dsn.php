<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Backup;

use App\Engine\Database\ConnectionConfig;

/**
 * A connection's DSN, taken apart.
 *
 * `ConnectionConfig` deliberately keeps only the raw DSN string -- see its
 * own docblock -- because a driver's DSN syntax is that driver's business,
 * not the framework's. A backup tool is the one thing that genuinely needs
 * host/port/database apart again, to hand to `mysqldump`/`pg_dump` as
 * separate arguments, so this parses what `ConnectionConfig::assemble()`
 * writes, in reverse: `key=value` pairs split on `;`, case-insensitively,
 * because a DSN written by hand may not match this framework's own casing.
 */
final class Dsn
{
    private function __construct(
        public readonly string $driver,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $database,
        /** sqlite only: the file path, or null for an in-memory database. */
        public readonly ?string $path,
    ) {}

    public static function parse(ConnectionConfig $config): self
    {
        $dsn = $config->dsn;
        $colon = \strpos($dsn, ':');
        $driver = $colon === false ? '' : \substr($dsn, 0, $colon);
        $rest = $colon === false ? '' : \substr($dsn, $colon + 1);

        if ($driver === 'sqlite') {
            $path = $rest === '' || $rest === ':memory:' ? null : $rest;

            return new self($driver, null, null, null, $path);
        }

        $parts = self::pairs($rest);

        if ($driver === 'sqlsrv') {
            [$host, $port] = self::server($parts['server'] ?? null);

            return new self($driver, $host, $port, $parts['database'] ?? null, null);
        }

        $port = isset($parts['port']) && \ctype_digit($parts['port']) ? (int) $parts['port'] : null;

        return new self($driver, $parts['host'] ?? null, $port, $parts['dbname'] ?? null, null);
    }

    /** @return array<string, string> lower-cased keys */
    private static function pairs(string $rest): array
    {
        $parts = [];

        foreach (\explode(';', $rest) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = \array_pad(\explode('=', $pair, 2), 2, '');
            $parts[\strtolower($key)] = $value;
        }

        return $parts;
    }

    /**
     * "host" or "host,port" -> [host, port].
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function server(?string $server): array
    {
        if ($server === null || $server === '') {
            return [null, null];
        }

        if (!\str_contains($server, ',')) {
            return [$server, null];
        }

        [$host, $port] = \explode(',', $server, 2);

        return [$host, \ctype_digit($port) ? (int) $port : null];
    }
}
