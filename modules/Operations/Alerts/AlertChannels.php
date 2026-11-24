<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Engine\Security\Encrypter;
use App\Modules\Audit\AuditLog;

/**
 * Where alerts go. A channel is an email address, a Telegram chat (through a
 * bot), a Slack-style incoming webhook, or a webhook of the owner's that gets
 * the alert as signed JSON. What is secret (a bot token, a webhook's address
 * -- for Slack it is the whole credential -- and a signing key) is sealed
 * with the panel's key and never shown again; what is shown is `target`: the
 * address, the chat, the web address's host.
 */
final class AlertChannels
{
    public const KINDS = ['email', 'telegram', 'slack', 'webhook'];

    /** The kinds of alert a channel may be limited to. */
    public const CATEGORIES = ['services', 'updates', 'backups', 'ssl', 'resources', 'security'];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    private const CONTEXT = 'ops.alert-channel';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Encrypter $encrypter,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> without secrets */
    public function all(): array
    {
        return \array_map(self::shown(...), $this->table()->orderBy('name')->get());
    }

    /** @return array<string, mixed>|null without secrets */
    public function find(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : self::shown($row);
    }

    /**
     * The channels an alert goes to, with their secrets opened.
     *
     * @return list<array<string, mixed>>
     */
    public function receiving(string $category, string $severity): array
    {
        $out = [];

        foreach ($this->table()->where('enabled', 1)->orderBy('id')->get() as $row) {
            $categories = \preg_split('/\s+/', (string) $row['categories'], -1, \PREG_SPLIT_NO_EMPTY) ?: [];

            if (!\in_array('*', $categories, true) && !\in_array($category, $categories, true)) {
                continue;
            }

            if (self::rank($severity) < self::rank((string) $row['min_severity'])) {
                continue;
            }

            $out[] = $this->opened($row);
        }

        return $out;
    }

    /**
     * One channel with its secrets opened (for a test).
     *
     * @return array<string, mixed>|null
     */
    public function open(int $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : $this->opened($row);
    }

