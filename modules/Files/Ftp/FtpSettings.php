<?php

declare(strict_types=1);

namespace App\Modules\Files\Ftp;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * The server's FTP settings: whether TLS is needed, the passive ports (and
 * the address given out for them behind NAT), and how many clients may
 * connect. The agent writes them into pure-ftpd.conf; they are kept here
 * only once it has.
 */
final class FtpSettings
{
    public const TLS_MODES = ['optional', 'required', 'strict'];

    /** @var array{tls: string, passive_from: int, passive_to: int, passive_ip: string, max_clients: int, max_per_ip: int} */
    public const DEFAULTS = ['tls' => 'required', 'passive_from' => 30000, 'passive_to' => 30100, 'passive_ip' => '', 'max_clients' => 200, 'max_per_ip' => 8];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{tls: string, passive_from: int, passive_to: int, passive_ip: string, max_clients: int, max_per_ip: int} */
    public function get(): array
    {
        $saved = [];

        foreach ($this->table()->get() as $row) {
            $saved[(string) $row['name']] = (string) $row['value'];
        }

        // Saved values are text in the table.
        $int = static fn(string $name): int => (int) ($saved[$name] ?? self::DEFAULTS[$name]);

        return [
            'tls' => $saved['tls'] ?? self::DEFAULTS['tls'],
            'passive_from' => $int('passive_from'),
            'passive_to' => $int('passive_to'),
            'passive_ip' => $saved['passive_ip'] ?? self::DEFAULTS['passive_ip'],
            'max_clients' => $int('max_clients'),
            'max_per_ip' => $int('max_per_ip'),
        ];
    }

    /**
     * Check settings typed in a form, the API or the CLI, over the current ones.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} the settings, and errors by field (language keys)
     */
    public function validate(array $input): array
    {
        $s = $this->get();
        $errors = [];
        $text = static fn(mixed $v): string => \trim(\is_scalar($v) ? (string) $v : '');

        foreach (['passive_from', 'passive_to', 'max_clients', 'max_per_ip'] as $name) {
            if (!isset($input[$name])) {
                continue;
            }

            $value = $text($input[$name]);

            if (!\ctype_digit($value) || \strlen($value) > 5) {
                $errors[$name] = 'Files.ftp_error_number';
            } else {
                $s[$name] = (int) $value;
            }
        }

        foreach (['tls', 'passive_ip'] as $name) {
            if (isset($input[$name])) {
                $s[$name] = $text($input[$name]);
            }
        }

        if (!\in_array($s['tls'], self::TLS_MODES, true)) {
            $errors['tls'] = 'Files.ftp_error_tls';
        }

        if (!isset($errors['passive_from']) && !isset($errors['passive_to'])
            && ($s['passive_from'] < 1024 || $s['passive_to'] > 65535 || $s['passive_from'] > $s['passive_to'] || $s['passive_to'] - $s['passive_from'] > 10000)) {
            $errors['passive_from'] = 'Files.ftp_error_ports';
        }

        if ($s['passive_ip'] !== '' && \filter_var($s['passive_ip'], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            $errors['passive_ip'] = 'Files.ftp_error_ip';
        }

        if (!isset($errors['max_clients']) && ($s['max_clients'] < 1 || $s['max_clients'] > 10000)) {
            $errors['max_clients'] = 'Files.ftp_error_clients';
        }

        if (!isset($errors['max_per_ip']) && !isset($errors['max_clients']) && ($s['max_per_ip'] < 1 || $s['max_per_ip'] > $s['max_clients'])) {
            $errors['max_per_ip'] = 'Files.ftp_error_per_ip';
        }

        return [$s, $errors];
    }

    /**
     * Save new settings: the agent writes them and restarts Pure-FTPd; they
     * are kept only if it did.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: bool, 1: array<string, string>, 2: string|null} saved, field errors, and why it failed (a language key or the agent's words)
     */
    public function save(?Identity $actor, ?Request $request, array $input): array
    {
        [$s, $errors] = $this->validate($input);

        if ($errors !== []) {
            return [false, $errors, null];
        }

        [$ok, $why] = $this->apply($s);
        $this->audit->record('ftp.settings', $actor, $request, 'ftp', $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? $s : $s + ['error' => $why]);

        if ($ok) {
            $this->table()->upsert(\array_map(static fn(string $k, mixed $v): array => ['name' => $k, 'value' => (string) $v], \array_keys($s), $s), ['name'], ['value']);
        }

        return [$ok, [], $ok ? null : $why];
    }

    /**
     * Send settings to the agent (the current ones when none are given).
     *
     * @param array<string, mixed>|null $s
     *
     * @return array{0: bool, 1: string|null}
     */
    public function apply(?array $s = null): array
    {
        $s ??= $this->get();

        try {
            $reply = $this->agent->call('ftp.settings', $s, id: Protocol::newId(), timeout: 70.0);
        } catch (AgentUnavailable) {
            return [false, 'Files.ftp_error_unreachable'];
        }

        if (!$reply->done()) {
            return [false, $reply->errorCode === 'ftp_off' ? 'Files.ftp_error_off' : (string) ($reply->errorMessage ?? 'Files.ftp_error_failed')];
        }

        return [true, null];
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ftp_settings');
    }
}
