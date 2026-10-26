<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Security\Signer;

/**
 * Print a new APP_KEY.
 *
 * **It prints; it does not write.** Every other framework's equivalent edits
 * the .env file, and that convenience is exactly wrong for this value. Writing
 * it means the command has to find a .env, decide whether one already has a key,
 * and decide what to do when it does -- and the answer to the last is the only
 * one that matters, because silently replacing a live key invalidates every
 * token and session the application has issued. A command that cannot do that
 * by accident is worth the one copy and paste.
 *
 * It also means this is not a generator in the sense the specification bans. It
 * writes no file and creates no class; it reads 32 bytes from the CSPRNG and
 * shows them.
 */
final class SecurityKeyCommand
{
    public function __construct(private readonly Signer $signer) {}

    public function __invoke(Output $output, bool $bare = false): int
    {
        $key = Signer::generate();

        // For a deployment script: one line, nothing else, so that
        // `APP_KEY=$(php laika security:key --bare)` works.
        if ($bare) {
            $output->write($key . "\n");

            return 0;
        }

        $output->heading('A new application key');
        $output->line();
        $output->line('    APP_KEY=' . $key);
        $output->line();
        $output->line('Put it in the environment -- a .env file on a laptop, the real environment on');
        $output->line('a server. It is 32 bytes of randomness, base64 encoded because a raw key does');
        $output->line('not survive a shell, a YAML file or a copy and paste.');
        $output->line();

        if ($this->signer->isConfigured()) {
            $output->warning('This application already has a key.');
            $output->line('Replacing it invalidates every token signed with the old one. Nothing here');
            $output->line('has changed: this command only prints.');

            return 0;
        }

        $output->line('This application has no key yet, so tokens are currently unsigned. See:');
        $output->line('    php laika security:check');

        return 0;
    }
}
