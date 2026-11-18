<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;

/**
 * When every account is backed up, where to, and how many snapshots are
 * kept. Nothing here reaches the agent until a run starts.
 */
final class BackupSettings
{
    /** @var array{enabled: bool, time: string, destination_id: int, keep_daily: int, keep_weekly: int, keep_monthly: int, prune_day: int} */
    public const DEFAULTS = ['enabled' => false, 'time' => '02:30', 'destination_id' => 0, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 3, 'prune_day' => 0];

    /** The largest number of each kind of snapshot kept. */
    public const KEEP_MAX = ['keep_daily' => 365, 'keep_weekly' => 104, 'keep_monthly' => 120];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{enabled: bool, time: string, destination_id: int, keep_daily: int, keep_weekly: int, keep_monthly: int, prune_day: int} */
    public function get(): array
    {
        $saved = [];

        foreach ($this->table()->get() as $row) {
            $saved[(string) $row['name']] = (string) $row['value'];
        }

        $int = static fn(string $name): int => (int) ($saved[$name] ?? self::DEFAULTS[$name]);

        return [
            'enabled' => ($saved['enabled'] ?? '0') === '1',
            'time' => $saved['time'] ?? self::DEFAULTS['time'],
            'destination_id' => $int('destination_id'),
            'keep_daily' => $int('keep_daily'),
            'keep_weekly' => $int('keep_weekly'),
            'keep_monthly' => $int('keep_monthly'),
            'prune_day' => $int('prune_day'),
        ];
    }

    /**
     * Check settings from a form, the API or the CLI, over the current ones.
     *
     * @param array<string, mixed> $input
     * @param list<int>            $destinations the destinations there are
     *
     * @return array{0: array{enabled: bool, time: string, destination_id: int, keep_daily: int, keep_weekly: int, keep_monthly: int, prune_day: int}, 1: array<string, string>}
     */
    public function validate(array $input, array $destinations): array
    {
        $s = $this->get();
        $errors = [];
        $text = static fn(mixed $v): string => \trim(\is_scalar($v) ? (string) $v : '');

        if (\array_key_exists('enabled', $input)) {
            $s['enabled'] = \in_array($text($input['enabled']), ['1', 'on', 'true', 'yes'], true) || $input['enabled'] === true;
        }

        if (isset($input['time'])) {
            $time = $text($input['time']);

            if (\preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                $errors['time'] = 'Backups.error_time';
            } else {
                $s['time'] = $time;
            }
        }

        foreach (['destination_id', 'keep_daily', 'keep_weekly', 'keep_monthly', 'prune_day'] as $name) {
            if (!isset($input[$name])) {
                continue;
            }

            $value = $text($input[$name]);

            if (!\ctype_digit($value) || \strlen($value) > 9) {
                $errors[$name] = 'Backups.error_number';
            } else {
                $s[$name] = (int) $value;
            }
        }

        foreach (self::KEEP_MAX as $name => $max) {
            if (!isset($errors[$name]) && $s[$name] > $max) {
                $errors[$name] = 'Backups.error_keep_max';
            }
        }

        if (!isset($errors['keep_daily']) && $s['keep_daily'] + $s['keep_weekly'] + $s['keep_monthly'] === 0) {
            $errors['keep_daily'] = 'Backups.error_keep_none';
        }

        if (!isset($errors['prune_day']) && $s['prune_day'] > 6) {
            $errors['prune_day'] = 'Backups.error_number';
        }

        if ($s['destination_id'] !== 0 && !\in_array($s['destination_id'], $destinations, true)) {
            $errors['destination_id'] = 'Backups.error_destination';
        }

        if ($s['enabled'] && $s['destination_id'] === 0 && !isset($errors['destination_id'])) {
            $errors['destination_id'] = 'Backups.error_destination_needed';
        }

        return [$s, $errors];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<int>            $destinations
     *
     * @return array<string, string> errors by field; empty when saved
     */
    public function save(?Identity $actor, ?Request $request, array $input, array $destinations): array
    {
        [$s, $errors] = $this->validate($input, $destinations);

        if ($errors !== []) {
            return $errors;
        }

        $rows = [];

        foreach ($s as $name => $value) {
            $rows[] = ['name' => $name, 'value' => \is_bool($value) ? ($value ? '1' : '0') : (string) $value];
        }

        $this->table()->upsert($rows, ['name'], ['value']);
        $this->audit->record('backup.settings', $actor, $request, 'backups', AuditLog::OK, $s);

        return [];
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('backup_settings');
    }
}
