<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * Adding, changing and removing an account's domains: the one place the
 * User panel, the API and the CLI all go through.
 *
 * Each change is saved, then sent to the server. If the server refuses it
 * (a test failed, or the probe did and the agent put the old files back),
 * the change is undone here too and the server is told the state it still
 * has. If the agent cannot be reached, the change stays recorded and
 * web:settle finishes it.
 */
final class DomainActions
{
    public function __construct(
        private readonly Domains $domains,
        private readonly WebSync $sync,
        private readonly Packages $packages,
        private readonly AuditLog $audit,
        private readonly ConnectionManager $connections,
    ) {}

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $input   name, kind, target, docroot, php, wildcard
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>, 2: array<string, mixed>} [outcome, errors, clean]
     */
    public function add(?Identity $actor, ?Request $request, array $account, array $input): array
    {
        $package = $this->packages->byId((int) $account['package_id']) ?? [];
        $inUse = $this->inUse($account);
        $input['php'] = ($input['php'] ?? '') === '' ? $this->sync->defaultPhp() : $input['php'];
        [$clean, $errors] = $this->domains->validateNew($account, $package, $input, $this->sync->phpVersions($inUse));

        if ($errors !== []) {
            return [null, $errors, $clean];
        }

        $id = $this->domains->add((int) $account['id'], $clean);
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === WebOutcome::FAILED) {
            $this->domains->delete($id);
            $this->sync->touch((int) $account['id']);
        }

        $this->record('domain.add', $actor, $request, $account, $clean['name'], $outcome, [
            'kind' => $clean['kind'], 'docroot' => $clean['docroot'], 'php' => $clean['php'],
        ]);

        return [$outcome, [], $clean];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $input   docroot?, php?, wildcard?
     *
     * @return array{0: ?WebOutcome, 1: array<string, string>}
     */
    public function change(?Identity $actor, ?Request $request, array $account, array $domain, array $input): array
    {
        [$clean, $errors] = $this->domains->validateChange($domain, $input, $this->sync->phpVersions($this->inUse($account)));

        if ($errors !== []) {
            return [null, $errors];
        }

        $before = \array_intersect_key($domain, $clean);
        $this->domains->update((int) $domain['id'], $clean);
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === WebOutcome::FAILED) {
            $this->domains->update((int) $domain['id'], $before);
            $this->sync->touch((int) $account['id']);
        }

        $this->record('domain.change', $actor, $request, $account, (string) $domain['name'], $outcome, $clean);

        return [$outcome, []];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $domain
     *
     * @return array{0: ?WebOutcome, 1: ?string} [outcome, why it cannot be removed]
     */
    public function remove(?Identity $actor, ?Request $request, array $account, array $domain): array
    {
        $blocker = $this->domains->blocker($domain);

        if ($blocker !== null) {
            return [null, $blocker];
        }

        $this->domains->delete((int) $domain['id']);
        $outcome = $this->sync->touch((int) $account['id']);

        if ($outcome->status === WebOutcome::FAILED) {
            // Put the row back as it was, id and all.
            $row = $domain;
            unset($row['account_name']);
            $this->connections->connection()->table('domains')->insert($row);
            $this->sync->touch((int) $account['id']);
        }

        $this->record('domain.remove', $actor, $request, $account, (string) $domain['name'], $outcome, ['kind' => $domain['kind']]);

        return [$outcome, null];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return list<string>
     */
    private function inUse(array $account): array
    {
        return \array_values(\array_filter(\array_map(
            static fn(array $d): string => (string) ($d['php'] ?? ''),
            $this->domains->forAccount((int) $account['id']),
        )));
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $details
     */
    private function record(string $action, ?Identity $actor, ?Request $request, array $account, string $name, WebOutcome $outcome, array $details): void
    {
        $details = ['account' => $account['username']] + $details;

        if ($outcome->status === WebOutcome::PENDING) {
            $details['pending'] = true;
        } elseif ($outcome->status === WebOutcome::FAILED) {
            $details += ['reason' => $outcome->code, 'error' => $outcome->message];
        }

        $this->audit->record($action, $actor, $request, $name, $outcome->status === WebOutcome::FAILED ? AuditLog::FAILED : AuditLog::OK, $details);
    }
}
