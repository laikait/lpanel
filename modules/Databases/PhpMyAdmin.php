<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Auth\Identity;
use App\Engine\Config\Config;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * Signing in to phpMyAdmin from the User panel, with no second password.
 *
 * The agent makes (or renews) the account's own phpMyAdmin user,
 * "lpsso-<account>", with a fresh random password and every privilege on the
 * account's databases (db.sso). The panel writes that login into a one-time
 * ticket file and sends the browser to phpMyAdmin's signon script, which
 * reads the ticket, deletes it, and starts phpMyAdmin's session with it.
 *
 * Tickets live a minute, in a folder only the panel and phpMyAdmin's own PHP
 * pool (a separate system user) can read; the password is never stored by
 * the panel. Logins unused for LIFETIME are removed (expire()).
 */
final class PhpMyAdmin
{
    /** A ticket is good for this long. */
    public const TICKET_SECONDS = 60;

    /** The phpMyAdmin user is removed this long after its last sign-on. */
    public const LIFETIME_SECONDS = 8 * 3600;

    public function __construct(
        private readonly Databases $databases,
        private readonly DbSync $sync,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
        private readonly Config $config,
        private readonly Accounts $accounts,
    ) {}

    /**
     * Make a ticket for the account and return where to send the browser.
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: ?string, 1: ?string} [url, error key]
     */
    public function signOn(?Identity $actor, ?Request $request, array $account): array
    {
        if ($account['status'] !== Accounts::ACTIVE) {
            return [null, 'Databases.error_suspended'];
        }

        $dir = $this->ticketDir();

        if (!\is_dir($dir) || !\is_writable($dir)) {
            return [null, 'Databases.error_pma_missing'];
        }

        $names = \array_map(static fn(array $d): string => (string) $d['name'], $this->databases->databases((int) $account['id']));

        try {
            $reply = $this->agent->call('db.sso', ['username' => $account['username'], 'databases' => $names], id: Protocol::newId(), timeout: 30.0);
        } catch (AgentUnavailable) {
            return [null, 'Databases.error_unreachable'];
        }

        if (!$reply->done() || !\is_string($reply->result['user'] ?? null) || !\is_string($reply->result['password'] ?? null)) {
            $this->audit->record('database.phpmyadmin', $actor, $request, (string) $account['username'], AuditLog::FAILED, ['reason' => $reply->errorCode, 'error' => $reply->errorMessage]);

            return [null, 'Databases.error_pma_failed'];
        }

        $token = \bin2hex(\random_bytes(32));
        $path = $dir . '/' . $token;
        $ticket = \json_encode([
            'user' => $reply->result['user'],
            'password' => $reply->result['password'],
            'expires' => \time() + self::TICKET_SECONDS,
        ], \JSON_THROW_ON_ERROR);

        // Readable by phpMyAdmin's pool through the folder's group, and by no
        // one else.
        $old = \umask(0o137);

        try {
            if (@\file_put_contents($path, $ticket, \LOCK_EX) === false) {
                return [null, 'Databases.error_pma_missing'];
            }
        } finally {
            \umask($old);
        }

        @\chmod($path, 0o640);
        $this->sync->mark((int) $account['id'], ['sso_at' => Accounts::now()]);
        $this->audit->record('database.phpmyadmin', $actor, $request, (string) $account['username'], AuditLog::OK, []);

        return [\rtrim($this->url(), '/') . '/lpanel-signon.php?ticket=' . $token, null];
    }

    /**
     * Remove phpMyAdmin logins nobody has signed on with for LIFETIME, and
     * tickets nobody used.
     *
     * @return int logins removed
     */
    public function expire(): int
    {
        $removed = 0;
        $before = \gmdate('Y-m-d H:i:s', \time() - self::LIFETIME_SECONDS);

        foreach ($this->sync->rowsWhere('sso_at', '<', $before) as $row) {
            $account = $this->accountName((int) $row['account_id']);

            if ($account === null) {
                continue;
            }

            try {
                $reply = $this->agent->call('db.sso_revoke', ['username' => $account], id: Protocol::newId(), timeout: 30.0);
            } catch (AgentUnavailable) {
                break;
            }

            if ($reply->done()) {
                $this->sync->mark((int) $row['account_id'], ['sso_at' => null]);
                $removed++;
            }
        }

        foreach (\glob($this->ticketDir() . '/*') ?: [] as $file) {
            if (\preg_match('/^[0-9a-f]{64}$/', \basename($file)) === 1 && (int) @\filemtime($file) < \time() - 5 * self::TICKET_SECONDS) {
                @\unlink($file);
            }
        }

        return $removed;
    }

    public function url(): string
    {
        return (string) $this->config->get('Databases.phpmyadmin_url', '/phpmyadmin/');
    }

    public function ticketDir(): string
    {
        return \rtrim((string) $this->config->get('Databases.phpmyadmin_tickets', '/var/lib/lpanel-pma/tickets'), '/');
    }

    private function accountName(int $id): ?string
    {
        $row = $this->accounts->byId($id);

        return $row === null ? null : (string) $row['username'];
    }
}
