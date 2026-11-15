<?php

declare(strict_types=1);

namespace App\Modules\Dns;

use App\Engine\Auth\Identity;

/**
 * Where the DNS module hooks into the others.
 */
final class DnsListeners
{
    public function __construct(
        private readonly DnsZones $zones,
        private readonly DnsSync $sync,
    ) {}

    /**
     * accounts.changed: a new account's main domain gets its zone (Web made
     * the domain just before). Terminate removed the zones on the server
     * already (the agent does it before deleting the user), so only the
     * rows go. Suspension leaves DNS alone: the sites answer "suspended".
     *
     * @param array<string, mixed> $row
     */
    public function accountChanged(string $op, array $row): void
    {
        match ($op) {
            'account.terminate' => $this->zones->deleteAccount((int) $row['id']),
            'account.create' => $this->sync->reconcile((int) $row['id']),
            default => null,
        };
    }

    /**
     * web.changed: the account's domains changed (added, removed, wildcard
     * on or off).
     *
     * @param array<string, mixed> $account
     */
    public function webChanged(array $account): void
    {
        $this->sync->reconcile((int) $account['id']);
    }

    /**
     * ssl.wildcard: AutoSSL may ask for "*.name" when the name's zone is on
     * this server; the agent still checks that the world's DNS leads here.
     *
     * @param array<string, mixed> $account
     */
    public function wildcard(bool $possible, array $account, string $name): bool
    {
        return $possible || $this->zones->zoneHolding((int) $account['id'], $name) !== null;
    }

    /**
     * accounts.show: the account's zones, in the Admin panel.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed>       $account
     *
     * @return list<array<string, mixed>>
     */
    public function accountShow(array $cards, array $account, Identity $identity): array
    {
        $zones = [];

        foreach ($this->zones->zonesOf((int) $account['id']) as $zone) {
            $zones[] = $zone + ['records' => \count($this->zones->records((int) $zone['id']))];
        }

        $cards[] = ['template' => '@Dns/admin-card', 'data' => [
            'zones' => $zones,
            'nameservers' => $zones === [] ? [] : $this->zones->nameserversFor($account['owner_id'] === null ? null : (int) $account['owner_id'], (string) $zones[0]['name']),
        ]];

        return $cards;
    }
}
