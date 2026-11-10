<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Engine\Auth\Account;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\TokenProvider;
use App\Engine\Auth\TwoFactorAccounts;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Hook\HookEngine;
use App\Engine\Security\Encrypter;
use App\Engine\Security\Secret;

/**
 * Every login of the panel, in the panel_users table: root, panel admins,
 * resellers, account owners and sub-users.
 *
 * It is the framework's UserProvider, so byId() runs on every signed-in
 * request: suspending a login or changing its kind takes effect on the next
 * click. It also stores two-factor settings for the framework's TwoFactor,
 * and answers for API tokens (see ApiTokens) as a TokenProvider.
 */
final class PanelUsers implements TokenProvider, TwoFactorAccounts
{
    /** Panel admin logins: a letter, then letters, digits, dot, dash or underscore. */
    public const STAFF_USERNAME = '/^[a-z][a-z0-9._-]{2,31}$/';

    private const TOTP_PURPOSE = 'identity.totp';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Encrypter $encrypter,
        private readonly Password $passwords,
        private readonly HookEngine $hooks,
    ) {}

    public function describe(): string
    {
        return 'LPanel logins, from the panel_users table';
    }

    public function byId(string $id): ?Account
    {
        return \ctype_digit($id) ? $this->account($this->table()->where('id', (int) $id)->first()) : null;
    }

    public function byLogin(string $login): ?Account
    {
        return $this->account($this->table()->where('username', self::normalise($login))->first());
    }

    /**
     * The login an API token belongs to. An expired token answers nobody, and
     * a disabled or suspended login is refused by the authenticator.
     */
    public function byToken(string $token): ?Account
    {
        $tokens = $this->connections->connection()->table('api_tokens');
        $row = $tokens->where('token_hash', ApiTokens::hash($token))->first();

        if ($row === null || ($row['expires_at'] !== null && (string) $row['expires_at'] <= self::now())) {
            return null;
        }

        $tokens->where('id', (int) $row['id'])->update(['last_used_at' => self::now()]);

        return $this->byId((string) $row['user_id']);
    }

    /** @return array<string, mixed>|null the row for a username */
    public function find(string $username): ?array
    {
        return $this->table()->where('username', self::normalise($username))->first();
    }

    /** @return array<string, mixed>|null */
    public function row(string $id): ?array
    {
        return \ctype_digit($id) ? $this->table()->where('id', (int) $id)->first() : null;
    }

    /**
     * root's row, made on its first sign-in. It holds root's two-factor
     * settings and last sign-in; the password stays the server's.
     */
    public function root(): Account
    {
        if ($this->find('root') === null) {
            $this->table()->insert([
                'username' => 'root',
                'kind' => Kind::Root->value,
                'name' => 'root',
                'active' => true,
                'created_at' => self::now(),
            ]);
        }

        return $this->byLogin('root') ?? throw new \LogicException('The root login could not be created.');
    }

    /**
     * A new login with a password. The username must be free.
     *
     * @return string the new login's id
     */
    public function create(Kind $kind, string $username, string $name, ?string $email, Secret $password, ?int $parentId = null): string
    {
        if ($kind === Kind::Root) {
            throw new \InvalidArgumentException('root is the server\'s own login and is never created by hand.');
        }

        $id = $this->table()->insert([
            'username' => self::normalise($username),
            'kind' => $kind->value,
            'name' => \trim($name),
            'email' => $email !== null && $email !== '' ? \trim($email) : null,
            'password_hash' => $this->passwords->hash($password),
            'parent_id' => $parentId,
            'active' => true,
            'created_at' => self::now(),
        ], 'id');

        return (string) $id;
    }

    /** @return list<array<string, mixed>> logins of these kinds, by username */
    public function ofKinds(Kind ...$kinds): array
    {
        $values = \array_map(static fn(Kind $kind): string => $kind->value, $kinds);

        return \array_values($this->table()->whereIn('kind', $values)->orderBy('username')->get());
    }

    /** A new password also signs the login out everywhere (see signOutEverywhere()). */
    public function setPassword(string $id, Secret $password): void
    {
        $this->update($id, ['password_hash' => $this->passwords->hash($password)]);
        $this->signOutEverywhere([$id]);
    }

    /**
     * End every session of these logins on their next request, by raising
     * their session epoch: a session remembers the epoch it signed in with
     * (IdentityListeners::signedIn) and SessionUsers refuses a stale one.
     *
     * @param list<int|string> $ids
     */
    public function signOutEverywhere(array $ids): void
    {
        foreach ($ids as $id) {
            $row = $this->row((string) $id);

            if ($row !== null) {
                $this->table()->where('id', (int) $id)->update(['session_epoch' => (int) ($row['session_epoch'] ?? 0) + 1]);
            }
        }
    }

    /** Store a hash the framework made at sign-in (the auth.rehash hook). */
    public function storeHash(Identity $identity, string $hash): void
    {
        $this->update($identity->id, ['password_hash' => $hash]);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->update($id, ['active' => $active]);

        if (!$active) {
            $this->signOutEverywhere([$id]);
        }
    }

    /**
     * Suspend or unsuspend logins along with their hosting account. A
     * suspended login is signed out on its next click and cannot sign in.
     *
     * @param list<int|string> $ids
     */
    public function setSuspended(array $ids, bool $suspended): void
    {
        if ($ids === []) {
            return;
        }

        $this->table()->whereIn('id', \array_map('intval', $ids))->update(['suspended' => $suspended, 'updated_at' => self::now()]);

        if ($suspended) {
            $this->signOutEverywhere($ids);
        }
    }

    /** @return list<array<string, mixed>> the logins this one owns */
    public function children(string $id): array
    {
        return \array_values($this->table()->where('parent_id', (int) $id)->orderBy('username')->get());
    }

    /** Remove a login, its sub-users and their API tokens. */
    public function delete(string $id): void
    {
        $ids = [(int) $id];

        foreach ($this->children($id) as $child) {
            $ids[] = (int) $child['id'];
        }

        $this->connections->connection()->table('api_tokens')->whereIn('user_id', $ids)->delete();
        $this->table()->whereIn('id', $ids)->delete();
        // Other modules forget what they kept about these logins.
        $this->hooks->do('identity.deleted', $ids);
    }

    public function signedIn(string $id, ?string $ip): void
    {
        $this->update($id, ['last_login_at' => self::now(), 'last_login_ip' => $ip]);
    }

    // ---- TwoFactorAccounts ------------------------------------------------

    public function twoFactorSecret(Account $account): ?string
    {
        $stored = $this->row($account->identity->id)['totp_secret'] ?? null;

        return \is_string($stored) ? $this->encrypter->decrypt($stored, self::TOTP_PURPOSE) : null;
    }

    public function lastTwoFactorStep(Account $account): ?int
    {
        $step = $this->row($account->identity->id)['totp_step'] ?? null;

        return $step === null ? null : (int) $step;
    }

    public function recordTwoFactorStep(Account $account, int $step): void
    {
        $this->update($account->identity->id, ['totp_step' => $step]);
    }

    public function recoveryCodes(Account $account): array
    {
        $stored = $this->row($account->identity->id)['recovery_codes'] ?? null;
        $codes = \is_string($stored) ? \json_decode($stored, true) : [];

        return \is_array($codes) ? \array_values(\array_filter($codes, 'is_string')) : [];
    }

    public function replaceRecoveryCodes(Account $account, array $hashes): void
    {
        $this->update($account->identity->id, ['recovery_codes' => \json_encode(\array_values($hashes))]);
    }

    public function enableTwoFactor(Account $account, string $secret, array $recoveryHashes): void
    {
        $this->update($account->identity->id, [
            'totp_secret' => $this->encrypter->encrypt($secret, self::TOTP_PURPOSE),
            'totp_step' => null,
            'recovery_codes' => \json_encode(\array_values($recoveryHashes)),
        ]);
    }

    public function disableTwoFactor(Account $account): void
    {
        $this->update($account->identity->id, ['totp_secret' => null, 'totp_step' => null, 'recovery_codes' => null]);
    }

    // -----------------------------------------------------------------------

    public static function normalise(string $username): string
    {
        return \strtolower(\trim($username));
    }

    /** @param array<string, mixed>|null $row */
    private function account(?array $row): ?Account
    {
        if ($row === null) {
            return null;
        }

        $kind = Kind::tryFrom((string) $row['kind']);

        if ($kind === null) {
            return null;
        }

        $username = (string) $row['username'];
        $name = \trim((string) $row['name']);

        return new Account(
            new Identity(
                (string) $row['id'],
                $name !== '' ? $name : $username,
                [$kind->role()],
                [
                    'username' => $username,
                    'kind' => $kind->value,
                    'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
                    'epoch' => (int) ($row['session_epoch'] ?? 0),
                ],
            ),
            $kind === Kind::Root ? null : (\is_string($row['password_hash']) ? $row['password_hash'] : null),
            (bool) $row['active'] && !(bool) ($row['suspended'] ?? false),
        );
    }

    /** @param array<string, mixed> $changes */
    private function update(string $id, array $changes): void
    {
        $this->table()->where('id', (int) $id)->update($changes + ['updated_at' => self::now()]);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('panel_users');
    }

    private static function now(): string
    {
        return \gmdate('Y-m-d H:i:s');
    }
}
