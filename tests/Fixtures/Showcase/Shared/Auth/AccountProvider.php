<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Auth;

use App\Engine\Auth\Account;
use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Auth\Identity;
use App\Engine\Auth\TokenProvider;
use App\Tests\Fixtures\Showcase\Shared\Data\UserRepository;
use App\Tests\Fixtures\Showcase\Shared\Model\User;

/**
 * This application's answer to "what is a user".
 *
 * **This is the modular half of the authentication phase.** The framework has
 * no User model, no users table and no columns it expects; it has an interface
 * with two methods. Everything that knows what a user actually is lives here,
 * in the shared module, next to the model and the repository it already owned.
 * Swapping it for LDAP or an identity provider means writing another class of
 * this shape and binding that instead -- nothing in engine/ changes, because
 * nothing in engine/ ever knew.
 *
 * The roles are derived from the username here because this demo has no roles
 * table. A real application reads them from wherever it keeps them -- which is
 * exactly the point: that decision belongs to the application, and it is made
 * in one method.
 */
final class AccountProvider implements TokenProvider
{
    /**
     * Demo passwords, hashed once at build time rather than at boot.
     *
     * Hashing is meant to be slow, so doing it as the application starts would
     * cost every request a login's worth of work for accounts that mostly never
     * log in. Both of these are "secret" -- a demo credential in a public
     * repository, which is why they are written where anyone can see they are
     * not real.
     *
     * @var array<string, string>
     */
    private const PASSWORDS = [
        'ada' => '$2y$10$OJo9ntDmwjaeqgj4VJNUC.AeDMyPb7RNLyGRIdaOn18vno4Yg2rN6',
        'grace' => '$2y$10$OJo9ntDmwjaeqgj4VJNUC.AeDMyPb7RNLyGRIdaOn18vno4Yg2rN6',
    ];

    /**
     * Demo API tokens, stored as fingerprints rather than as tokens.
     *
     * The plaintext of the first is "ada-token-do-not-use". What is written
     * down is the SHA-256 of it, because a table holding usable tokens is a
     * password table that skipped the last thirty years: anyone who can read it
     * can be any of those clients.
     *
     * @var array<string, string> fingerprint => username
     */
    private const TOKENS = [
        '97edbb63812f8005a430ced74ada473f60484b8921d14d6333b7424be04fa8c4' => 'ada',
    ];

    /**
     * Who is an administrator.
     *
     * A list in a constant because this demo has no roles table. The shape of
     * the real thing is the same -- rolesFor() reads from somewhere and returns
     * a list of names -- which is why it is one method rather than a condition
     * scattered through the provider.
     *
     * @var list<string>
     */
    private const ADMINISTRATORS = ['ada'];

    public function __construct(private readonly UserRepository $users) {}

    public function describe(): string
    {
        return 'users, via the shared module';
    }

    public function byId(string $id): ?Account
    {
        if (!\ctype_digit($id)) {
            return null;
        }

        foreach ($this->users->all() as $user) {
            if ($user instanceof User && (string) $user->identity() === $id) {
                return $this->accountFor($user);
            }
        }

        return null;
    }

    public function byLogin(string $login): ?Account
    {
        foreach ($this->users->all() as $user) {
            // Usernames are compared exactly. A case-insensitive login is a
            // decision with consequences -- two accounts differing only in case
            // become one -- and it belongs to whoever owns the user store.
            if ($user instanceof User && $user->username() === $login) {
                return $this->accountFor($user);
            }
        }

        return null;
    }

    public function byToken(string $token): ?Account
    {
        $username = self::TOKENS[TokenAuthenticator::fingerprint($token)] ?? null;

        return $username === null ? null : $this->byLogin($username);
    }

    private function accountFor(User $user): Account
    {
        return new Account(
            new Identity(
                (string) $user->identity(),
                $user->username(),
                $this->rolesFor($user),
                ['email' => $user->email()],
            ),
            self::PASSWORDS[$user->username()] ?? null,
        );
    }

    /**
     * @return list<string>
     */
    private function rolesFor(User $user): array
    {
        return \in_array($user->username(), self::ADMINISTRATORS, true)
            ? ['administrator']
            : ['member'];
    }
}
