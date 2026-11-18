<?php

declare(strict_types=1);

namespace App\Modules\Files\Ftp;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Modules\Accounts\Accounts;

/**
 * Each account's FTP logins, as the panel keeps them, and the rules their
 * names, passwords and folders follow (the agent checks the same).
 *
 * A login is a name and one of the account's domains, bob@example.com, so
 * logins never collide between accounts and say whose they are.
 */
final class FtpUsers
{
    public const PASSWORD_MIN = 10;
    public const PASSWORD_MAX = 128;
    public const MAX_LOGIN = 100;
    public const MAX_FOLDER = 1024;

    /** The name before the @: lowercase letters, digits, dot, dash, underscore. */
    private const NAME = '/^[a-z0-9]([a-z0-9._-]{0,62}[a-z0-9])?$/';

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return list<array<string, mixed>> the account's logins, by name */
    public function users(int $accountId): array
    {
        return $this->table()->where('account_id', $accountId)->orderBy('login')->get();
    }

    /** @return array<string, mixed>|null */
    public function user(int $accountId, int|string $idOrLogin): ?array
    {
        $q = $this->table()->where('account_id', $accountId);

        return (\is_int($idOrLogin) ? $q->where('id', $idOrLogin) : $q->where('login', $idOrLogin))->first();
    }

    /** @return list<array<string, mixed>> every account's logins, with its username */
    public function all(): array
    {
        $names = [];

        foreach ($this->connections->connection()->table('accounts')->get() as $a) {
            $names[(int) $a['id']] = (string) $a['username'];
        }

        return \array_map(
            static fn(array $u): array => $u + ['username' => $names[(int) $u['account_id']] ?? '?'],
            $this->table()->orderBy('login')->get(),
        );
    }

    public function count(int $accountId): int
    {
        return \count($this->users($accountId));
    }

    public function taken(string $login): bool
    {
        return $this->table()->where('login', $login)->first() !== null;
    }

    public function add(int $accountId, string $login, string $hash, string $folder): int
    {
        $now = Accounts::now();

        return (int) $this->table()->insert(['account_id' => $accountId, 'login' => $login, 'hash' => $hash, 'folder' => $folder, 'created_at' => $now, 'updated_at' => $now], 'id');
    }

    /** @param array<string, mixed> $row a row as it was, to put back */
    public function restore(array $row): int
    {
        unset($row['id'], $row['username']);

        return (int) $this->table()->insert($row, 'id');
    }

    /** @param array<string, mixed> $changes */
    public function update(int $id, array $changes): void
    {
        $this->table()->where('id', $id)->update($changes + ['updated_at' => Accounts::now()]);
    }

    public function delete(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    public function deleteAccount(int $accountId): void
    {
        $this->table()->where('account_id', $accountId)->delete();
    }

    /**
     * The account's whole state as ftp.apply takes it.
     *
     * @param array<string, mixed> $account
     *
     * @return array<string, mixed>
     */
    public function state(array $account): array
    {
        return [
            'username' => (string) $account['username'],
            'users' => \array_map(static fn(array $u): array => [
                'login' => (string) $u['login'],
                'hash' => (string) $u['hash'],
                'folder' => (string) $u['folder'],
            ], $this->users((int) $account['id'])),
            'suspended' => $account['status'] === Accounts::SUSPENDED,
        ];
    }

    // -------------------------------------------------------------------------
    // Rules

    /** @return string|null a language key when the name before the @ is not one */
    public static function nameError(string $name): ?string
    {
        return \preg_match(self::NAME, $name) === 1 ? null : 'Files.ftp_error_name';
    }

    /**
     * A folder named from the home, without slashes at either end ("" is
     * the home itself).
     *
     * @return array{0: string, 1: string|null} the folder, and a language key when it is not one
     */
    public static function cleanFolder(string $input): array
    {
        $folder = \trim(\trim($input), '/');

        if (\strlen($folder) > self::MAX_FOLDER) {
            return ['', 'Files.ftp_error_folder'];
        }

        if ($folder === '') {
            return ['', null];
        }

        foreach (\explode('/', $folder) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || \strlen($part) > 255 || \preg_match('/[\x00-\x1f\x7f:\\\\]/', $part) === 1) {
                return ['', 'Files.ftp_error_folder'];
            }
        }

        return [$folder, null];
    }

    public static function passwordError(string $password): ?string
    {
        $length = \mb_strlen($password);

        return match (true) {
            $length < self::PASSWORD_MIN => 'Files.ftp_error_password_short',
            $length > self::PASSWORD_MAX => 'Files.ftp_error_password_long',
            \preg_match('/[\x00-\x1f\x7f]/', $password) === 1 => 'Files.ftp_error_password_chars',
            default => null,
        };
    }

    /** The hash Pure-FTPd checks logins against (bcrypt, through crypt(3)). */
    public static function hash(string $password): string
    {
        return \password_hash($password, \PASSWORD_BCRYPT);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ftp_users');
    }
}