    /**
     * Check what a form, the API or the CLI sent. For a channel that exists
     * the secrets left out are kept.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing the channel as open() gives it
     *
     * @return array{0: array{name: string, kind: string, target: string, config: array<string, string>, categories: string, min_severity: string, enabled: bool}, 1: array<string, string>}
     */
    public function validate(array $input, ?array $existing = null): array
    {
        $errors = [];
        $text = static fn(string $k): string => \trim(\is_scalar($input[$k] ?? null) ? (string) $input[$k] : '');
        $kind = $existing === null ? $text('kind') : (string) $existing['kind'];
        $name = $existing === null || \array_key_exists('name', $input) ? $text('name') : (string) $existing['name'];
        $config = $existing === null ? [] : (array) $existing['config'];

        if ($name === '' || \mb_strlen($name) > 80 || \preg_match('/[\x00-\x1f]/', $name) === 1) {
            $errors['name'] = 'Operations.alerts_error_name';
        }

        if (!\in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Operations.alerts_error_kind';
            $kind = 'email';
        }

        $target = '';

        switch ($kind) {
            case 'email':
                $to = $text('to');

                if ($to !== '' || $existing === null) {
                    if (\filter_var($to, \FILTER_VALIDATE_EMAIL) === false || \strlen($to) > 200 || \str_contains($to, ',')) {
                        $errors['to'] = 'Operations.alerts_error_email';
                    } else {
                        $config = ['to' => $to];
                    }
                }

                $target = (string) ($config['to'] ?? '');

                break;
            case 'telegram':
                $token = $text('token');
                $chat = $text('chat');

                if ($token !== '' || $existing === null) {
                    if (\preg_match('/^\d{5,12}:[A-Za-z0-9_-]{30,50}$/', $token) !== 1) {
                        $errors['token'] = 'Operations.alerts_error_token';
                    } else {
                        $config['token'] = $token;
                    }
                }

                if ($chat !== '' || $existing === null) {
                    if (\preg_match('/^(-?\d{3,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $chat) !== 1) {
                        $errors['chat'] = 'Operations.alerts_error_chat';
                    } else {
                        $config['chat'] = $chat;
                    }
                }

                $target = (string) ($config['chat'] ?? '');

                break;
            case 'slack':
            case 'webhook':
                $url = $text('url');

                if ($url !== '' || $existing === null) {
                    if (!self::goodUrl($url)) {
                        $errors['url'] = 'Operations.alerts_error_url';
                    } else {
                        $config['url'] = $url;
                    }
                }

                if ($kind === 'webhook' && \array_key_exists('signing_key', $input)) {
                    $key = $text('signing_key');

                    if ($key === '') {
                        // Left empty on a form: keep what is there. The API and CLI
                        // remove it with remove_signing_key.
                        if (!empty($input['remove_signing_key'])) {
                            unset($config['signing_key']);
                        }
                    } elseif (\strlen($key) < 16 || \strlen($key) > 200 || \preg_match('/[\x00-\x20\x7f]/', $key) === 1) {
                        $errors['signing_key'] = 'Operations.alerts_error_signing_key';
                    } else {
                        $config['signing_key'] = $key;
                    }
                }

                $target = \parse_url((string) ($config['url'] ?? ''), \PHP_URL_HOST) ?: '';

                break;
        }

        $categories = $existing === null ? '*' : (string) $existing['categories'];

        if (\array_key_exists('categories', $input)) {
            $list = \is_array($input['categories']) ? $input['categories'] : \preg_split('/[\s,]+/', $text('categories'), -1, \PREG_SPLIT_NO_EMPTY);
            $list = \array_values(\array_unique(\array_map(static fn(mixed $c): string => \is_string($c) ? \trim($c) : '', (array) $list)));

            if ($list === [] || \in_array('*', $list, true) || \count(\array_intersect($list, self::CATEGORIES)) === \count(self::CATEGORIES)) {
                $categories = '*';
            } elseif (\array_diff($list, self::CATEGORIES) !== []) {
                $errors['categories'] = 'Operations.alerts_error_categories';
            } else {
                $categories = \implode(' ', $list);
            }
        }

        $severity = $existing === null ? 'warning' : (string) $existing['min_severity'];

        if (\array_key_exists('min_severity', $input)) {
            if (\in_array($text('min_severity'), self::SEVERITIES, true)) {
                $severity = $text('min_severity');
            } else {
                $errors['min_severity'] = 'Operations.alerts_error_severity';
            }
        }

        $enabled = $existing === null || (bool) $existing['enabled'];

        if (\array_key_exists('enabled', $input)) {
            $enabled = $input['enabled'] === true || \in_array(\strtolower($text('enabled')), ['1', 'on', 'true', 'yes'], true);
        }

        return [['name' => $name, 'kind' => $kind, 'target' => \mb_substr($target, 0, 255), 'config' => $config, 'categories' => $categories, 'min_severity' => $severity, 'enabled' => $enabled], $errors];
    }

    /**
     * @param array{name: string, kind: string, target: string, config: array<string, string>, categories: string, min_severity: string, enabled: bool} $c
     *
     * @return int the new channel's id
     */
    public function create(array $c, ?Identity $actor, ?Request $request): int
    {
        $id = (int) $this->table()->insert($this->row($c) + ['created_at' => \gmdate('Y-m-d H:i:s')], 'id');
        $this->audit->record('alerts.channel_add', $actor, $request, $c['name'], AuditLog::OK, ['kind' => $c['kind'], 'target' => $c['target']]);

        return $id;
    }

    /** @param array{name: string, kind: string, target: string, config: array<string, string>, categories: string, min_severity: string, enabled: bool} $c */
    public function update(int $id, array $c, ?Identity $actor, ?Request $request): void
    {
        $this->table()->where('id', $id)->update($this->row($c));
        $this->audit->record('alerts.channel_change', $actor, $request, $c['name'], AuditLog::OK, ['kind' => $c['kind'], 'target' => $c['target'], 'enabled' => $c['enabled'] ? 'yes' : 'no']);
    }

    public function remove(int $id, ?Identity $actor, ?Request $request): bool
    {
        $row = $this->table()->where('id', $id)->first();

        if ($row === null) {
            return false;
        }

        $this->table()->where('id', $id)->delete();
        $this->connections->connection()->table('ops_alert_deliveries')->where('channel_id', $id)->delete();
        $this->audit->record('alerts.channel_remove', $actor, $request, (string) $row['name'], AuditLog::OK, ['kind' => (string) $row['kind']]);

        return true;
    }

    /** What a delivery did, for the channel's line on the page. */
    public function delivered(int $id, bool $ok, string $error): void
    {
        $this->table()->where('id', $id)->update($ok
            ? ['last_ok_at' => \gmdate('Y-m-d H:i:s'), 'last_error' => null]
            : ['last_error' => \mb_substr($error, 0, 300)]);
    }

    public static function rank(string $severity): int
    {
        $i = \array_search($severity, self::SEVERITIES, true);

        return $i === false ? 0 : (int) $i;
    }

    /** https, a host, no credentials in it, no fragment. */
    public static function goodUrl(string $url): bool
    {
        if (\strlen($url) > 500 || \preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        $p = \parse_url($url);

        return \is_array($p)
            && ($p['scheme'] ?? '') === 'https'
            && isset($p['host']) && \preg_match('/^[A-Za-z0-9.-]+$/', $p['host']) === 1 && \str_contains($p['host'], '.')
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
            && (!isset($p['port']) || ($p['port'] > 0 && $p['port'] < 65536));
    }

    /**
     * @param array{name: string, kind: string, target: string, config: array<string, string>, categories: string, min_severity: string, enabled: bool} $c
     *
     * @return array<string, mixed>
     */
    private function row(array $c): array
    {
        return [
            'name' => $c['name'],
            'kind' => $c['kind'],
            'target' => $c['target'],
            'secret' => $this->encrypter->encrypt(\json_encode($c['config'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), self::CONTEXT),
            'categories' => $c['categories'],
            'min_severity' => $c['min_severity'],
            'enabled' => $c['enabled'] ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shown(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'target' => (string) $row['target'],
            'categories' => (string) $row['categories'],
            'min_severity' => (string) $row['min_severity'],
            'enabled' => (bool) $row['enabled'],
            'created_at' => (string) $row['created_at'],
            'last_ok_at' => $row['last_ok_at'] === null ? null : (string) $row['last_ok_at'],
            'last_error' => $row['last_error'] === null ? null : (string) $row['last_error'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function opened(array $row): array
    {
        $json = $row['secret'] === null ? null : $this->encrypter->decrypt((string) $row['secret'], self::CONTEXT);
        $config = \is_string($json) ? \json_decode($json, true) : null;

        return self::shown($row) + ['config' => \is_array($config) ? $config : []];
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_alert_channels');
    }
}
