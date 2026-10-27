<?php

declare(strict_types=1);

namespace App\Modules\Servers\Commands;

use App\Engine\Cli\Output;
use App\Modules\Servers\Agent\OperationKey;

/**
 * servers:keygen -- make the panel's operation-signing key, once.
 *
 * Refuses when the file exists: every enrolled agent trusts that key, and
 * replacing it would make them all refuse the panel's operations.
 */
final class KeyGen
{
    public function __construct(private readonly OperationKey $key) {}

    public function __invoke(Output $output): int
    {
        if ($this->key->path() === '') {
            $output->line('Servers.operation_key is empty. Set LPANEL_OPERATION_KEY to where the key file belongs.');

            return 1;
        }

        try {
            OperationKey::generate($this->key->path());
        } catch (\RuntimeException $e) {
            $output->line($e->getMessage());

            return 1;
        }

        $output->line('Wrote ' . $this->key->path());
        $output->line('Public key ' . $this->key->publicKey() . ' (kid ' . $this->key->kid() . ')');
        $output->line('On a server: chown root:lpanel and chmod 0640 it. Back it up: losing it means pairing every agent again.');

        return 0;
    }
}
