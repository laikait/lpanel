<?php

declare(strict_types=1);

namespace App\Modules\Websites\Commands;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Operations\OperationException;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;
use App\Modules\Websites\Http\WebsiteViews;
use App\Modules\Websites\Sites\Website;
use App\Modules\Websites\Sites\WebsiteActions;
use App\Modules\Websites\Sites\WebsiteStore;

/**
 * websites:create, websites:list, websites:suspend, websites:unsuspend,
 * websites:restore, websites:delete and websites:check -- for an
 * administrator with a shell on the panel host, the scheduler (the hourly
 * check) and the package test. Audited as the console.
 */
final class WebsiteCommands
{
    public function __construct(
        private readonly WebsiteActions $actions,
        private readonly WebsiteStore $websites,
        private readonly WebsiteViews $views,
        private readonly AccountStore $accounts,
        private readonly ServerStore $servers,
    ) {}

    public function create(Output $output, string $account, string $domains, ?string $root = null, bool $static = false, bool $wait = false, int $timeout = 120): int
    {
        $found = $this->accounts->byUid($account);

        foreach ($found === null ? $this->accounts->page(null, 'current') : [] as $a) {
            if ($a->systemUser === $account) {
                $found = $a;
            }
        }

        if ($found === null) {
            $output->line(\sprintf('No hosting account "%s" (give its id or its system user).', $account));

            return 1;
        }

        try {
            $website = $this->actions->create(self::console(), $found, ['domains' => $domains, 'document_root' => $root ?? '', 'php' => !$static]);
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        return $this->finish($output, $website, $wait, $timeout, Website::ACTIVE);
    }

    public function list(Output $output, bool $json = false, string $status = 'current'): int
    {
        if (!isset(WebsiteStore::GROUPS[$status])) {
            $output->line('--status is one of ' . \implode(', ', \array_keys(WebsiteStore::GROUPS)) . '.');

            return 1;
        }

        $websites = $this->websites->page(null, $status);

        if ($json) {
            $output->line(\json_encode(\array_map($this->views->present(...), $websites), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return 0;
        }

        foreach ($websites as $w) {
            $output->line(\sprintf('%s  %-12s  %-6s  %s', $w->uid, $w->status, $w->configState, \implode(' ', $w->domains())));
        }

        return 0;
    }

    public function suspend(Output $output, string $website, bool $wait = false, int $timeout = 120): int
    {
        return $this->act($output, $website, 'suspend', null, $wait, $timeout, Website::SUSPENDED);
    }

    public function unsuspend(Output $output, string $website, bool $wait = false, int $timeout = 120): int
    {
        return $this->act($output, $website, 'unsuspend', null, $wait, $timeout, Website::ACTIVE);
    }

    public function delete(Output $output, string $website, ?string $confirm = null, bool $wait = false, int $timeout = 120): int
    {
        return $this->act($output, $website, 'delete', $confirm, $wait, $timeout, Website::DELETED);
    }

    public function restore(Output $output, string $website, bool $wait = false, int $timeout = 120): int
    {
        $found = $this->find($website);

        if ($found === null) {
            $output->line(\sprintf('No website "%s" (give its id or its domain).', $website));

            return 1;
        }

        try {
            $found = $this->actions->update(self::console(), $found, $found->settings, true);
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        return $this->finish($output, $found, $wait, $timeout, $found->previousStatus ?? Website::ACTIVE);
    }

    /** Queue websites.check on $server, or on every paired server with websites. */
    public function check(Output $output, ?string $server = null): int
    {
        $targets = [];

        foreach ($this->servers->all() as $s) {
            if ($s->state === Server::ACTIVE && ($server === null ? $this->websites->servedOn($s->id) : ($s->uid === $server || $s->name === $server))) {
                $targets[] = $s;
            }
        }

        if ($server !== null && $targets === []) {
            $output->line(\sprintf('No paired server "%s".', $server));

            return 1;
        }

        $failed = 0;

        foreach ($targets as $s) {
            if (!\in_array(1, $s->capabilities['websites.check'] ?? [], true)) {
                $output->line(\sprintf('%s: its agent cannot check websites yet.', $s->name));

                continue;
            }

            try {
                $op = $this->actions->check(self::console(), $s);
                $output->line(\sprintf('%s: %s %s', $s->name, $op->uid, $op->status));
            } catch (OperationException $e) {
                $output->line(\sprintf('%s: %s: %s', $s->name, $e->errorCode, $e->getMessage()));
                ++$failed;
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    private function act(Output $output, string $id, string $action, ?string $confirm, bool $wait, int $timeout, string $goal): int
    {
        $website = $this->find($id);

        if ($website === null) {
            $output->line(\sprintf('No website "%s" (give its id or its domain).', $id));

            return 1;
        }

        try {
            $website = $this->actions->act(self::console(), $website, $action, $confirm);
        } catch (OperationException $e) {
            $output->line(\sprintf('%s: %s', $e->errorCode, $e->getMessage()));

            return 1;
        }

        return $this->finish($output, $website, $wait, $timeout, $goal);
    }

    /** Print it; with $wait, once it settles (exit 0 only if it reached $goal). */
    private function finish(Output $output, Website $website, bool $wait, int $timeout, string $goal): int
    {
        $deadline = \time() + \max(1, \min(3600, $timeout));

        while ($wait && $website->isBusy() && \time() < $deadline) {
            \usleep(250000);
            $website = $this->websites->byUid($website->uid) ?? $website;
        }

        $output->line(\sprintf('%s  %s  %s%s', $website->uid, $website->domain, $website->status, $website->lastError === null ? '' : '  ' . $website->lastError));

        return !$wait || $website->status === $goal ? 0 : 1;
    }

    private function find(string $id): ?Website
    {
        $website = $this->websites->byUid($id);

        if ($website !== null) {
            return $website;
        }

        foreach ($this->websites->page(null, 'current') as $w) {
            if (\in_array(\strtolower($id), $w->domains(), true)) {
                return $w;
            }
        }

        return null;
    }

    private static function console(): Identity
    {
        return new Identity('console', 'Panel console', [], ['system' => true]);
    }
}
