<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Accounts;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;

/**
 * `php laika dev:seed-hosting`: packages and accounts to look at on a
 * developer's machine, after dev:seed. The accounts are rows only: there is
 * no agent on Windows, so no Linux users are made. Refuses unless APP_ENV is
 * "local".
 */
final class DevSeedHosting
{
    public function __construct(
        private readonly Packages $packages,
        private readonly Accounts $accounts,
        private readonly PanelUsers $users,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output): int
    {
        if ($this->config->get('app.env') !== 'local') {
            $output->error('dev:seed-hosting only runs with APP_ENV=local.');

            return 1;
        }

        $reseller = $this->users->find('reseller');

        if ($reseller === null) {
            $output->error('Run dev:seed first.');

            return 1;
        }

        $resellerId = (int) $reseller['id'];
        $starter = $this->package('Starter', null, 2048, 50000, 20480, Packages::DEFAULT_FEATURES);
        $business = $this->package('Business', null, 10240, 250000, 102400, [...Packages::DEFAULT_FEATURES, 'ssh']);
        $basic = $this->package('Reseller Basic', $resellerId, 1024, 30000, 10240, ['file_manager', 'email', 'databases', 'ssl']);

        // username => [domain, package, owner, status, used MB, reason]
        $accounts = [
            'demo' => ['demo-shop.test', $basic, $resellerId, Accounts::ACTIVE, 412, null],
            'bakery' => ['sunrise-bakery.test', $business, null, Accounts::ACTIVE, 7610, null],
            'oldblog' => ['old-blog.test', $basic, $resellerId, Accounts::SUSPENDED, 980, 'Unpaid invoice #1042'],
            'agency' => ['northwind-agency.test', $starter, null, Accounts::ACTIVE, 1210, null],
            'newsite' => ['brand-new-site.test', $starter, null, Accounts::CREATING, null, null],
        ];

        foreach ($accounts as $username => [$domain, $package, $owner, $status, $usedMb, $reason]) {
            if ($this->accounts->findByUsernameUnscoped($username) !== null) {
                $output->line(\sprintf('  %-10s already there', $username));

                continue;
            }

            $login = $this->users->find($username);
            $loginId = $login !== null ? (string) $login['id'] : $this->users->create(Kind::User, $username, \ucfirst($username), null, new Secret($username . '-password-123'), $owner);
            $usage = $usedMb === null ? [] : ['disk_used_bytes' => $usedMb * 1048576, 'inodes_used' => $usedMb * 9, 'usage_at' => Accounts::now()];
            $this->accounts->insert($usage + [
                'login_id' => (int) $loginId, 'username' => $username, 'domain' => $domain, 'package_id' => $package,
                'owner_id' => $owner, 'status' => $status, 'suspend_reason' => $reason,
                'uid' => $status === Accounts::CREATING ? null : 1000 + \count($this->accounts->pending()) + \random_int(1, 900),
                'home' => $status === Accounts::CREATING ? null : '/home/' . $username,
                'op' => $status === Accounts::CREATING ? 'account.create' : null,
                'op_id' => $status === Accounts::CREATING ? \bin2hex(\random_bytes(16)) : null,
                'op_args' => $status === Accounts::CREATING ? '{"username":"' . $username . '","shell":"none","quota":{"blocks_kib":2097152,"inodes":50000}}' : null,
                'op_meta' => $status === Accounts::CREATING ? '{}' : null,
                'op_started_at' => $status === Accounts::CREATING ? Accounts::now() : null,
            ]);

            if ($status === Accounts::SUSPENDED) {
                $this->users->setSuspended([(int) $loginId], true);
            }

            $output->line(\sprintf('  %-10s %-24s %s', $username, $domain, $status));
        }

        $output->success('Sample packages and accounts ready. Account owners sign in with <username>-password-123 (demo: demo-password-123).');

        return 0;
    }

    /** @param list<string> $features */
    private function package(string $name, ?int $owner, int $disk, int $inodes, int $bandwidth, array $features): int
    {
        foreach ($this->packages->usableFor($this->users->root()->identity, $owner) as $row) {
            if ($row['name'] === $name && $row['owner_id'] === $owner) {
                return (int) $row['id'];
            }
        }

        [$clean] = $this->packages->validate([
            'name' => $name, 'disk_mb' => $disk, 'inodes' => $inodes, 'bandwidth_mb' => $bandwidth,
            'domains' => $owner === null ? 10 : 1, 'subdomains' => 20, 'mailboxes' => 25, 'databases' => 10, 'ftp_accounts' => 5,
            'features' => $features,
        ], $owner);

        return (int) $this->packages->create($clean, $owner);
    }
}
