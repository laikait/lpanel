<?php

declare(strict_types=1);

namespace App\Modules\Identity\Users;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Auth\PasswordReset;
use App\Engine\Mail\MailException;
use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Tokens\TokenStore;

/**
 * Every change one person makes to another's account, for the API and the
 * screens alike, so the rules cannot differ between them:
 *
 *   - root's row is never changed here: root is the server's account;
 *   - only root creates, changes or grants administrators: an admin making
 *     another admin is how one stolen session becomes two;
 *   - nobody changes their own roles or status here: you cannot lock
 *     yourself out, or promote yourself, by accident or otherwise.
 *
 * Each change, allowed or refused, is in the audit log.
 */
final class UserAdmin
{
    public function __construct(
        private readonly UserStore $users,
        private readonly TokenStore $tokens,
        private readonly Password $passwords,
        private readonly PasswordReset $resets,
        private readonly Authorizer $authorizer,
        private readonly AuditLog $audit,
    ) {}

    public static function createSchema(): Schema
    {
        return Schema::of(
            'identity.user.input',
            Field::string('login')->pattern('/^[a-z][a-z0-9._-]{2,63}$/', 'a lower-case login of 3-64 letters, digits, ".", "_" or "-"'),
            Field::string('name')->length(1, 190),
            self::emailField(),
            Field::string('password')->length(12, 1024),
            Field::listOf('roles', Field::string('role')->oneOf(Roles::ASSIGNABLE))->size(1, 2),
        );
    }

    public static function updateSchema(): Schema
    {
        return Schema::of(
            'identity.user.update',
            Field::string('name')->length(1, 190),
            self::emailField(),
            Field::listOf('roles', Field::string('role')->oneOf(Roles::ASSIGNABLE))->size(1, 2),
        );
    }

    public static function passwordSchema(): Schema
    {
        return Schema::of('identity.user.password', Field::string('password')->length(12, 1024));
    }

    private static function emailField(): Field
    {
        return Field::string('email')->optional()->nullable()->check(
            'an email address',
            static fn(mixed $v): bool => $v === null || (\is_string($v) && \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false && \strlen($v) <= 190),
        );
    }

    /** May $actor change $target at all? The reason when not, null when so. */
    public function refusal(Identity $actor, User $target): ?string
    {
        return match (true) {
            !$this->authorizer->allows($actor, 'users.manage') => 'You cannot manage users.',
            $target->isRoot() || $target->kind !== User::KIND_PANEL => 'Root is the server\'s own account. Change it on the server.',
            $target->uid === $actor->id => 'Change your own account from Your account.',
            \in_array(Roles::ADMIN, $target->roles, true) && !$actor->hasRole(Roles::ROOT) => 'Only root can change an administrator.',
            default => null,
        };
    }

    /** @param list<string> $roles */
    public function mayGrant(Identity $actor, array $roles): bool
    {
        return !\in_array(Roles::ADMIN, $roles, true) || $actor->hasRole(Roles::ROOT);
    }

    /**
     * @param array{login: string, name: string, email?: ?string, password: string, roles: list<string>} $input validated by createSchema()
     *
     * @throws UserAdminException
     */
    public function create(Identity $actor, array $input): User
    {
        if (!$this->mayGrant($actor, $input['roles'])) {
            $this->audit->record('user.create', 'denied', $actor, 'user', null, ['login' => $input['login'], 'reason' => 'only root creates administrators']);

            throw UserAdminException::forbidden('Only root can create administrators.');
        }

        if (UserStore::normalize($input['login']) === UserStore::ROOT_LOGIN || $this->users->byLogin($input['login']) !== null) {
            throw UserAdminException::conflict('That login is already taken.');
        }

        $user = $this->users->create(
            $input['login'],
            $input['name'],
            self::blankToNull($input['email'] ?? null),
            $this->passwords->hash(new Secret($input['password'])),
            $input['roles'],
        );
        $this->audit->record('user.create', 'succeeded', $actor, 'user', $user->uid, ['login' => $user->login, 'roles' => $user->effectiveRoles()]);

        return $user;
    }

