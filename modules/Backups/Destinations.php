<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Where backups go: a local folder, an SFTP server, an S3-compatible store
 * or Backblaze B2. A destination is kept only once the agent has reached it
 * (and made its repository). Its secrets go to the agent and stay there;
 * here, a destination remembers only what the Admin panel shows.
 */
final class Destinations
{
    public const TYPES = ['local', 'sftp', 's3', 'b2'];

    /**
     * The settings each type takes (secrets apart).
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'local' => ['path'],
        'sftp' => ['host', 'port', 'user', 'path'],
        's3' => ['endpoint', 'scheme', 'region', 'bucket', 'prefix'],
        'b2' => ['bucket', 'prefix'],
    ];

    /** Types that need an access key and a secret. */
    public const SECRET_TYPES = ['s3', 'b2'];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> with their settings decoded */
    public function all(): array
    {
        return \array_map(self::decode(...), $this->table()->orderBy('name')->get());
    }

    /** @return array<string, mixed>|null */
    public function find(int|string $idOrName): ?array
    {
        $q = $this->table();
        $row = (\is_int($idOrName) || \ctype_digit($idOrName) ? $q->where('id', (int) $idOrName) : $q->where('name', $idOrName))->first();

        return $row === null ? null : self::decode($row);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return \array_map(static fn(array $d): int => (int) $d['id'], $this->table()->get());
    }

    /** @return list<string> the settings a type takes */
    public static function fieldsOf(string $type): array
    {
        return self::FIELDS[$type] ?? [];
    }

    /** The agent's name for a destination. */
    public static function agentId(int $id): string
    {
        return 'd' . $id;
    }

    /**
     * Check a destination typed in a form, the API or the CLI.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current the destination being changed
     *
     * @return array{0: array{name: string, type: string, settings: array<string, string|int>, access_key: string, secret_key: string, password: string}, 1: array<string, string>}
     */
    public function validate(array $input, ?array $current = null): array
    {
        $text = static fn(string $k): string => \trim(\is_scalar($input[$k] ?? null) ? (string) $input[$k] : '');
        $errors = [];
        $name = $text('name') !== '' || $current === null ? $text('name') : (string) $current['name'];
        $type = $current !== null ? (string) $current['type'] : $text('type');

        if (\preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._()-]{0,63}$/u', $name) !== 1) {
            $errors['name'] = 'Backups.error_name';
        } elseif (($other = $this->find($name)) !== null && ($current === null || (int) $other['id'] !== (int) $current['id'])) {
            $errors['name'] = 'Backups.error_name_taken';
        }

        if (!\in_array($type, self::TYPES, true)) {
            return [['name' => $name, 'type' => $type, 'settings' => [], 'access_key' => '', 'secret_key' => '', 'password' => ''], $errors + ['type' => 'Backups.error_type']];
        }

        $settings = [];

        foreach (self::fieldsOf($type) as $field) {
            $settings[$field] = \array_key_exists($field, $input) || $current === null ? $text($field) : (string) ($current['settings'][$field] ?? '');
        }

        if (isset($settings['path']) && $settings['path'] !== '/') {
            $settings['path'] = \rtrim((string) $settings['path'], '/');
        }

