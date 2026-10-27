<?php

declare(strict_types=1);

namespace App\Modules\Identity\Users;

use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Encrypter;
use App\Modules\Shared\Support\Ulid;

/** Reads and writes users and their roles. The only code that touches those tables. */
final class UserStore
{
    public const ROOT_LOGIN = 'root';

    private const TOTP_CONTEXT = 'identity.totp';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Encrypter $encrypter,
    ) {}

    /** How a login is compared and stored: trimmed, lower case. */
    public static function normalize(string $login): string
    {
        return \mb_strtolower(\trim($login));
    }

    private function db(): Connection
    {
        return $this->connections->connection();
    }

    public function byUid(string $uid): ?User
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }

        return $this->hydrate($this->db()->table('users')->where('uid', $uid)->first());
    }

    /** By the internal id, which never leaves the panel (a token row names its user this way). */
    public function byId(int $id): ?User
    {
        return $this->hydrate($this->db()->table('users')->where('id', $id)->first());
    }

    public function byLogin(string $login): ?User
    {
        return $this->hydrate($this->db()->table('users')->where('login', self::normalize($login))->first());
    }

    /** @return list<User> */
    public function all(): array
    {
        $users = [];

        foreach ($this->db()->table('users')->orderBy('login')->get() as $row) {
            $user = $this->hydrate($row);

            if ($user !== null) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Create a user and its roles in one transaction.
     *
     * @param list<string> $roles
     */
    public function create(string $login, string $name, ?string $email, ?string $passwordHash, array $roles, string $kind = User::KIND_PANEL): User
    {
        $login = self::normalize($login);
        $uid = Ulid::generate();
        $now = self::now();

        $this->db()->transaction(function (Connection $db) use ($login, $name, $email, $passwordHash, $roles, $kind, $uid, $now): void {
            $id = (int) $db->table('users')->insert([
                'uid' => $uid,
                'login' => $login,
                'name' => $name,
                'email' => $email,
                'kind' => $kind,
                'password_hash' => $passwordHash,
                'status' => User::ACTIVE,
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id');

            foreach (\array_unique($roles) as $role) {
                $db->table('user_roles')->insert(['user_id' => $id, 'role' => $role]);
            }
        });

        return $this->byUid($uid) ?? throw new \LogicException('A user just created cannot be read back.');
    }

    /** The root account's row, created the first time root logs in. It never has a password hash. */
    public function ensureRoot(): User
    {
        return $this->byLogin(self::ROOT_LOGIN) ?? $this->create(self::ROOT_LOGIN, 'root', null, null, [], User::KIND_SYSTEM);
    }

    public function recordLogin(string $uid): void
    {
        $this->update($uid, ['last_login_at' => self::now()]);
    }

    /** A new password ends every other session: they started under the old one. */
    public function setPasswordHash(string $uid, string $hash): void
    {
        $this->db()->transaction(function () use ($uid, $hash): void {
            $this->update($uid, ['password_hash' => $hash], kind: User::KIND_PANEL);
            $this->endSessions($uid);
        });
    }

    /** Sign this user out everywhere, on each session's next request. */
    public function endSessions(string $uid): void
    {
        $user = $this->byUid($uid);

        if ($user !== null) {
            $this->update($uid, ['session_epoch' => $user->sessionEpoch + 1]);
        }
    }

    /** Name and address. Never root's row: root is the server's account, not the panel's. */
    public function updateProfile(string $uid, string $name, ?string $email): void
    {
        $this->update($uid, ['name' => $name, 'email' => $email], kind: User::KIND_PANEL);
    }

    /** @param list<string> $roles names from Roles::ASSIGNABLE */
    public function setRoles(string $uid, array $roles): void
    {
        $user = $this->byUid($uid);

        if ($user === null || $user->kind !== User::KIND_PANEL) {
            return;
        }

        $this->db()->transaction(function (Connection $db) use ($user, $roles): void {
            $db->table('user_roles')->where('user_id', $user->id)->delete();

            foreach (\array_unique($roles) as $role) {
                $db->table('user_roles')->insert(['user_id' => $user->id, 'role' => $role]);
            }

            $db->table('users')->where('id', $user->id)->update(['updated_at' => self::now()]);
        });
    }

    /** active or suspended. A suspended user is nobody from their next request on. */
    public function setStatus(string $uid, string $status): void
    {
        if (!\in_array($status, [User::ACTIVE, User::SUSPENDED], true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown user status "%s".', $status));
        }

        $this->update($uid, ['status' => $status], kind: User::KIND_PANEL);
    }

    /** @return array{total: int, admins: int, customers: int, two_factor: int, suspended: int} */
    public function counts(): array
    {
        $db = $this->db();

        return [
            'total' => $db->table('users')->count(),
            'admins' => $db->table('user_roles')->where('role', Roles::ADMIN)->count(),
            'customers' => $db->table('user_roles')->where('role', Roles::CUSTOMER)->count(),
            'two_factor' => $db->table('users')->whereNotNull('totp_secret')->count(),
            'suspended' => $db->table('users')->where('status', User::SUSPENDED)->count(),
        ];
    }

    public function totpSecret(User $user): ?string
    {
        if ($user->totpSecretEncrypted === null) {
            return null;
        }

        return $this->encrypter->decrypt($user->totpSecretEncrypted, self::TOTP_CONTEXT)
            ?? throw new \RuntimeException('A two-factor secret cannot be decrypted: APP_KEY changed without keeping the old one in security.previous_keys.');
    }

    /** @param list<string> $recoveryHashes */
    public function enableTwoFactor(string $uid, string $secret, array $recoveryHashes): void
    {
        $this->update($uid, [
            'totp_secret' => $this->encrypter->encrypt($secret, self::TOTP_CONTEXT),
            'totp_last_step' => null,
            'recovery_codes' => \json_encode(\array_values($recoveryHashes), \JSON_THROW_ON_ERROR),
        ]);
    }

    public function disableTwoFactor(string $uid): void
    {
        $this->update($uid, ['totp_secret' => null, 'totp_last_step' => null, 'recovery_codes' => null]);
    }

    public function recordTwoFactorStep(string $uid, int $step): void
    {
        $this->update($uid, ['totp_last_step' => $step]);
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryCodes(string $uid, array $hashes): void
    {
        $this->update($uid, ['recovery_codes' => \json_encode(\array_values($hashes), \JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string, mixed> $changes */
    private function update(string $uid, array $changes, ?string $kind = null): void
    {
        $query = $this->db()->table('users')->where('uid', $uid);

        // The builder is immutable: the condition is assigned back, or it is silently dropped.
        if ($kind !== null) {
            $query = $query->where('kind', $kind);
        }

        $query->update($changes + ['updated_at' => self::now()]);
    }

    /** @param array<string, mixed>|null $row */
    private function hydrate(?array $row): ?User
    {
        if ($row === null) {
            return null;
        }

        $id = (int) $row['id'];
        $roles = [];

        foreach ($this->db()->table('user_roles')->select('role')->where('user_id', $id)->orderBy('role')->get() as $r) {
            $roles[] = (string) $r['role'];
        }

        $recovery = \is_string($row['recovery_codes'] ?? null) ? \json_decode($row['recovery_codes'], true) : [];

        return new User(
            id: $id,
            uid: (string) $row['uid'],
            login: (string) $row['login'],
            name: (string) $row['name'],
            email: isset($row['email']) ? (string) $row['email'] : null,
            kind: (string) $row['kind'],
            status: (string) $row['status'],
            passwordHash: isset($row['password_hash']) ? (string) $row['password_hash'] : null,
            roles: $roles,
            totpSecretEncrypted: isset($row['totp_secret']) ? (string) $row['totp_secret'] : null,
            totpLastStep: isset($row['totp_last_step']) ? (int) $row['totp_last_step'] : null,
            recoveryHashes: \is_array($recovery) ? \array_values(\array_map('strval', $recovery)) : [],
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            sessionEpoch: (int) ($row['session_epoch'] ?? 1),
            createdAt: isset($row['created_at']) ? (string) $row['created_at'] : null,
        );
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
