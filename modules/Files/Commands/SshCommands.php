<?php

declare(strict_types=1);

namespace App\Modules\Files\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\Ssh\SshActions;
use App\Modules\Files\Ssh\SshKeys;
use App\Modules\Files\Ssh\SshOutcome;
use App\Modules\Files\Ssh\SshSync;
use App\Modules\Identity\PanelUsers;

/**
 * ssh:* from the shell, as root: the same actions as the User panel and the
 * API. A public key is read from standard input; ssh:generate prints the
 * private key it made to standard output (and nothing else there), so it
 * can be sent straight to a file.
 */
final class SshCommands
{
    public function __construct(
        private readonly SshKeys $keys,
        private readonly SshActions $actions,
        private readonly SshSync $sync,
        private readonly Accounts $accounts,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** An account's SSH access and keys. */
    public function keys(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $output->line('Access: ' . match ($this->actions->access($account)) {
            SshActions::JAIL => 'a jailed shell and SFTP',
            SshActions::SFTP => 'SFTP only (jailed)',
            default => 'none (the package has neither ssh nor sftp)',
        });
        $output->line();
        $output->line(\sprintf('%-24s %-20s %5s  %s', 'NAME', 'TYPE', 'BITS', 'FINGERPRINT'));

        foreach ($this->keys->keys((int) $account['id']) as $k) {
            $output->line(\sprintf('%-24s %-20s %5d  %s', (string) $k['name'], (string) $k['type'], (int) $k['bits'], (string) $k['fingerprint']));
        }

        $state = $this->sync->stateOf((int) $account['id']);
        $output->line();
        $output->line('On the server: ' . ($state === null ? 'nothing yet' : $state['state'] . ($state['error'] ? ' (' . $state['error'] . ')' : '')));

        return 0;
    }

    /** Add a public key, read from standard input (one line, as in a .pub file). */
    public function add(Output $output, string $username, ?string $name = null): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $key = \trim((string) \stream_get_contents(\STDIN, 16384));

        return $this->outcome($output, $this->actions->add($this->root(), null, $account, (string) $name, $key), 'Key added.');
    }

    /** Make a key pair: the public half is added, the private half printed. */
    public function generate(Output $output, string $username, string $name): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        [$outcome, $private] = $this->actions->generate($this->root(), null, $account, $name);

        if ($private === null) {
            return $this->outcome($output, $outcome, '');
        }

        $output->write($private);
        $output->errorLine($outcome->status === SshOutcome::PENDING
            ? 'Key added; the server has not answered yet (ssh:settle sends it). The private key above is not kept anywhere.'
            : 'Key added. The private key above is not kept anywhere.');

        return 0;
    }

    public function delete(Output $output, string $username, string $key): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $row = $this->keys->key((int) $account['id'], $key);

        if ($row === null) {
            return $this->fail($output, \sprintf('%s has no key named or with the fingerprint %s.', $username, $key));
        }

        return $this->outcome($output, $this->actions->delete($this->root(), null, $account, $row), \sprintf('Key %s removed.', $row['name']));
    }

    public function settle(Output $output): int
    {
        $counts = $this->sync->settleAll();
        $output->line($counts === [] ? 'Nothing waiting.' : \implode(', ', \array_map(static fn(string $k, int $n): string => $n . ' ' . $k, \array_keys($counts), $counts)));

        return 0;
    }

    public function sync(Output $output, ?string $username = null, bool $all = false): int
    {
        if ($all) {
            $ids = \array_values(\array_unique([...$this->sync->accountIds(), ...$this->keys->accountIds()]));
        } elseif ($username !== null && $username !== '') {
            $account = $this->account($output, $username);

            if ($account === null) {
                return 1;
            }

            $ids = [(int) $account['id']];
        } else {
            return $this->fail($output, 'Name an account, or give --all.');
        }

        $failed = 0;

        foreach ($ids as $id) {
            $outcome = $this->sync->touch($id, true);

            if ($outcome->status === SshOutcome::FAILED) {
                $failed++;
                $output->error(\sprintf('Account %d: %s', $id, $outcome->message));
            }
        }

        return $failed > 0 ? 1 : $this->ok($output, \sprintf('Sent %d account(s).', \count($ids)));
    }

    // -------------------------------------------------------------------------

    private function outcome(Output $output, SshOutcome $outcome, string $done): int
    {
        return match ($outcome->status) {
            SshOutcome::REFUSED => $this->fail($output, $this->localization->get((string) $outcome->message)),
            SshOutcome::FAILED => $this->fail($output, 'The server refused it: ' . $outcome->message),
            SshOutcome::PENDING => $this->ok($output, 'Saved; the server has not answered yet (ssh:settle sends it).'),
            SshOutcome::SKIPPED => $this->fail($output, 'The account is being set up or removed; try again in a moment.'),
            default => $this->ok($output, $done),
        };
    }

    private function ok(Output $output, string $message): int
    {
        $output->success($message);

        return 0;
    }

    private function fail(Output $output, string $message): int
    {
        $output->error($message);

        return 1;
    }

    /** @return array<string, mixed>|null */
    private function account(Output $output, string $username): ?array
    {
        $account = $this->accounts->findByUsernameUnscoped($username);

        if ($account === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $account;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
