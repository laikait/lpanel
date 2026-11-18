<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\Outcome;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * Makes an account again from the description in one of its snapshots
 * (AccountExport): on this server after it was terminated, or on another.
 *
 * plan() says whether it can be done here: the username, domains,
 * databases, database users and FTP logins must be free, and the package is
 * found by its name and reseller (or made from the snapshot's). create()
 * then writes the owner's login (with the password it had), the account and
 * its modules' rows, with new ids, and asks the server to make it; each
 * module then applies its part (accounts.changed account.restore).
 * Certificates are not in a snapshot: AutoSSL issues new ones.
 */
final class AccountImport
{
    /** Columns pointing at another imported table's rows. */
    private const REFS = [
        'domains' => ['parent_id' => 'domains'],
        'ssl_sites' => ['domain_id' => 'domains'],
        'web_redirects' => ['domain_id' => 'domains'],
        'web_protected' => ['domain_id' => 'domains'],
        'db_grants' => ['user_id' => 'db_users', 'database_id' => 'db_databases'],
        'dns_records' => ['zone_id' => 'dns_zones', 'domain_id' => 'domains'],
        'mail_domains' => ['domain_id' => 'domains'],
        'mail_boxes' => ['mail_domain_id' => 'mail_domains'],
        'mail_forwarders' => ['mail_domain_id' => 'mail_domains'],
        'mail_responders' => ['mailbox_id' => 'mail_boxes'],
        'mail_filters' => ['mailbox_id' => 'mail_boxes'],
    ];

    /** References that may be left empty when what they pointed at is not there. */
    private const OPTIONAL_REFS = ['domains.parent_id', 'dns_records.domain_id', 'mail_filters.mailbox_id'];

    /** What the old server had made, which this one has not (yet). */
    private const RESET = [
        'ssl_sites' => [
            'cert_id' => null, 'source' => null, 'names' => null, 'issuer' => null, 'serial' => null, 'key_type' => null,
            'self_signed' => false, 'authority' => null, 'not_before' => null, 'not_after' => null, 'excluded' => null,
            'last_error' => null, 'failures' => 0, 'checked_at' => null, 'retry_at' => null,
        ],
        'dns_zones' => ['revision' => 0, 'applied_revision' => 0, 'op_id' => null, 'op_args' => null, 'state' => 'pending', 'error' => null, 'applied_at' => null],
        'db_databases' => ['size_bytes' => 0],
        'mail_boxes' => ['used_bytes' => 0],
    ];

    /** Names that must be free here, by table and column, with the language key that says so. */
    private const UNIQUE = [
        ['domains', 'name', 'Backups.import_domain_taken'],
        ['dns_zones', 'name', 'Backups.import_domain_taken'],
        ['mail_domains', 'name', 'Backups.import_domain_taken'],
        ['db_databases', 'name', 'Backups.import_database_taken'],
        ['db_users', 'name', 'Backups.import_database_taken'],
        ['ftp_users', 'login', 'Backups.import_ftp_taken'],
    ];

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Accounts $accounts,
        private readonly Packages $packages,
        private readonly PanelUsers $users,
        private readonly Provisioner $provisioner,
    ) {}

    /**
     * Whether the account in a snapshot's description can be made here.
     *
     * @param array<mixed> $meta lpanel.json
     *
     * @return array{plan: array<string, mixed>|null, problems: list<array{0: string, 1: array<string, string>}>}
     *                                                                                                            the plan, or what stands in the way (language keys and their values)
     */
    public function plan(array $meta, ?int $packageId = null): array
    {
        $problems = [];
        $account = \is_array($meta['account'] ?? null) ? $meta['account'] : [];
        $login = \is_array($meta['login'] ?? null) ? $meta['login'] : [];
        $tables = \is_array($meta['tables'] ?? null) ? $meta['tables'] : [];
        $username = (string) ($account['username'] ?? '');
        $domain = (string) ($account['domain'] ?? '');

        if (!\in_array($meta['format'] ?? null, [1, 2], true) || \preg_match('/^[a-z][a-z0-9]{1,15}$/', $username) !== 1 || $domain === '' || ($login['password_hash'] ?? '') === '') {
            return ['plan' => null, 'problems' => [['Backups.import_unreadable', []]]];
        }

        if ($this->accounts->usernameTaken($username) || $this->users->find($username) !== null) {
            $problems[] = ['Backups.import_username_taken', ['name' => $username]];
        }

        if ($this->accounts->domainTaken($domain)) {
            $problems[] = ['Backups.import_domain_taken', ['name' => $domain]];
        }

        $db = $this->connections->connection();

        foreach (self::UNIQUE as [$table, $column, $key]) {
            foreach (\is_array($tables[$table] ?? null) ? $tables[$table] : [] as $row) {
                $name = \is_array($row) ? (string) ($row[$column] ?? '') : '';

                if ($name !== '' && $db->table($table)->where($column, $name)->first() !== null) {
                    $problems[] = [$key, ['name' => $name]];
                }
            }
        }

        // The reseller, by username; on a server without them, the account is the administrator's.
        $ownerId = null;
        $ownerName = \is_string($meta['owner_username'] ?? null) ? $meta['owner_username'] : null;

        if ($ownerName !== null) {
            $owner = $this->users->find($ownerName);
            $ownerId = $owner !== null && $owner['kind'] === Kind::Reseller->value ? (int) $owner['id'] : null;
        }

        $package = \is_array($meta['package'] ?? null) ? $meta['package'] : [];
        $chosen = $packageId !== null ? $this->packages->byId($packageId) : $this->packageNamed((string) ($package['name'] ?? ''), $ownerId);

        if ($packageId !== null && $chosen === null) {
            $problems[] = ['Backups.import_no_package', []];
        }

        if ($chosen === null && $packageId === null && ($package['name'] ?? '') === '') {
            $problems[] = ['Backups.import_no_package', []];
        }

        return [
            'plan' => $problems !== [] ? null : [
                'username' => $username,
                'domain' => $domain,
                'owner_id' => $ownerId,
                'owner_username' => $ownerId === null ? null : $ownerName,
                'package_id' => $chosen === null ? null : (int) $chosen['id'],
                'package_name' => (string) ($chosen['name'] ?? $package['name'] ?? ''),
                'make_package' => $chosen === null,
                'counts' => \array_map(static fn(mixed $rows): int => \is_array($rows) ? \count($rows) : 0, $tables),
            ],
            'problems' => $problems,
        ];
    }

    /**
     * Make the account. plan() must have said yes.
     *
     * @param array<mixed>         $meta
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $details for the audit log
     *
     * @return array{0: int, 1: Outcome}
     */
    public function create(?Identity $actor, ?Request $request, array $meta, array $plan, array $details = []): array
    {
        $packageId = $plan['package_id'] ?? null;

        if (!\is_int($packageId)) {
            $packageId = $this->makePackage(\is_array($meta['package'] ?? null) ? $meta['package'] : [], $plan['owner_id']);
        }

        $login = \is_array($meta['login'] ?? null) ? $meta['login'] : [];
        $loginId = (string) $this->connections->connection()->table('panel_users')->insert([
            'username' => PanelUsers::normalise((string) $plan['username']),
            'kind' => Kind::User->value,
            'name' => (string) ($login['name'] ?? $plan['username']),
            'email' => \is_string($login['email'] ?? null) && $login['email'] !== '' ? $login['email'] : null,
            'password_hash' => (string) $login['password_hash'],
            'parent_id' => $plan['owner_id'],
            'active' => true,
            'created_at' => Accounts::now(),
        ], 'id');

        $tables = \is_array($meta['tables'] ?? null) ? $meta['tables'] : [];

        return $this->provisioner->restore(
            $actor,
            $request,
            $loginId,
            ['username' => (string) $plan['username'], 'domain' => (string) $plan['domain'], 'package_id' => $packageId, 'owner_id' => $plan['owner_id']],
            fn(int $accountId) => $this->insertRows($accountId, $tables),
            fn(int $accountId) => $this->removeRows($accountId),
            $details,
        );
    }

    /**
     * Write the modules' rows, with new ids, in one transaction.
     *
     * @param array<mixed> $tables
     */
    public function insertRows(int $accountId, array $tables): void
    {
        $db = $this->connections->connection();

        $db->transaction(function () use ($db, $accountId, $tables): void {
            /** @var array<string, array<int, int>> $ids old id => new id, by table */
            $ids = [];
            $later = [];

            foreach (\array_keys(AccountExport::TABLES) as $table) {
                foreach (\is_array($tables[$table] ?? null) ? $tables[$table] : [] as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }

                    $old = (int) ($row['id'] ?? 0);
                    unset($row['id']);

                    if (\array_key_exists('account_id', $row)) {
                        $row['account_id'] = $accountId;
                    }

                    $skip = false;

                    foreach (self::REFS[$table] ?? [] as $column => $target) {
                        if (($row[$column] ?? null) === null) {
                            continue;
                        }

                        $new = $ids[$target][(int) $row[$column]] ?? null;

                        if ($new === null && $table === $target) {
                            // A subdomain listed before its parent: set once both are in.
                            $later[] = [$table, $column, $old, (int) $row[$column]];
                            $row[$column] = null;
                        } elseif ($new === null && \in_array($table . '.' . $column, self::OPTIONAL_REFS, true)) {
                            $row[$column] = null;
                        } elseif ($new === null) {
                            $skip = true;
                        } else {
                            $row[$column] = $new;
                        }
                    }

                    if ($skip) {
                        continue;
                    }

                    foreach (self::RESET[$table] ?? [] as $column => $value) {
                        if (\array_key_exists($column, $row)) {
                            $row[$column] = $value;
                        }
                    }

                    $ids[$table][$old] = (int) $db->table($table)->insert($row, 'id');
                }
            }

            foreach ($later as [$table, $column, $old, $ref]) {
                if (isset($ids[$table][$old], $ids[$table][$ref])) {
                    $db->table($table)->where('id', $ids[$table][$old])->update([$column => $ids[$table][$ref]]);
                }
            }
        });
    }

    /** Take an account's modules' rows away again (children first). */
    public function removeRows(int $accountId): void
    {
        $db = $this->connections->connection();
        $rows = (new AccountExport($this->connections, $this->packages))->tables($accountId);

        foreach (\array_reverse(AccountExport::TABLES, true) as $table => $_) {
            $ids = \array_map(static fn(array $r): int => (int) $r['id'], $rows[$table] ?? []);

            if ($ids !== []) {
                $db->table($table)->whereIn('id', $ids)->delete();
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function packageNamed(string $name, ?int $ownerId): ?array
    {
        if ($name === '') {
            return null;
        }

        $query = $this->connections->connection()->table('packages')->where('name', $name);
        $query = $ownerId === null ? $query->whereNull('owner_id') : $query->where('owner_id', $ownerId);

        return $query->first();
    }

    /**
     * Make the snapshot's package here, as it was.
     *
     * @param array<mixed> $package
     */
    private function makePackage(array $package, ?int $ownerId): int
    {
        unset($package['id']);
        $package['owner_id'] = $ownerId;

        if (\is_array($package['features'] ?? null)) {
            $package['features'] = \json_encode(\array_values($package['features']), \JSON_THROW_ON_ERROR);
        }
        $now = Accounts::now();

        foreach (['created_at', 'updated_at'] as $column) {
            if (\array_key_exists($column, $package)) {
                $package[$column] = $now;
            }
        }

        return (int) $this->connections->connection()->table('packages')->insert($package, 'id');
    }
}
