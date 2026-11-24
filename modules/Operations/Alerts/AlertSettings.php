<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;

/**
 * When the server speaks up on its own.
 *
 * - disk: percent of a filesystem used (50-99; 0 is off).
 * - memory: percent of memory in use, swap aside (50-99; 0 is off).
 * - load: the five-minute load average per CPU (1-20; 0 is off).
 * - ssl: days before a certificate expires (1-60; 0 is off).
 * - quiet: minutes in which the same alert is not sent twice (5-1440).
 */
final class AlertSettings
{
    /** @var array{disk: int, memory: int, load: int, ssl: int, quiet: int} */
    public const DEFAULTS = ['disk' => 90, 'memory' => 90, 'load' => 4, 'ssl' => 7, 'quiet' => 60];

    /** name => [lowest, highest]; each may also be 0 (off) except quiet. */
    private const RANGE = ['disk' => [50, 99], 'memory' => [50, 99], 'load' => [1, 20], 'ssl' => [1, 60], 'quiet' => [5, 1440]];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{disk: int, memory: int, load: int, ssl: int, quiet: int} */
    public function get(): array
    {
        $saved = [];

        foreach ($this->table()->get() as $row) {
            $saved[(string) $row['name']] = (string) $row['value'];
        }

        $s = self::DEFAULTS;

        foreach (self::RANGE as $name => [$low, $high]) {
            $v = $saved['alerts_' . $name] ?? null;

            if ($v !== null && \ctype_digit($v) && (((int) $v >= $low && (int) $v <= $high) || ((int) $v === 0 && $name !== 'quiet'))) {
                $s[$name] = (int) $v;
            }
        }

        return $s;
    }

    /**
     * Settings from a form, the API or the CLI, over the current ones.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array{disk: int, memory: int, load: int, ssl: int, quiet: int}, 1: array<string, string>}
     */
    public function validate(array $input): array
    {
        $s = $this->get();
        $errors = [];

        foreach (self::RANGE as $name => [$low, $high]) {
            if (!\array_key_exists($name, $input)) {
                continue;
            }

            $v = \trim(\is_scalar($input[$name]) ? (string) $input[$name] : '');

            if (\ctype_digit($v) && (((int) $v >= $low && (int) $v <= $high) || ((int) $v === 0 && $name !== 'quiet'))) {
                $s[$name] = (int) $v;
            } else {
                $errors[$name] = 'Operations.alerts_error_' . $name;
            }
        }

        return [$s, $errors];
    }

    /** @param array{disk: int, memory: int, load: int, ssl: int, quiet: int} $s */
    public function save(array $s, ?Identity $actor, ?Request $request): void
    {
        foreach ($s as $name => $value) {
            $key = 'alerts_' . $name;

            if ($this->table()->where('name', $key)->first() === null) {
                $this->table()->insert(['name' => $key, 'value' => (string) $value]);
            } else {
                $this->table()->where('name', $key)->update(['value' => (string) $value]);
            }
        }

        $this->audit->record('alerts.settings', $actor, $request, 'server', AuditLog::OK, $s);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_settings');
    }
}
