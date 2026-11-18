<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Database\ConnectionManager;
use App\Modules\Packages\Packages;

/**
 * The panel's description of an account, saved in each of its snapshots
 * (lpanel.json): enough to make the account again, on this server after it
 * was terminated or on another one (AccountImport). Its row, its package,
 * its owner's login (with the password hash, never a second factor's
 * secret), its reseller's username, and its rows in each module's tables.
 *
 * Mail, FTP and database passwords are there as the hashes the servers
 * check; the snapshot is encrypted by restic.
 */
final class AccountExport
{
    /**
     * An account's tables, parents before children. Each is found by its
     * account_id, or (when it has none) by a column pointing at a parent
     * table already listed. A table a server does not have is left out.
     *
     * @var array<string, array{0: string, 1: string|null}> table => [column, parent table or null for account_id]
     */
    public const TABLES = [
        'domains' => ['account_id', null],
        'php_settings' => ['account_id', null],
        'ssl_sites' => ['account_id', null],
        'web_redirects' => ['domain_id', 'domains'],
        'web_protected' => ['domain_id', 'domains'],
        'db_databases' => ['account_id', null],
        'db_users' => ['account_id', null],
        'db_grants' => ['user_id', 'db_users'],
        'db_hosts' => ['account_id', null],
        'dns_zones' => ['account_id', null],
        'dns_records' => ['zone_id', 'dns_zones'],
        'mail_domains' => ['account_id', null],
        'mail_boxes' => ['account_id', null],
        'mail_forwarders' => ['account_id', null],
        'mail_responders' => ['mailbox_id', 'mail_boxes'],
        'mail_filters' => ['account_id', null],
        'mail_spam' => ['account_id', null],
        'ftp_users' => ['account_id', null],
        'ssh_keys' => ['account_id', null],
    ];

    /** Login columns that do not leave the panel. */
    private const SECRET_LOGIN = ['totp_secret', 'recovery_codes', 'totp_step', 'session_epoch'];

    /** 2: child tables (redirects, protected folders, grants, records, autoresponders) and the reseller. */
    public const FORMAT = 2;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Packages $packages,
    ) {}

    /**
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    public function export(array $account): array
    {
        $db = $this->connections->connection();
        $login = $db->table('panel_users')->where('id', (int) $account['login_id'])->first();

        if ($login !== null) {
            $login = \array_diff_key($login, \array_flip(self::SECRET_LOGIN));
        }

        $owner = $account['owner_id'] === null ? null : $db->table('panel_users')->where('id', (int) $account['owner_id'])->first();

        return [
            'format' => self::FORMAT,
            'made_at' => \gmdate('Y-m-d\TH:i:s\Z'),
            'account' => $account,
            'package' => $this->packages->byId((int) $account['package_id']),
            'login' => $login,
            'owner_username' => $owner === null ? null : (string) $owner['username'],
            'tables' => $this->tables((int) $account['id']),
        ];
    }

    /**
     * An account's rows in every module's tables.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function tables(int $accountId): array
    {
        $db = $this->connections->connection();
        $tables = [];

        foreach (self::TABLES as $table => [$column, $parent]) {
            try {
                if ($parent === null) {
                    $rows = $db->table($table)->where('account_id', $accountId)->orderBy('id')->get();
                } else {
                    $ids = \array_map(static fn(array $r): int => (int) $r['id'], $tables[$parent] ?? []);
                    $rows = $ids === [] ? [] : $db->table($table)->whereIn($column, $ids)->orderBy('id')->get();
                }
            } catch (\Throwable) {
                // Not on this server.
                continue;
            }

            $tables[$table] = \array_values($rows);
        }

        return $tables;
    }
}