        $path = static fn(string $p): bool => \preg_match('#^/[A-Za-z0-9._/-]{1,399}$#', $p) === 1 && !\str_contains($p, '..') && $p !== '/';
        $host = static fn(string $h): bool => \preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $h) === 1;

        switch ($type) {
            case 'local':
                if (!$path((string) $settings['path'])) {
                    $errors['path'] = 'Backups.error_path';
                } elseif (\preg_match('#^/(home|etc|proc|sys|dev|usr|run|boot)(/|$)|^/var/lib/mysql(/|$)#', (string) $settings['path']) === 1) {
                    $errors['path'] = 'Backups.error_path_system';
                }
                break;
            case 'sftp':
                $settings['port'] = (string) $settings['port'] === '' ? 22 : (int) $settings['port'];

                if (!$host((string) $settings['host'])) {
                    $errors['host'] = 'Backups.error_host';
                }
                if (\preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string) $settings['user']) !== 1) {
                    $errors['user'] = 'Backups.error_user';
                }
                if (!\ctype_digit((string) $settings['port']) || $settings['port'] < 1 || $settings['port'] > 65535) {
                    $errors['port'] = 'Backups.error_port';
                }
                if (!$path((string) $settings['path'])) {
                    $errors['path'] = 'Backups.error_path';
                }
                break;
            case 's3':
                $settings['scheme'] = $settings['scheme'] === '' ? 'https' : $settings['scheme'];
                [$endpointHost, $endpointPort] = \explode(':', (string) $settings['endpoint'], 2) + [1 => ''];

                if (!$host($endpointHost) || ($endpointPort !== '' && (!\ctype_digit($endpointPort) || (int) $endpointPort > 65535))) {
                    $errors['endpoint'] = 'Backups.error_endpoint';
                }
                if (!\in_array($settings['scheme'], ['https', 'http'], true)) {
                    $errors['scheme'] = 'Backups.error_scheme';
                }
                if (\preg_match('/^[a-z0-9-]{0,40}$/', (string) $settings['region']) !== 1) {
                    $errors['region'] = 'Backups.error_region';
                }
                if (\preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', (string) $settings['bucket']) !== 1) {
                    $errors['bucket'] = 'Backups.error_bucket';
                }
                break;
            case 'b2':
                if (\preg_match('/^[A-Za-z0-9-]{6,50}$/', (string) $settings['bucket']) !== 1) {
                    $errors['bucket'] = 'Backups.error_bucket';
                }
                break;
        }

        if (isset($settings['prefix'])) {
            $settings['prefix'] = \trim((string) $settings['prefix'], '/');

            if (\preg_match('#^[A-Za-z0-9._/-]{0,200}$#', $settings['prefix']) !== 1 || \str_contains($settings['prefix'], '..')) {
                $errors['prefix'] = 'Backups.error_prefix';
            }
        }

        $access = $text('access_key');
        $secret = $text('secret_key');

        if (\in_array($type, self::SECRET_TYPES, true)) {
            foreach (['access_key' => $access, 'secret_key' => $secret] as $field => $value) {
                if ($value === '' && $current === null) {
                    $errors[$field] = 'Backups.error_secret_needed';
                } elseif ($value !== '' && \preg_match('/^[\x21-\x7e]{1,256}$/', $value) !== 1) {
                    $errors[$field] = 'Backups.error_secret';
                }
            }
        }

        // A repository another server made opens with that server's password
        // (its `lpa backup-password`); only when the destination is added.
        $password = $current === null ? $text('password') : '';

        if ($password !== '' && \preg_match('/^[!-~]{1,256}$/', $password) !== 1) {
            $errors['password'] = 'Backups.error_secret';
        }

        return [['name' => $name, 'type' => $type, 'settings' => $settings, 'access_key' => $access, 'secret_key' => $secret, 'password' => $password], $errors];
    }

    /**
     * Add a destination, or change one: the agent reaches it (making its
     * repository the first time) before anything is kept.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $current
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, string>, 2: string|null} the destination, field errors, and why the agent refused (a language key or its words)
     */
    public function save(?Identity $actor, ?Request $request, array $input, ?array $current = null): array
    {
        [$d, $errors] = $this->validate($input, $current);

        if ($errors !== []) {
            return [null, $errors, null];
        }

        $now = Accounts::now();
        $id = $current === null
            ? (int) $this->table()->insert(['name' => $d['name'], 'type' => $d['type'], 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now], 'id')
            : (int) $current['id'];
        $args = ['id' => self::agentId($id), 'type' => $d['type']] + $d['settings']
            + \array_filter(['access_key' => $d['access_key'], 'secret_key' => $d['secret_key'], 'password' => $d['password']], static fn(string $v): bool => $v !== '');

        try {
            $reply = $this->agent->call('backup.destination', $args, id: Protocol::newId(), timeout: 150.0);
            $why = $reply->done() ? null : match ($reply->errorCode) {
                'backup_off' => 'Backups.error_off',
                'backup_wrong_password' => $d['password'] !== '' ? 'Backups.error_password_wrong' : 'Backups.error_wrong_password',
                'backup_busy' => 'Backups.error_busy',
                default => (string) ($reply->errorMessage ?? 'Backups.error_failed'),
            };
        } catch (AgentUnavailable) {
            $why = 'Backups.error_unreachable';
            $reply = null;
        }

        $subject = $d['name'];
        $details = ['type' => $d['type']] + $d['settings'] + ($d['password'] !== '' ? ['existing_repository' => true] : []);

        if ($why !== null || $reply === null) {
            if ($current === null) {
                $this->table()->where('id', $id)->delete();
            }

            $this->audit->record($current === null ? 'backup.destination_add' : 'backup.destination_change', $actor, $request, $subject, AuditLog::FAILED, $details + ['error' => $why]);

            return [null, [], $why];
        }

        $result = $reply->result;
        $this->table()->where('id', $id)->update([
            'name' => $d['name'],
            'settings' => \json_encode($d['settings'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'public_key' => \is_string($result['public_key'] ?? null) && $result['public_key'] !== '' ? $result['public_key'] : null,
            'snapshots' => (int) ($result['snapshots'] ?? 0),
            'checked_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit->record($current === null ? 'backup.destination_add' : 'backup.destination_change', $actor, $request, $subject, AuditLog::OK, $details + ['created' => (bool) ($result['created'] ?? false)]);

        return [$this->find($id), [], null];
    }

    /**
     * Forget a destination (what is there stays there).
     *
     * @param array<string, mixed> $d
     *
     * @return string|null why not (a language key or the agent's words)
     */
    public function remove(?Identity $actor, ?Request $request, array $d): ?string
    {
        try {
            $reply = $this->agent->call('backup.destination_remove', ['id' => self::agentId((int) $d['id'])], id: Protocol::newId(), timeout: 40.0);
            $why = $reply->done() || $reply->errorCode === 'backup_no_destination' ? null
                : ($reply->errorCode === 'backup_busy' ? 'Backups.error_busy' : (string) ($reply->errorMessage ?? 'Backups.error_failed'));
        } catch (AgentUnavailable) {
            $why = 'Backups.error_unreachable';
        }

        $this->audit->record('backup.destination_remove', $actor, $request, (string) $d['name'], $why === null ? AuditLog::OK : AuditLog::FAILED, $why === null ? ['type' => $d['type']] : ['error' => $why]);

        if ($why === null) {
            $this->table()->where('id', (int) $d['id'])->delete();
        }

        return $why;
    }

    /**
     * Where a destination is, in one line for the panel.
     *
     * @param array<string, mixed> $d
     */
    public static function describe(array $d): string
    {
        $s = \is_array($d['settings'] ?? null) ? $d['settings'] : [];

        return match ($d['type']) {
            'local' => (string) ($s['path'] ?? ''),
            'sftp' => \sprintf('%s@%s:%s%s', $s['user'] ?? '', $s['host'] ?? '', (int) ($s['port'] ?? 22) === 22 ? '' : ($s['port'] . ':'), $s['path'] ?? ''),
            's3' => \sprintf('%s/%s%s', $s['endpoint'] ?? '', $s['bucket'] ?? '', ($s['prefix'] ?? '') === '' ? '' : '/' . $s['prefix']),
            'b2' => \sprintf('b2:%s%s', $s['bucket'] ?? '', ($s['prefix'] ?? '') === '' ? '' : '/' . $s['prefix']),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $settings = \json_decode((string) $row['settings'], true);
        $row['settings'] = \is_array($settings) ? $settings : [];

        return $row;
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('backup_destinations');
    }
}
