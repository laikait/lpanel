<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\Password;
use App\Engine\Cli\Output;
use App\Engine\Security\Secret;

/**
 * Hash a password, for seeding the first account.
 *
 * Every application has the same first problem: the users table is empty, and
 * the only way in needs a row in it. The alternatives are worse than this --
 * a default administrator with a known password that half of all installations
 * never change, or a "create the first user" page that has to be reachable
 * without a login and is forgotten in place.
 *
 * Like security:key, **it prints and does not write**. It does not know where
 * the application's users live -- that is the UserProvider's business, and the
 * framework deliberately has no idea what is behind it.
 *
 * The password is taken as an argument, which means it lands in the shell
 * history. That is acceptable for seeding a development account and it is not
 * acceptable for a real one, so the command says so.
 */
final class AuthHashCommand
{
    public function __construct(private readonly Password $passwords) {}

    public function __invoke(Output $output, string $password, bool $bare = false): int
    {
        if ($password === '') {
            $output->error('Nothing to hash.');

            return 1;
        }

        $hash = $this->passwords->hash(new Secret($password));

        if ($bare) {
            $output->write($hash . "\n");

            return 0;
        }

        $output->heading('Password hash');
        $output->line();
        $output->line('    ' . $hash);
        $output->line();
        $output->line('Algorithm: ' . $this->passwords->describe());
        $output->line();
        $output->warning('This password is now in your shell history.');
        $output->line('Fine for seeding a development account. For a real one, have the person set it.');
        $output->line();

        return 0;
    }
}
