<?php

declare(strict_types=1);

namespace App\Modules\Files\Ssh;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Accounts\Provisioner;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Changing an account's SSH keys: the one place the User panel, the API and
 * the CLI all go through.
 *
 * A change is saved, then the account's whole list goes to the server
 * (SshSync); if the server refuses it, the change is undone here too. Every
 * change is audited (ssh.*) with the key's fingerprint; a private key the
 * panel makes is handed back once and appears nowhere else.
 */
final class SshActions
{
    public const JAIL = 'jail';
    public const SFTP = 'sftp';
    public const NONE = 'none';

    public function __construct(
        private readonly SshKeys $keys,
        private readonly SshSync $sync,
        private readonly Packages $packages,
        private readonly AuditLog $audit,
    ) {}

    /**
     * What the account's package lets it do over SSH: a jailed shell and
     * SFTP (jail), SFTP alone (sftp), or nothing (none).
     *
     * @param array<string, mixed> $account
     */
    public function access(array $account): string
    {
        $package = $this->packages->byId((int) $account['package_id']);

        return $package === null ? self::NONE : Provisioner::shell($package);
    }

    /**
     * Add a public key someone pasted.
     *
     * @param array<string, mixed> $account
     */
    public function add(?Identity $actor, ?Request $request, array $account, string $name, string $input): SshOutcome
    {
        [$parsed, $why] = SshKeys::parse($input);

        if ($parsed === null) {
            return SshOutcome::refused((string) $why, 'key');
        }

        $name = \trim($name) !== '' ? \trim($name) : \mb_substr($parsed['comment'], 0, SshKeys::MAX_NAME);

        if ($name === '') {
            $name = 'key-' . \substr(\str_replace(['+', '/'], '', \substr($parsed['fingerprint'], 7)), 0, 8);
        }

        return $this->save($actor, $request, $account, $name, $parsed, 'ssh.key_add');
    }

    /**
     * Make a new Ed25519 pair; the public half is added and the private half
     * returned, once.
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: SshOutcome, 1: string|null} the outcome, and the private key file when the key was added
     */
    public function generate(?Identity $actor, ?Request $request, array $account, string $name): array
    {
        $name = \trim($name);

        if (($why = SshKeys::nameError($name)) !== null) {
            return [SshOutcome::refused($why, 'name'), null];
        }

        $pair = SshKeyGen::ed25519($name);
        [$parsed] = SshKeys::parse('ssh-ed25519 ' . $pair['public']);

        if ($parsed === null) {
            throw new \LogicException('a key the panel made does not read back');
        }

        $outcome = $this->save($actor, $request, $account, $name, $parsed, 'ssh.key_generate');

        return [$outcome, $outcome->ok() ? $pair['private'] : null];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $key
     */
    public function delete(?Identity $actor, ?Request $request, array $account, array $key): SshOutcome
    {
        $id = (int) $key['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'ssh.key_delete',
            (string) $key['name'],
            ['fingerprint' => (string) $key['fingerprint']],
            fn() => $this->keys->delete($id),
            function () use (&$id, $key): void {
                $id = $this->keys->restore($key);
            },
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $account
     * @param array{type: string, key: string, fingerprint: string, bits: int, comment: string} $parsed
     */
    private function save(?Identity $actor, ?Request $request, array $account, string $name, array $parsed, string $action): SshOutcome
    {
        $accountId = (int) $account['id'];

        $refusal = match (true) {
            $this->access($account) === self::NONE => 'Files.ssh_error_no_access',
            SshKeys::nameError($name) !== null => 'Files.ssh_error_name',
            $this->keys->count($accountId) >= SshKeys::MAX_KEYS => 'Files.ssh_error_limit',
            $this->keys->key($accountId, $parsed['fingerprint']) !== null => 'Files.ssh_error_taken',
            $this->keys->key($accountId, $name) !== null => 'Files.ssh_error_name_taken',
            default => null,
        };

        if ($refusal !== null) {
            return SshOutcome::refused($refusal, \in_array($refusal, ['Files.ssh_error_name', 'Files.ssh_error_name_taken'], true) ? 'name' : 'key');
        }

        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            $action,
            $name,
            ['fingerprint' => $parsed['fingerprint'], 'type' => $parsed['type']],
            function () use (&$id, $accountId, $name, $parsed): void {
                $id = $this->keys->add($accountId, $name, $parsed);
            },
            function () use (&$id): void {
                $this->keys->delete($id);
            },
        );
    }

    /**
     * Save, send, and undo both if the server refuses; audit either way.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function attempt(?Identity $actor, ?Request $request, array $account, string $action, string $subject, array $details, \Closure $do, \Closure $undo): SshOutcome
    {
        $do();
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === SshOutcome::FAILED) {
            $undo();
            $this->sync->touch((int) $account['id']);
        }

        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === SshOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === SshOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $subject, $outcome->status === SshOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }
}
