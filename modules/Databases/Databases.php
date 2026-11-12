<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * Each account's databases, database users, grants and remote hosts, as the
 * panel keeps them; and the rules their names and values must follow.
 *
 * Names carry the account's prefix ("bob_"), then lowercase letters, digits
 * and _: the agent checks the same, and never puts anything else into SQL.
 */
final class Databases
{
    /** Longest names MariaDB and MySQL both take. */
    public const MAX_DATABASE = 64;
    public const MAX_USER = 32;

    public const PASSWORD_MIN = 10;
    public const PASSWORD_MAX = 128;

    /** Privileges a user may hold on a database (ALL is every one of them). */
    public const PRIVILEGES = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'INDEX', 'ALTER',
        'CREATE TEMPORARY TABLES', 'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW',
        'CREATE ROUTINE', 'ALTER ROUTINE', 'EVENT', 'TRIGGER', 'REFERENCES',
    ];

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @param array<string, mixed> $account */
    public static function prefix(array $account): string
    {
        return $account['username'] . '_';
    }

    // -------------------------------------------------------------------------
    // Reading

    /** @return list<array<string, mixed>> the account's databases, by name */
    public function databases(int $accountId): array
    {
        return $this->table('db_databases')->where('account_id', $accountId)->orderBy('name')->get();
    }

    /** @return array<string, mixed>|null */
    public function database(int $accountId, int|string $idOrName): ?array
    {
        $q = $this->table('db_databases')->where('account_id', $accountId);

        return (\is_int($idOrName) ? $q->where('id', $idOrName) : $q->where('name', $idOrName))->first();
    }

    /**
     * The account's users, each with its grants: database name => privileges.
     *
     * @return list<array<string, mixed>>
     */
    public function users(int $accountId): array
    {
        $users = $this->table('db_users')->where('account_id', $accountId)->orderBy('name')->get();
        $names = [];

        foreach ($this->databases($accountId) as $db) {
            $names[(int) $db['id']] = (string) $db['name'];
        }

        foreach ($users as &$user) {
            $user['grants'] = [];

            foreach ($this->table('db_grants')->where('user_id', (int) $user['id'])->get() as $grant) {
                if (isset($names[(int) $grant['database_id']])) {
                    $user['grants'][$names[(int) $grant['database_id']]] = self::decodePrivileges((string) $grant['privileges']);
                }
            }

            \ksort($user['grants']);
        }
        unset($user);

        return $users;
    }

    /** @return array<string, mixed>|null */
    public function user(int $accountId, int|string $idOrName): ?array
    {
        $q = $this->table('db_users')->where('account_id', $accountId);

        return (\is_int($idOrName) ? $q->where('id', $idOrName) : $q->where('name', $idOrName))->first();
    }

    /** @return list<array<string, mixed>> */
    public function hosts(int $accountId): array
    {
        return $this->table('db_hosts')->where('account_id', $accountId)->orderBy('host')->get();
    }

    /** @return array<string, mixed>|null */
    public function host(int $accountId, int $id): ?array
    {
        return $this->table('db_hosts')->where('account_id', $accountId)->where('id', $id)->first();
    }

    /**
     * The account's whole state as db.apply takes it. Grants go as objects
     * so an empty map stays {} on the wire.
     *
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    public function state(array $account): array
    {
        $id = (int) $account['id'];

        return [
            'username' => (string) $account['username'],
            'databases' => \array_map(static fn(array $d): string => (string) $d['name'], $this->databases($id)),
            'users' => \array_map(static fn(array $u): array => [
                'name' => (string) $u['name'],
                'hash' => (string) $u['hash'],
                'grants' => (object) $u['grants'],
            ], $this->users($id)),
            'hosts' => \array_map(static fn(array $h): string => (string) $h['host'], $this->hosts($id)),
            'suspended' => $account['status'] === Accounts::SUSPENDED,
        ];
    }

    /** @return int how many databases the account has */
    public function count(int $accountId): int
    {
        return \count($this->databases($accountId));
    }

    // -------------------------------------------------------------------------
    // Rules

    /**
     * A new database's full name from what the owner typed (with or without
     * the prefix).
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: string, 1: ?string} [name, error key]
     */
    public function checkDatabaseName(array $account, string $input, ?int $exceptId = null): array
    {
        $name = self::full($account, $input);

        if (($why = self::nameError($account, $name, self::MAX_DATABASE)) !== null) {
            return [$name, $why];
        }

        $taken = $this->table('db_databases')->where('name', $name)->first();

        return [$name, $taken !== null && (int) $taken['id'] !== $exceptId ? 'Databases.error_db_taken' : null];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return array{0: string, 1: ?string} [name, error key]
     */
    public function checkUserName(array $account, string $input): array
    {
        $name = self::full($account, $input);

        if (($why = self::nameError($account, $name, self::MAX_USER)) !== null) {
            return [$name, $why];
        }

        return [$name, $this->table('db_users')->where('name', $name)->first() !== null ? 'Databases.error_user_taken' : null];
    }

    public static function passwordError(string $password): ?string
    {
        $length = \mb_strlen($password);

        return match (true) {
            $length < self::PASSWORD_MIN => 'Databases.error_password_short',
            $length > self::PASSWORD_MAX => 'Databases.error_password_long',
            \preg_match('/[\x00-\x1f\x7f]/', $password) === 1 => 'Databases.error_password_chars',
            default => null,
        };
    }

    /** MariaDB's mysql_native_password hash of a password, as it stores it. */
    public static function hash(string $password): string
    {
        return '*' . \strtoupper(\sha1(\sha1($password, true)));
    }

    /**
     * Privileges from a form or the API: "ALL", or a subset of PRIVILEGES.
     *
     * @param mixed $input list of names, or "ALL"
     *
     * @return array{0: list<string>, 1: ?string} [privileges, error key]
     */
    public static function checkPrivileges(mixed $input): array
    {
        $list = \is_string($input) ? \array_map('trim', \explode(',', $input)) : (\is_array($input) ? \array_values(\array_map(static fn(mixed $p): string => \is_scalar($p) ? \trim((string) $p) : '', $input)) : []);
        $list = \array_values(\array_filter(\array_map(static fn(string $p): string => \strtoupper(\str_replace('_', ' ', $p)), $list), static fn(string $p): bool => $p !== ''));

        if ($list === []) {
            return [[], 'Databases.error_no_privileges'];
        }

        if (\in_array('ALL', $list, true) || \in_array('ALL PRIVILEGES', $list, true)) {
            return [['ALL'], null];
        }

        foreach ($list as $p) {
            if (!\in_array($p, self::PRIVILEGES, true)) {
                return [[], 'Databases.error_privilege'];
            }
        }

        $list = \array_values(\array_unique($list));

        // Every privilege is the same as ALL.
        return [\count($list) === \count(self::PRIVILEGES) ? ['ALL'] : \array_values(\array_intersect(self::PRIVILEGES, $list)), null];
    }

    /**
     * A remote host as MariaDB takes it: an IPv4 or IPv6 address, an IPv4
     * network (203.0.113.0/24 becomes 203.0.113.0/255.255.255.0), a host
     * name, or a pattern with % (any characters).
     *
     * @return array{0: string, 1: ?string} [host, error key]
     */
    public static function checkHost(string $input): array
    {
        $host = \strtolower(\trim($input));

        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return [$host, 'Databases.error_host'];
        }

        if (\preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $host, $m) === 1) {
            $bits = (int) $m[2];

            if (\filter_var($m[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false || $bits < 8 || $bits > 32) {
                return [$host, 'Databases.error_host'];
            }

            $mask = (~0 << (32 - $bits)) & 0xFFFFFFFF;
            $network = \ip2long($m[1]) & $mask;

            return [\long2ip($network) . '/' . \long2ip($mask), null];
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host, null];
        }

        // Host names and % patterns ("%" alone is anywhere at all).
        if (\strlen($host) <= 60 && \preg_match('/^[a-z0-9%]([a-z0-9.%-]*[a-z0-9%])?$/', $host) === 1) {
            return [$host, null];
        }

        return [$host, 'Databases.error_host'];
    }

    // -------------------------------------------------------------------------
    // Writing

    public function addDatabase(int $accountId, string $name): int
    {
        return (int) $this->table('db_databases')->insert(['account_id' => $accountId, 'name' => $name, 'size_bytes' => 0, 'created_at' => Accounts::now()], 'id');
    }

    public function renameDatabase(int $id, string $name): void
    {
        $this->table('db_databases')->where('id', $id)->update(['name' => $name]);
    }

    public function deleteDatabase(int $id): void
    {
        $this->table('db_grants')->where('database_id', $id)->delete();
        $this->table('db_databases')->where('id', $id)->delete();
    }

    public function setSize(string $name, int $bytes): void
    {
        $this->table('db_databases')->where('name', $name)->update(['size_bytes' => $bytes]);
    }

    public function addUser(int $accountId, string $name, string $hash): int
    {
        $now = Accounts::now();

        return (int) $this->table('db_users')->insert(['account_id' => $accountId, 'name' => $name, 'hash' => $hash, 'created_at' => $now, 'updated_at' => $now], 'id');
    }

    public function setHash(int $userId, string $hash): void
    {
        $this->table('db_users')->where('id', $userId)->update(['hash' => $hash, 'updated_at' => Accounts::now()]);
    }

    public function deleteUser(int $userId): void
    {
        $this->table('db_grants')->where('user_id', $userId)->delete();
        $this->table('db_users')->where('id', $userId)->delete();
    }

    /** @return list<string>|null the user's privileges on the database, null for none */
    public function grant(int $userId, int $databaseId): ?array
    {
        $row = $this->table('db_grants')->where('user_id', $userId)->where('database_id', $databaseId)->first();

        return $row === null ? null : self::decodePrivileges((string) $row['privileges']);
    }

    /** @param list<string>|null $privileges null revokes */
    public function setGrant(int $userId, int $databaseId, ?array $privileges): void
    {
        $this->table('db_grants')->where('user_id', $userId)->where('database_id', $databaseId)->delete();

        if ($privileges !== null) {
            $this->table('db_grants')->insert(['user_id' => $userId, 'database_id' => $databaseId, 'privileges' => \json_encode($privileges, \JSON_THROW_ON_ERROR)]);
        }
    }

    public function addHost(int $accountId, string $host, ?string $note): int
    {
        return (int) $this->table('db_hosts')->insert(['account_id' => $accountId, 'host' => $host, 'note' => $note, 'created_at' => Accounts::now()], 'id');
    }

    public function hasHost(int $accountId, string $host): bool
    {
        return $this->table('db_hosts')->where('account_id', $accountId)->where('host', $host)->first() !== null;
    }

    public function deleteHost(int $id): void
    {
        $this->table('db_hosts')->where('id', $id)->delete();
    }

    /** Forget a terminated account; the agent dropped everything itself. */
    public function deleteAccount(int $accountId): void
    {
        foreach ($this->table('db_users')->where('account_id', $accountId)->get() as $user) {
            $this->table('db_grants')->where('user_id', (int) $user['id'])->delete();
        }

        foreach (['db_users', 'db_databases', 'db_hosts'] as $table) {
            $this->table($table)->where('account_id', $accountId)->delete();
        }
    }

    /** @return list<array<string, mixed>> every database on the server, with its account */
    public function all(): array
    {
        $usernames = [];

        foreach ($this->connections->connection()->table('accounts')->get() as $a) {
            $usernames[(int) $a['id']] = (string) $a['username'];
        }

        return \array_map(static fn(array $d): array => $d + ['username' => $usernames[(int) $d['account_id']] ?? '?'], $this->table('db_databases')->orderBy('name')->get());
    }

    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $account */
    private static function full(array $account, string $input): string
    {
        $input = \strtolower(\trim($input));
        $prefix = self::prefix($account);

        return \str_starts_with($input, $prefix) ? $input : $prefix . $input;
    }

    /** @param array<string, mixed> $account */
    private static function nameError(array $account, string $name, int $max): ?string
    {
        $suffix = \substr($name, \strlen(self::prefix($account)));

        return match (true) {
            $suffix === '' || \preg_match('/^[a-z0-9][a-z0-9_]*$/', $suffix) !== 1 => 'Databases.error_name',
            \strlen($name) > $max => $max === self::MAX_USER ? 'Databases.error_user_long' : 'Databases.error_db_long',
            default => null,
        };
    }

    /** @return list<string> */
    private static function decodePrivileges(string $json): array
    {
        $list = \json_decode($json, true);

        return \is_array($list) ? \array_values(\array_map('strval', $list)) : [];
    }

    private function table(string $name): QueryBuilder
    {
        return $this->connections->connection()->table($name);
    }
}
