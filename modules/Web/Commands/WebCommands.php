<?php

declare(strict_types=1);

namespace App\Modules\Web\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\DomainActions;
use App\Modules\Web\Domains;
use App\Modules\Web\WebOutcome;
use App\Modules\Web\WebSync;

/**
 * domains:* and web:* — the same actions as the User panel and the API, as
 * root, from the shell.
 */
final class WebCommands
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Domains $domains,
        private readonly DomainActions $actions,
        private readonly WebSync $sync,
        private readonly PanelUsers $users,
        private readonly Localization $localization,
    ) {}

    public function list(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $output->line(\sprintf('%-40s %-6s %-28s %-4s %s', 'DOMAIN', 'KIND', 'FOLDER', 'PHP', 'POINTS TO'));
        $names = [];

        foreach ($this->domains->forAccount((int) $account['id']) as $d) {
            $names[$d['id']] = $d['name'];
        }

        foreach ($this->domains->forAccount((int) $account['id']) as $d) {
            $output->line(\sprintf(
                '%-40s %-6s %-28s %-4s %s',
                ($d['wildcard'] ? '*.' : '') . $d['name'],
                $d['kind'],
                $d['docroot'] ?? '-',
                $d['php'] === null ? '-' : WebSync::phpLabel((string) $d['php']),
                $d['parent_id'] === null ? '' : ($names[$d['parent_id']] ?? ''),
            ));
        }

        $web = $this->sync->stateOf((int) $account['id']);

        if ($web !== null && $web['state'] !== WebSync::APPLIED) {
            $output->line('Web server: ' . $web['state'] . ($web['error'] ? ' (' . $web['error'] . ')' : ''));
        }

        return 0;
    }

    public function add(Output $output, string $username, string $domain, ?string $type = null, ?string $docroot = null, ?string $php = null, ?string $target = null, bool $wildcard = false): int
    {
        $account = $this->account($output, $username);

        if ($account === null) {
            return 1;
        }

        $targetId = '';

        if ($target !== null) {
            $targetId = (string) ($this->domains->findByName((int) $account['id'], $target)['id'] ?? '');
        }

        [$outcome, $errors] = $this->actions->add($this->root(), null, $account, [
            'kind' => $type ?? Domains::ADDON,
            'name' => $domain,
            'target' => $targetId,
            'docroot' => $docroot ?? '',
            'php' => $php === null ? '' : \str_replace('.', '', $php),
            'wildcard' => $wildcard,
        ]);

        if ($outcome === null) {
            return $this->invalid($output, $errors);
        }

        return $this->report($output, $outcome, $domain . ' added to ' . $username . '.');
    }

    public function set(Output $output, string $username, string $domain, ?string $docroot = null, ?string $php = null, ?string $wildcard = null): int
    {
        $account = $this->account($output, $username);
        $row = $account === null ? null : $this->domains->findByName((int) $account['id'], $domain);

        if ($account === null || $row === null) {
            $output->error($account === null ? '' : \sprintf('%s has no domain %s.', $username, $domain));

            return 1;
        }

        [$outcome, $errors] = $this->actions->change($this->root(), null, $account, $row, [
            'docroot' => $docroot,
            'php' => $php === null ? null : \str_replace('.', '', $php),
            'wildcard' => $wildcard === null ? null : \in_array($wildcard, ['1', 'yes', 'on', 'true'], true),
        ]);

        if ($outcome === null) {
            return $this->invalid($output, $errors);
        }

        return $this->report($output, $outcome, $domain . ' changed.');
    }

    public function remove(Output $output, string $username, string $domain): int
    {
        $account = $this->account($output, $username);
        $row = $account === null ? null : $this->domains->findByName((int) $account['id'], $domain);

        if ($account === null || $row === null) {
            $output->error($account === null ? '' : \sprintf('%s has no domain %s.', $username, $domain));

            return 1;
        }

        [$outcome, $blocker] = $this->actions->remove($this->root(), null, $account, $row);

        if ($outcome === null) {
            $output->error($this->localization->get((string) $blocker));

            return 2;
        }

        return $this->report($output, $outcome, $domain . ' removed.');
    }

    public function info(Output $output): int
    {
        $info = $this->sync->info();

        if ($info === null) {
            $output->error('The agent could not be asked.');

            return 1;
        }

        $output->line(\sprintf('Web server: %s, %s, %s', $info['server'] ?? '?', ($info['installed'] ?? false) ? 'installed' : 'not installed', ($info['running'] ?? false) ? 'running' : 'stopped'));
        $output->line(\sprintf('Accounts with sites: %d', (int) ($info['accounts'] ?? 0)));

        foreach (\is_array($info['php'] ?? null) ? $info['php'] : [] as $v) {
            $output->line(\sprintf('PHP %-4s %3d pools  %s', WebSync::phpLabel((string) $v['version']), (int) $v['pools'], $v['running'] ? 'running' : 'stopped'));
        }

        return 0;
    }

    public function settle(Output $output): int
    {
        $counts = $this->sync->settleAll();

        if ($counts !== []) {
            $output->line(\implode(', ', \array_map(static fn(string $k, int $v): string => "{$v} {$k}", \array_keys($counts), $counts)));
        }

        return 0;
    }

    /** Send an account's sites to the server again, as the panel has them. */
    public function sync(Output $output, string $username): int
    {
        $account = $this->account($output, $username);

        return $account === null ? 1 : $this->report($output, $this->sync->touch((int) $account['id']), $username . '\'s sites are as the panel has them.');
    }

    /** @param array<string, string> $errors */
    private function invalid(Output $output, array $errors): int
    {
        foreach ($errors as $field => $key) {
            $output->error($field . ': ' . $this->localization->get($key));
        }

        return 2;
    }

    private function report(Output $output, WebOutcome $outcome, string $done): int
    {
        switch ($outcome->status) {
            case WebOutcome::DONE:
                $output->success($done);

                return 0;
            case WebOutcome::PENDING:
                $output->line('Recorded; the agent is not reachable' . ($outcome->message ? ' (' . $outcome->message . ')' : '') . '. web:settle will finish it.');

                return 0;
            case WebOutcome::SKIPPED:
                $output->error('The account is being made or removed; try again when it is done.');

                return 1;
            default:
                $output->error($this->localization->get('Web.refused') . ' (' . $outcome->code . ': ' . $outcome->message . ')');

                return 1;
        }
    }

    /** @return array<string, mixed>|null */
    private function account(Output $output, string $username): ?array
    {
        $row = $this->accounts->findByUsernameUnscoped($username);

        if ($row === null) {
            $output->error(\sprintf('There is no account named %s.', $username));
        }

        return $row;
    }

    private function root(): Identity
    {
        return $this->users->root()->identity;
    }
}
