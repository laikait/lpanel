<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Routing\Router;
use App\Modules\Panel\Ui\Tiles;
use App\Modules\Subusers\Subusers;

/**
 * Where the Web module hooks into the others.
 */
final class WebListeners
{
    public function __construct(
        private readonly Domains $domains,
        private readonly WebSync $sync,
        private readonly Subusers $subusers,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
        private readonly Bandwidth $bandwidth,
        private readonly ConnectionManager $connections,
    ) {}

    /**
     * panel.dashboard (Admin panel): how many domains there are, and the
     * server's traffic by day.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function adminDashboard(array $cards, string $area, Identity $identity, int $range = 30): array
    {
        if ($area !== 'admin' || !$this->authorizer->allows($identity, 'system.view')) {
            return $cards;
        }

        $db = $this->connections->connection();
        $dates = \array_map(static fn(array $r): string => (string) $r['created_at'], $db->table('domains')->get());
        $cards[] = Tiles::stat('Web.stat_domains', 'globe', 'ok', \count($dates), $dates, $this->router->url('admin.accounts'), 2);

        $range = \in_array($range, [7, 30, 90], true) ? $range : 30;
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $today->modify('-' . ($range - 1) . ' days');
        $perDay = [];

        foreach ($db->table('web_traffic')->where('day', '>=', $from->format('Y-m-d'))->get() as $row) {
            $perDay[(string) $row['day']] = ($perDay[(string) $row['day']] ?? 0) + (int) $row['bytes'];
        }

        $points = [];
        $max = 0;

        for ($i = 0; $i < $range; $i++) {
            $day = $from->modify('+' . $i . ' days');
            $bytes = $perDay[$day->format('Y-m-d')] ?? 0;
            $max = \max($max, $bytes);
            $points[] = ['day' => $day, 'bytes' => $bytes];
        }

        [$divisor, $unit] = $max >= 1_073_741_824 ? [1_073_741_824, ' GB'] : ($max >= 1_048_576 ? [1_048_576, ' MB'] : [1024, ' KB']);
        $cards[] = ['template' => 'Web/traffic-card', 'slot' => 'traffic', 'order' => 20, 'data' => [
            'points' => \array_map(static fn(array $p): array => ['label' => $p['day']->format('M j'), 'value' => \round($p['bytes'] / $divisor, 2)], $points),
            'unit' => $unit,
            'total' => \array_sum(\array_column($points, 'bytes')),
            'range' => $range,
            'ranges' => [7, 30, 90],
            'base' => $this->router->url('admin.dashboard'),
        ]];

        return $cards;
    }

    /**
     * accounts.changed: a new account gets its main site; suspending turns
     * every site into a "suspended" page (and stops its PHP); unsuspending
     * brings them back. Terminate removed the sites on the server already
     * (the agent does it before deleting the user), so only the rows go.
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        match ($op) {
            'account.terminate' => $this->sync->forget((int) $row['id']),
            'account.create', 'account.restore', 'account.suspend', 'account.unsuspend', 'account.modify' => $this->sync->touch((int) $row['id']),
            default => null,
        };

        // Another package may have another bandwidth limit.
        if ($op === 'account.modify') {
            $this->bandwidth->enforce();
        }
    }

    /**
     * packages.updated: a package's limits changed, so its accounts may now be
     * over their bandwidth, or no longer.
     *
     * @param array<string, mixed> $package
     */
    public function packageUpdated(array $package): void
    {
        $this->bandwidth->enforce();
    }

    /** accounts.domain_taken: a new account cannot take a name some site already answers to. */
    public function domainTaken(bool $taken, string $domain): bool
    {
        return $taken || $this->domains->taken($domain);
    }

    /**
     * panel.dashboard: the owner's sites, on the User panel.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area !== 'user' || !$this->authorizer->allows($identity, 'feature.domains')) {
            return $cards;
        }

        $account = $this->subusers->accountOf($identity);

        if ($account === null) {
            return $cards;
        }

        $cards[] = ['template' => 'Web/user-card', 'order' => 20, 'data' => [
            'domains' => \array_slice($this->domains->forAccount((int) $account['id']), 0, 6),
            'link' => $this->router->url('user.domains'),
            'bandwidth' => $this->bandwidth->usage($account),
        ]];

        return $cards;
    }

    /**
     * accounts.show: the account's sites and their state on the server, in
     * the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $cards[] = ['template' => 'Web/admin-card', 'data' => [
            'domains' => $this->domains->forAccount((int) $account['id']),
            'web' => $this->sync->stateOf((int) $account['id']),
            'bandwidth' => $this->bandwidth->usage($account),
        ]];

        return $cards;
    }
}
