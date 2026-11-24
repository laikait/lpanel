<?php

declare(strict_types=1);

namespace App\Modules\Operations\Updates;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;

/**
 * What the server installs by itself, and when.
 *
 * - os: the operating system's updates installed every night: off, security
 *   (the packages a security erratum updates; the default) or all.
 * - php: PHP's updates too (Remi publishes no errata, so "security" alone would
 *   never update PHP); on by default.
 * - hour: the hour of the night, server time, updates are installed in.
 * - exclude: packages (or patterns such as kernel*) never updated, by hand or not.
 * - panel: LPanel's own releases: notify (the default) or install.
 * - reboot: reboot in the same hour when an update needs it; off by default.
 */
final class UpdateSettings
{
    public const OS = ['off', 'security', 'all'];
    public const PANEL = ['notify', 'install'];

    /** @var array{os: string, php: bool, hour: int, exclude: list<string>, panel: string, reboot: bool} */
    public const DEFAULTS = ['os' => 'security', 'php' => true, 'hour' => 3, 'exclude' => [], 'panel' => 'notify', 'reboot' => false];

    /** A package name, or a pattern of one (the agent checks the same). */
    private const PATTERN = '/^[A-Za-z0-9_*?\[][A-Za-z0-9._+*?\[\]-]{0,99}$/';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{os: string, php: bool, hour: int, exclude: list<string>, panel: string, reboot: bool} */
    public function get(): array
    {
        $saved = [];

        foreach ($this->table()->get() as $row) {
            $saved[(string) $row['name']] = (string) $row['value'];
        }

        $s = self::DEFAULTS;

        if (\in_array($saved['updates_os'] ?? null, self::OS, true)) {
            $s['os'] = $saved['updates_os'];
        }

        if (isset($saved['updates_php'])) {
            $s['php'] = $saved['updates_php'] === '1';
        }

        if (isset($saved['updates_hour']) && \ctype_digit($saved['updates_hour']) && (int) $saved['updates_hour'] <= 23) {
            $s['hour'] = (int) $saved['updates_hour'];
        }

        if (isset($saved['updates_exclude'])) {
            $s['exclude'] = \array_values(\array_filter(\explode(' ', $saved['updates_exclude']), static fn(string $p): bool => \preg_match(self::PATTERN, $p) === 1));
        }

        if (\in_array($saved['updates_panel'] ?? null, self::PANEL, true)) {
            $s['panel'] = $saved['updates_panel'];
        }

        if (isset($saved['updates_reboot'])) {
            $s['reboot'] = $saved['updates_reboot'] === '1';
        }

        return $s;
    }

    /**
     * Check settings from a form, the API or the CLI, over the current ones.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array{os: string, php: bool, hour: int, exclude: list<string>, panel: string, reboot: bool}, 1: array<string, string>}
     */
    public function validate(array $input): array
    {
        $s = $this->get();
        $errors = [];
        $text = static fn(mixed $v): string => \trim(\is_scalar($v) ? (string) $v : '');
        $bool = static fn(mixed $v): bool => $v === true || \in_array(\strtolower($text($v)), ['1', 'on', 'true', 'yes'], true);

        if (\array_key_exists('os', $input)) {
            if (\in_array($text($input['os']), self::OS, true)) {
                $s['os'] = $text($input['os']);
            } else {
                $errors['os'] = 'Operations.updates_error_os';
            }
        }

        if (\array_key_exists('php', $input)) {
            $s['php'] = $bool($input['php']);
        }

        if (\array_key_exists('hour', $input)) {
            $h = $text($input['hour']);
            if (\ctype_digit($h) && (int) $h <= 23) {
                $s['hour'] = (int) $h;
            } else {
                $errors['hour'] = 'Operations.updates_error_hour';
            }
        }

        if (\array_key_exists('exclude', $input)) {
            $list = \is_array($input['exclude']) ? $input['exclude'] : \preg_split('/[\s,]+/', $text($input['exclude']));
            $patterns = [];

            foreach ((array) $list as $p) {
                $p = $text($p);

                if ($p === '') {
                    continue;
                }

                if (\preg_match(self::PATTERN, $p) !== 1) {
                    $errors['exclude'] = 'Operations.updates_error_exclude';

                    break;
                }

                $patterns[$p] = true;
            }

            if (\count($patterns) > 50) {
                $errors['exclude'] = 'Operations.updates_error_exclude';
            }

            $s['exclude'] = \array_keys($patterns);
        }

        if (\array_key_exists('panel', $input)) {
            if (\in_array($text($input['panel']), self::PANEL, true)) {
                $s['panel'] = $text($input['panel']);
            } else {
                $errors['panel'] = 'Operations.updates_error_panel';
            }
        }

        if (\array_key_exists('reboot', $input)) {
            $s['reboot'] = $bool($input['reboot']);
        }

        return [$s, $errors];
    }

    /** @param array{os: string, php: bool, hour: int, exclude: list<string>, panel: string, reboot: bool} $s */
    public function save(array $s, ?Identity $actor, ?Request $request): void
    {
        $values = [
            'updates_os' => $s['os'],
            'updates_php' => $s['php'] ? '1' : '0',
            'updates_hour' => (string) $s['hour'],
            'updates_exclude' => \implode(' ', $s['exclude']),
            'updates_panel' => $s['panel'],
            'updates_reboot' => $s['reboot'] ? '1' : '0',
        ];

        foreach ($values as $name => $value) {
            if ($this->table()->where('name', $name)->first() === null) {
                $this->table()->insert(['name' => $name, 'value' => $value]);
            } else {
                $this->table()->where('name', $name)->update(['value' => $value]);
            }
        }

        $this->audit->record('updates.settings', $actor, $request, 'server', AuditLog::OK, ['exclude' => \implode(' ', $s['exclude'])] + $s);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_settings');
    }
}
