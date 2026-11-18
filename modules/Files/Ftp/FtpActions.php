<?php

declare(strict_types=1);

namespace App\Modules\Files\Ftp;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;
use App\Modules\Web\Domains;

/**
 * Changing an account's FTP logins, and ending FTP sessions: the one place
 * the User panel, the Admin panel, the API and the CLI all go through.
 *
 * A change is saved, then the account's whole list goes to the server
 * (FtpSync); if the server refuses it, the change is undone here too.
 * Every change is audited (ftp.*), never a password.
 */
final class FtpActions
{
    public function __construct(
        private readonly FtpUsers $users,
        private readonly FtpSync $sync,
        private readonly Packages $packages,
        private readonly Domains $domains,
        private readonly AgentClient $agent,
        private readonly AuditLog $audit,
    ) {}

    /**
     * A new login: name@domain, where domain is one of the account's.
     *
     * @param array<string, mixed> $account
     */
    public function create(?Identity $actor, ?Request $request, array $account, string $name, string $domain, string $password, string $folder): FtpOutcome
    {
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $limit = (int) ($package['ftp_accounts'] ?? 0);

        if ($limit > 0 && $this->users->count((int) $account['id']) >= $limit) {
            return FtpOutcome::refused('Files.ftp_error_limit', 'name');
        }

        $name = \strtolower(\trim($name));

        if (($why = FtpUsers::nameError($name)) !== null) {
            return FtpOutcome::refused($why, 'name');
        }

        $domain = \strtolower(\trim($domain));

        if (!\in_array($domain, $this->domainsOf($account), true)) {
            return FtpOutcome::refused('Files.ftp_error_domain', 'domain');
        }

        $login = $name . '@' . $domain;

        if (\strlen($login) > FtpUsers::MAX_LOGIN) {
            return FtpOutcome::refused('Files.ftp_error_name', 'name');
        }

        if ($this->users->taken($login)) {
            return FtpOutcome::refused('Files.ftp_error_taken', 'name');
        }

        if (($why = FtpUsers::passwordError($password)) !== null) {
            return FtpOutcome::refused($why, 'password');
        }

        [$folder, $why] = FtpUsers::cleanFolder($folder);

        if ($why !== null) {
            return FtpOutcome::refused($why, 'folder');
        }

        $id = 0;

        return $this->attempt(
            $actor,
            $request,
            $account,
            'ftp.create',
            $login,
            ['folder' => '/' . $folder],
            function () use (&$id, $account, $login, $password, $folder): void {
                $id = $this->users->add((int) $account['id'], $login, FtpUsers::hash($password), $folder);
            },
            function () use (&$id): void {
                $this->users->delete($id);
            },
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     */
    public function setPassword(?Identity $actor, ?Request $request, array $account, array $user, string $password): FtpOutcome
    {
        if (($why = FtpUsers::passwordError($password)) !== null) {
            return FtpOutcome::refused($why, 'password');
        }

        $before = (string) $user['hash'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'ftp.password',
            (string) $user['login'],
            [],
            fn() => $this->users->update((int) $user['id'], ['hash' => FtpUsers::hash($password)]),
            fn() => $this->users->update((int) $user['id'], ['hash' => $before]),
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     */
    public function setFolder(?Identity $actor, ?Request $request, array $account, array $user, string $input): FtpOutcome
    {
        [$folder, $why] = FtpUsers::cleanFolder($input);

        if ($why !== null) {
            return FtpOutcome::refused($why, 'folder');
        }

        $before = (string) $user['folder'];

        if ($folder === $before) {
            return FtpOutcome::done();
        }

        return $this->attempt(
            $actor,
            $request,
            $account,
            'ftp.folder',
            (string) $user['login'],
            ['folder' => '/' . $folder, 'was' => '/' . $before],
            fn() => $this->users->update((int) $user['id'], ['folder' => $folder]),
            fn() => $this->users->update((int) $user['id'], ['folder' => $before]),
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $user
     */
    public function delete(?Identity $actor, ?Request $request, array $account, array $user): FtpOutcome
    {
        $id = (int) $user['id'];

        return $this->attempt(
            $actor,
            $request,
            $account,
            'ftp.delete',
            (string) $user['login'],
            [],
            fn() => $this->users->delete($id),
            function () use (&$id, $user): void {
                $id = $this->users->restore($user);
            },
        );
    }

    /**
     * End an FTP session; with an account, only one of its logins'.
     *
     * @param array<string, mixed>|null $account
     */
    public function kill(?Identity $actor, ?Request $request, ?array $account, int $pid): FtpOutcome
    {
        if ($pid <= 1) {
            return FtpOutcome::refused('Files.ftp_no_session');
        }

        $args = ['pid' => $pid] + ($account === null ? [] : ['username' => (string) $account['username']]);

        try {
            $reply = $this->agent->call('ftp.kill', $args, id: Protocol::newId(), timeout: 20.0);
        } catch (AgentUnavailable) {
            return FtpOutcome::refused('Files.ftp_error_unreachable');
        }

        if ($reply->errorCode === 'ftp_no_session') {
            return FtpOutcome::refused('Files.ftp_no_session');
        }

        $outcome = $reply->done()
            ? FtpOutcome::done($reply->result)
            : FtpOutcome::failed($reply->errorCode ?? 'failed', $reply->errorMessage ?? 'The agent gave no reason.');
        $details = ($account === null ? [] : ['account' => $account['username']]) + ['login' => (string) ($reply->result['login'] ?? '')];

        if ($outcome->status === FtpOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record('ftp.kill', $actor, $request, (string) $pid, $outcome->status === FtpOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }

    /**
     * The FTP server's state and its sessions; with an account, only its own.
     *
     * @param array<string, mixed>|null $account
     *
     * @return array<string, mixed>|null null when the agent cannot be asked
     */
    public function info(?array $account = null): ?array
    {
        try {
            $reply = $this->agent->call('ftp.info', [], timeout: 15.0);
        } catch (AgentUnavailable) {
            return null;
        }

        if (!$reply->done()) {
            return null;
        }

        $info = $reply->result;
        $sessions = \is_array($info['sessions'] ?? null) ? $info['sessions'] : [];

        if ($account !== null) {
            $sessions = \array_values(\array_filter($sessions, static fn(mixed $s): bool => \is_array($s) && ($s['account'] ?? '') === $account['username']));
        }

        $info['sessions'] = $sessions;

        return $info;
    }

    /**
     * The account's domains a login may be named after.
     *
     * @param array<string, mixed> $account
     *
     * @return list<string>
     */
    public function domainsOf(array $account): array
    {
        $names = \array_map(static fn(array $d): string => (string) $d['name'], $this->domains->forAccount((int) $account['id']));

        // Not a wildcard ("*.example.com"): a login names one domain.
        return \array_values(\array_filter($names, static fn(string $n): bool => !\str_contains($n, '*')));
    }

    // -------------------------------------------------------------------------

    /**
     * Save, send, and undo both if the server refuses; audit either way.
     *
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function attempt(?Identity $actor, ?Request $request, array $account, string $action, string $subject, array $details, \Closure $do, \Closure $undo): FtpOutcome
    {
        $do();
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === FtpOutcome::FAILED) {
            $undo();
            $this->sync->touch((int) $account['id']);
        }

        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === FtpOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === FtpOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $subject, $outcome->status === FtpOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);

        return $outcome;
    }
}
