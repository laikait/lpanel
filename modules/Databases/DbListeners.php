<?php

declare(strict_types=1);

namespace App\Modules\Databases;

use App\Engine\Auth\Authorizer;
use App\Engine\Database\ConnectionManager;
use App\Modules\Panel\Ui\Tiles;
use App\Engine\Auth\Identity;
use App\Engine\Routing\Router;
use App\Modules\Subusers\Subusers;

/**
 * Where the Databases module hooks into the others.
 */
final class DbListeners
{
    public function __construct(
        private readonly Databases $databases,
        private readonly DbSync $sync,
        private readonly Subusers $subusers,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
        private readonly ConnectionManager $connections,
    ) {}

    /**
     * accounts.changed: suspending locks the account's database users (and
     * ends their connections); unsuspending unlocks them. Terminate dropped
     * everything on the server already (the agent does it before deleting
     * the user), so only the rows go.
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        match ($op) {
            'account.terminate' => $this->sync->forget((int) $row['id']),
            'account.restore' => $this->sync->touch((int) $row['id']),
            'account.suspend', 'account.unsuspend' => $this->databases->count((int) $row['id']) > 0 || $this->sync->stateOf((int) $row['id']) !== null
                ? $this->sync->touch((int) $row['id'])
                : null,
            default => null,
        };
    }

    /**
     * accounts.show: the account's databases and users, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $cards[] = ['template' => 'Databases/admin-card', 'data' => [
            'databases' => $this->databases->databases((int) $account['id']),
            'users' => $this->databases->users((int) $account['id']),
            'hosts' => $this->databases->hosts((int) $account['id']),
            'db_state' => $this->sync->stateOf((int) $account['id']),
        ]];

        return $cards;
    }

    /**
     * panel.dashboard: the owner's databases, on the User panel.
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area === 'admin' && $this->authorizer->allows($identity, 'system.view')) {
            $dates = \array_map(static fn(array $r): string => (string) $r['created_at'], $this->connections->connection()->table('db_databases')->get());
            $cards[] = Tiles::stat('Databases.stat_databases', 'database', 'warn', \count($dates), $dates, $this->router->url('admin.databases'), 4);

            return $cards;
        }

        if ($area !== 'user' || !$this->authorizer->allows($identity, 'feature.databases')) {
            return $cards;
        }

        $account = $this->subusers->accountOf($identity);

        if ($account === null) {
            return $cards;
        }

        $cards[] = ['template' => 'Databases/user-card', 'order' => 30, 'data' => [
            'databases' => $this->databases->databases((int) $account['id']),
            'link' => $this->router->url('user.databases'),
        ]];

        return $cards;
    }
}