    /**
     * @param array{name: string, email?: ?string, roles: list<string>} $input validated by updateSchema()
     *
     * @throws UserAdminException
     */
    public function update(Identity $actor, User $target, array $input): User
    {
        $this->allow($actor, $target, 'user.update');

        if (!$this->mayGrant($actor, $input['roles'])) {
            $this->audit->record('user.update', 'denied', $actor, 'user', $target->uid, ['reason' => 'only root grants administrator']);

            throw UserAdminException::forbidden('Only root can make someone an administrator.');
        }

        $this->users->updateProfile($target->uid, $input['name'], self::blankToNull($input['email'] ?? null));
        $this->users->setRoles($target->uid, $input['roles']);
        $changed = $this->reload($target);
        $this->audit->record('user.update', 'succeeded', $actor, 'user', $target->uid, [
            'roles' => ['from' => $target->effectiveRoles(), 'to' => $changed->effectiveRoles()],
            'email_changed' => $target->email !== $changed->email,
            'name_changed' => $target->name !== $changed->name,
        ]);

        return $changed;
    }

    /** @throws UserAdminException */
    public function setStatus(Identity $actor, User $target, bool $active): User
    {
        $action = $active ? 'user.activate' : 'user.suspend';
        $this->allow($actor, $target, $action);
        $this->users->setStatus($target->uid, $active ? User::ACTIVE : User::SUSPENDED);
        $this->audit->record($action, 'succeeded', $actor, 'user', $target->uid, ['login' => $target->login]);

        return $this->reload($target);
    }

    /** @throws UserAdminException */
    public function setPassword(Identity $actor, User $target, Secret $password): void
    {
        $this->allow($actor, $target, 'user.password_set');
        $this->users->setPasswordHash($target->uid, $this->passwords->hash($password));
        $this->audit->record('user.password_set', 'succeeded', $actor, 'user', $target->uid, ['login' => $target->login, 'sessions_ended' => true]);
    }

    /**
     * Mail a reset link, through PasswordReset and its limits.
     *
     * @throws UserAdminException
     */
    public function sendResetLink(Identity $actor, User $target, ?string $ip): void
    {
        $this->allow($actor, $target, 'user.reset_link');

        if ($target->email === null || $target->email === '') {
            throw new UserAdminException('This user has no email address to send a link to.', 422);
        }

        try {
            $sent = $this->resets->request($target->login, $ip);
        } catch (MailException) {
            // An administrator may know the account exists; what they need is that mail is not set up.
            $this->audit->record('user.reset_link', 'failed', $actor, 'user', $target->uid, ['reason' => 'mail not sent']);

            throw new UserAdminException('The email could not be sent. Check the mail settings (MAIL_FROM_ADDRESS, MAIL_TRANSPORT).', 502);
        }

        if (!$sent) {
            throw new UserAdminException('Too many reset links were asked for. Try again later.', 429);
        }

        $this->audit->record('user.reset_link', 'succeeded', $actor, 'user', $target->uid, ['login' => $target->login]);
    }

    /** @throws UserAdminException */
    public function resetTwoFactor(Identity $actor, User $target): void
    {
        $this->allow($actor, $target, 'user.two_factor_reset');
        $this->users->disableTwoFactor($target->uid);
        $this->audit->record('user.two_factor_reset', 'succeeded', $actor, 'user', $target->uid, ['login' => $target->login]);
    }

    /**
     * Sign the user out of every session, and revoke their API tokens with it.
     *
     * @throws UserAdminException
     */
    public function endAccess(Identity $actor, User $target): int
    {
        $this->allow($actor, $target, 'user.sessions_end');
        $this->users->endSessions($target->uid);
        $revoked = $this->tokens->revokeAll($target->id);
        $this->audit->record('user.sessions_end', 'succeeded', $actor, 'user', $target->uid, ['login' => $target->login, 'api_credentials_revoked' => $revoked]);

        return $revoked;
    }

    /** @throws UserAdminException */
    private function allow(Identity $actor, User $target, string $action): void
    {
        $reason = $this->refusal($actor, $target);

        if ($reason !== null) {
            $this->audit->record($action, 'denied', $actor, 'user', $target->uid, ['reason' => $reason]);

            throw UserAdminException::forbidden($reason);
        }
    }

    private function reload(User $user): User
    {
        return $this->users->byUid($user->uid) ?? throw UserAdminException::notFound();
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || \trim($value) === '' ? null : \trim($value);
    }
}
