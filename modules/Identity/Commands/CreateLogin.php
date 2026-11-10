<?php

declare(strict_types=1);

namespace App\Modules\Identity\Commands;

use App\Engine\Cli\Output;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Http\SecurityPage;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;

/**
 * `lpanel identity:create admin alice --name="Alice"`: a login from the shell.
 *
 * The password is read from standard input, never from an option, so it does
 * not appear in the process list or the shell's history:
 *     printf '%s\n' "$PASSWORD" | lpanel identity:create admin alice
 */
final class CreateLogin
{
    public function __construct(
        private readonly PanelUsers $users,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Output $output, string $kind, string $username, ?string $name = null, ?string $email = null, ?string $parent = null): int
    {
        $kind = Kind::tryFrom($kind);

        if ($kind === null || $kind === Kind::Root) {
            $output->error('The kind is one of: admin, reseller, user, subuser.');

            return 2;
        }

        if (\preg_match(PanelUsers::STAFF_USERNAME, PanelUsers::normalise($username)) !== 1) {
            $output->error('A username is 3 to 32 characters: a letter, then letters, digits, dots, dashes or underscores.');

            return 2;
        }

        if ($this->users->find($username) !== null) {
            $output->error(\sprintf('The username "%s" is taken.', $username));

            return 1;
        }

        // A sub-user belongs to an account owner; an account owner may belong to
        // a reseller (none means the server's own); admins and resellers to nobody.
        $owner = $parent === null ? null : $this->users->find($parent);
        $ownerKind = match ($kind) {
            Kind::Subuser => Kind::User,
            Kind::User => Kind::Reseller,
            default => null,
        };

        if (($kind === Kind::Subuser && $owner === null) || ($parent !== null && ($owner === null || $owner['kind'] !== $ownerKind?->value))) {
            $output->error($ownerKind === null
                ? \sprintf('A %s has no --parent.', $kind->value)
                : \sprintf('--parent must name an existing %s.', $ownerKind->value));

            return 2;
        }

        $parentId = $owner === null ? null : (int) $owner['id'];

        $password = \rtrim((string) \fgets(\STDIN), "\r\n");

        if (\mb_strlen($password) < SecurityPage::MIN_PASSWORD) {
            $output->error(\sprintf('Send a password of at least %d characters on standard input.', SecurityPage::MIN_PASSWORD));

            return 2;
        }

        $id = $this->users->create($kind, $username, $name ?? '', $email, new Secret($password), $parentId);
        $this->audit->record('identity.create', null, null, PanelUsers::normalise($username), AuditLog::OK, ['kind' => $kind->value, 'via' => 'cli']);
        $output->success(\sprintf('%s login "%s" created (id %s).', \ucfirst($kind->value), PanelUsers::normalise($username), $id));

        return 0;
    }
}
