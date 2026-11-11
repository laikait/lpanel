<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;
use App\Modules\Web\Bandwidth;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class BandwidthTest extends TestCase
{
    use HostingFixtures;

    private const MB = 1048576;

    private Application $app;

    private FakeServer $server;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
    }

    public function test_this_months_bytes_are_counted_shown_and_never_twice(): void
    {
        $package = $this->packageFor(null, 'Starter', 1024, ['domains'], 100);
        $shop = $this->makeAccount('shop', $package, null);
        $lastMonth = \date('Y-m-d', \strtotime('first day of last month'));
        $this->server->traffic = ['shop' => [\date('Y-m-01') => 3 * self::MB, \date('Y-m-d') => 2 * self::MB, $lastMonth => 50 * self::MB], 'stranger' => [\date('Y-m-d') => 9]];

        self::assertTrue($this->bandwidth()->refresh());
        self::assertTrue($this->bandwidth()->refresh(), 'the agent answers running totals');
        $usage = $this->bandwidth()->usage($shop);
        $expected = \date('Y-m-01') === \date('Y-m-d') ? 2 * self::MB : 5 * self::MB;
        self::assertSame($expected, $usage['used'], 'only this month, each day once');
        self::assertSame(100 * self::MB, $usage['limit']);
        self::assertSame(\intdiv($expected * 100, 100 * self::MB), $usage['percent']);
        self::assertFalse($usage['over']);

        $alice = $this->signedIn('admin', 'alice', 'alice-password-123');
        $page = $alice->get('/admin/accounts/' . $shop['id'])->body();
        self::assertStringContainsString('Bandwidth this month', $page);
        self::assertStringContainsString('of 100', $page);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        self::assertStringContainsString('Bandwidth this month', $owner->get('/panel')->body());

        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        $api = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/bandwidth', $token))['data'];
        self::assertSame($expected, $api['used']);
        self::assertCount(30, $api['days']);
        self::assertSame(2 * self::MB, \end($api['days'])['bytes']);

        // A terminated account's counts go with it.
        $this->db()->table('web_traffic')->count() > 0 || self::fail('nothing stored');
        $this->app->container()->get(\App\Modules\Web\WebSync::class)->forget((int) $shop['id']);
        self::assertSame(0, $this->db()->table('web_traffic')->where('account_id', (int) $shop['id'])->count());
    }

    public function test_an_account_over_a_blocking_limit_has_its_sites_stopped_until_the_limit_is_raised(): void
    {
        $package = $this->packageFor(null, 'Tiny', 1024, ['domains'], 1);
        $this->makeAccount('shop', $package, null);
        $this->server->traffic = ['shop' => [\date('Y-m-d') => 2 * self::MB]];

        $this->bandwidth()->refresh();
        self::assertSame(['limited' => 1, 'restored' => 0], $this->bandwidth()->enforce());
        self::assertTrue($this->server->sites['shop']['limited'], 'the server was told');
        self::assertSame(['limited' => 0, 'restored' => 0], $this->bandwidth()->enforce(), 'once is enough');
        self::assertContains('account.bandwidth_limited', \array_column($this->auditRows(), 'action'));

        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        self::assertStringContainsString('the sites are stopped', $owner->get('/panel')->body());

        // Raising the package's limit starts them again at once.
        $alice = $this->signedIn('admin', 'alice', 'alice-password-123');
        $row = $this->app->container()->get(Packages::class)->byId($package) ?? self::fail('no package');
        self::assertSame(303, $alice->post('/admin/packages/' . $package, ['name' => $row['name'], 'disk_mb' => '1024', 'bandwidth_mb' => '10', 'bandwidth_action' => 'block', 'features' => ['domains']])->status());
        self::assertFalse($this->server->sites['shop']['limited']);
        self::assertContains('account.bandwidth_restored', \array_column($this->auditRows(), 'action'));
    }

    public function test_a_package_that_only_warns_keeps_the_sites_running(): void
    {
        $packages = $this->app->container()->get(Packages::class);
        [$clean, $errors] = $packages->validate(['name' => 'Warned', 'bandwidth_mb' => 1, 'bandwidth_action' => 'warn', 'features' => ['domains']], null);
        self::assertSame([], $errors);
        $this->makeAccount('shop', (int) $packages->create($clean, null), null);
        $this->server->traffic = ['shop' => [\date('Y-m-d') => 2 * self::MB]];

        $this->bandwidth()->refresh();
        self::assertSame(['limited' => 0, 'restored' => 0], $this->bandwidth()->enforce());
        self::assertFalse($this->server->sites['shop']['limited']);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        self::assertStringContainsString('The sites keep running', $owner->get('/panel')->body());

        [, $bad] = $packages->validate(['name' => 'Odd', 'bandwidth_action' => 'throttle'], null);
        self::assertArrayHasKey('bandwidth_action', $bad);
    }

    public function test_an_agent_that_cannot_be_asked_changes_nothing(): void
    {
        $package = $this->packageFor(null, 'Tiny', 1024, ['domains'], 1);
        $this->makeAccount('shop', $package, null);
        $this->server->down = true;

        self::assertFalse($this->bandwidth()->refresh());
        self::assertSame(0, $this->db()->table('web_traffic')->count());
    }

    private function bandwidth(): Bandwidth
    {
        return $this->app->container()->get(Bandwidth::class);
    }
}
