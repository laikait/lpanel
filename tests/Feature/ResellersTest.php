<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Resellers\Resellers;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class ResellersTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-here');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
    }

    public function test_root_creates_a_reseller_who_then_sees_their_allowance(): void
    {
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $made = $root->post('/admin/resellers', [
            'username' => 'rex', 'name' => 'Rex Hosting', 'email' => 'rex@example.com', 'password' => 'rex-password-1234',
            'max_accounts' => '10', 'disk_mb' => '10240', 'bandwidth_mb' => '0', 'acl' => Resellers::ACL,
        ]);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $dashboard = $rex->get('/admin')->body();

        self::assertSame(303, $made->status());
        self::assertStringContainsString('Your allowance', $dashboard);
        self::assertStringContainsString('10 left', $dashboard);
        self::assertStringContainsString('/ 10 GB', $dashboard);
        self::assertSame(403, $rex->get('/admin/resellers')->status());
        self::assertContains('reseller.create', \array_column($this->auditRows(), 'action'));
    }

    public function test_the_number_of_accounts_is_capped_for_the_reseller_and_for_whoever_creates_for_them(): void
    {
        $rex = $this->reseller('rex', ['max_accounts' => 1]);
        $package = $this->packageFor($rex, 'Starter');
        $this->makeAccount('shop', $package, $rex);
        $browser = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $byRex = $browser->post('/admin/accounts', ['domain' => 'two.example.com', 'username' => 'two', 'password' => 'two-owner-password', 'package' => (string) $package]);
        $byRoot = $root->post('/admin/accounts', ['domain' => 'three.example.com', 'username' => 'three', 'password' => 'three-owner-password', 'package' => (string) $package, 'owner' => (string) $rex]);

        self::assertSame(422, $byRex->status());
        self::assertStringContainsString('This reseller has used all the accounts they are allowed.', $byRex->body());
        self::assertSame(422, $byRoot->status());
        self::assertSame(1, $this->server->ran('account.create'));
    }

    public function test_a_disk_allowance_without_overselling_holds(): void
    {
        $rex = $this->reseller('rex', ['disk_mb' => 3072]);
        $browser = $this->signedIn('admin', 'rex', 'rex-password-1234');

        // A package may not be unlimited, nor bigger than the whole allowance.
        $unlimited = $browser->post('/admin/packages', ['name' => 'Huge', 'disk_mb' => '0', 'features' => ['email']]);
        $tooBig = $browser->post('/admin/packages', ['name' => 'Big', 'disk_mb' => '4096', 'features' => ['email']]);
        self::assertSame(422, $unlimited->status());
        self::assertStringContainsString('cannot have unlimited disk', $unlimited->body());
        self::assertSame(422, $tooBig->status());

        $two = $this->packageFor($rex, 'Two GB', 2048);
        $this->makeAccount('shop', $two, $rex);
        $over = $browser->post('/admin/accounts', ['domain' => 'cafe.example.com', 'username' => 'cafe', 'password' => 'cafe-owner-password', 'package' => (string) $two]);
        self::assertSame(422, $over->status());
        self::assertStringContainsString('more disk than their allowance', $over->body());

        // Growing the package past the allowance for the account on it is refused too.
        $grow = $browser->post('/admin/packages/' . $two, ['name' => 'Two GB', 'disk_mb' => '4096', 'features' => ['email']]);
        self::assertSame(422, $grow->status());
        self::assertSame(2048 * 1024, $this->server->users['shop']['quota']['blocks_kib']);

        // With overselling the same account goes through.
        $this->app->container()->get(Resellers::class)->saveLimits($rex, $this->limits(['disk_mb' => 3072, 'oversell' => true]));
        $ok = $browser->post('/admin/accounts', ['domain' => 'cafe.example.com', 'username' => 'cafe', 'password' => 'cafe-owner-password', 'package' => (string) $two]);
        self::assertSame(303, $ok->status());
        self::assertSame('active', $this->account('cafe')['status']);
    }

    public function test_moving_an_account_to_a_package_past_the_allowance_is_refused(): void
    {
        $rex = $this->reseller('rex', ['disk_mb' => 3072]);
        $small = $this->packageFor($rex, 'Small', 1024);
        $big = $this->packageFor($rex, 'Big', 3072);
        $this->makeAccount('shop', $small, $rex);
        $this->makeAccount('cafe', $small, $rex);
        $browser = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $browser->post('/admin/accounts/' . $this->account('shop')['id'] . '/package', ['package' => (string) $big]);
        $page = $browser->get('/admin/accounts/' . $this->account('shop')['id'])->body();

        self::assertSame($small, (int) $this->account('shop')['package_id']);
        self::assertStringContainsString('more disk than their allowance', $page);
        self::assertSame(0, $this->server->ran('account.modify'));
    }

    public function test_the_acl_takes_actions_away_everywhere(): void
    {
        $rex = $this->reseller('rex', ['acl' => ['accounts.create', 'accounts.suspend']]);
        $package = $this->packageFor($rex, 'Starter');
        $shop = $this->makeAccount('shop', $package, $rex);
        $browser = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $rex, 'billing')['token'];

        $page = $browser->get('/admin/accounts/' . $shop['id'])->body();

        self::assertStringContainsString('Suspend', $page);
        self::assertStringNotContainsString('Type shop to confirm', $page);
        self::assertStringNotContainsString('Change package', $page);
        self::assertSame(403, $browser->post('/admin/accounts/' . $shop['id'] . '/terminate', ['confirm' => 'shop'])->status());
        self::assertSame(403, $this->api('DELETE', '/admin/api/v1/accounts/shop', $token)->status());
        self::assertSame(403, $browser->get('/admin/packages')->status());
        self::assertStringNotContainsString('/admin/packages"', $browser->get('/admin')->body());
        self::assertSame(200, $this->api('POST', '/admin/api/v1/accounts/shop/suspend', $token, ['reason' => 'allowed'])->status());
        self::assertArrayHasKey('shop', $this->server->users);
    }

    public function test_the_api_and_the_cli_manage_resellers(): void
    {
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) ($this->app->container()->get(PanelUsers::class)->find('alice') ?? self::fail('no alice'))['id'], 'ops')['token'];

        $made = $this->api('POST', '/admin/api/v1/resellers', $token, ['username' => 'rita', 'password' => 'rita-password-123', 'max_accounts' => 5]);
        $changed = $this->api('PUT', '/admin/api/v1/resellers/rita/limits', $token, ['disk_mb' => 2048, 'oversell' => true]);
        $listed = $this->api('GET', '/admin/api/v1/resellers', $token);

        self::assertSame(201, $made->status());
        self::assertSame(5, $this->json($made)['data']['limits']['max_accounts']);
        self::assertSame(200, $changed->status());
        self::assertSame(['max_accounts' => 5, 'disk_mb' => 2048, 'bandwidth_mb' => 0, 'oversell' => true], \array_intersect_key($this->json($changed)['data']['limits'], \array_flip(['max_accounts', 'disk_mb', 'bandwidth_mb', 'oversell'])));
        self::assertSame('rita', $this->json($listed)['data'][0]['username']);

        $ritaToken = $this->app->container()->get(ApiTokens::class)->issue((string) $this->json($made)['data']['id'], 'mine')['token'];
        self::assertSame(403, $this->api('GET', '/admin/api/v1/resellers', $ritaToken)->status());
    }

    public function test_a_reseller_is_deleted_only_when_they_have_nothing_left(): void
    {
        $rex = $this->reseller('rex', []);
        $package = $this->packageFor($rex, 'Starter');
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $root->post('/admin/resellers/' . $rex . '/delete');
        self::assertNotNull($this->app->container()->get(Resellers::class)->find($rex));

        $this->app->container()->get(\App\Modules\Packages\Packages::class)->delete($package);
        $root->post('/admin/resellers/' . $rex . '/delete');
        self::assertNull($this->app->container()->get(Resellers::class)->find($rex));
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $limits */
    private function reseller(string $username, array $limits): int
    {
        $resellers = $this->app->container()->get(Resellers::class);

        return (int) $resellers->create($username, \ucfirst($username), null, new Secret($username . '-password-1234'), $this->limits($limits));
    }

    /**
     * @param array<string, mixed> $limits
     *
     * @return array<string, mixed>
     */
    private function limits(array $limits): array
    {
        [$clean] = $this->app->container()->get(Resellers::class)->validateLimits($limits + ['acl' => Resellers::ACL]);

        return $clean;
    }
}
