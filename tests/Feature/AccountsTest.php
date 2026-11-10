<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\TestCase;

final class AccountsTest extends TestCase
{
    private Application $app;

    private FakeServer $server;

    private PanelUsers $users;

    private string $rex;

    private string $rita;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-here');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->users = $this->app->container()->get(PanelUsers::class);
        $this->users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
        $this->rex = $this->users->create(Kind::Reseller, 'rex', 'Rex', null, new Secret('rex-password-1234'));
        $this->rita = $this->users->create(Kind::Reseller, 'rita', 'Rita', null, new Secret('rita-password-123'));
    }

    public function test_a_reseller_creates_an_account_from_their_package_and_the_owner_signs_in(): void
    {
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $made = $rex->post('/admin/packages', ['name' => 'Starter', 'disk_mb' => '2048', 'inodes' => '50000', 'domains' => '1', 'features' => ['email', 'ssh']]);
        $package = $this->packageId('Starter');
        $created = $rex->post('/admin/accounts', [
            'domain' => 'Shop.Example.com', 'username' => 'shop', 'password' => 'shop-owner-password', 'email' => 'owner@example.com', 'package' => (string) $package,
        ]);
        $account = $this->account('shop');

        self::assertSame(303, $made->status());
        self::assertSame(303, $created->status());
        self::assertSame(Accounts::ACTIVE, $account['status']);
        self::assertSame('shop.example.com', $account['domain']);
        self::assertSame((int) $this->rex, (int) $account['owner_id']);
        self::assertSame('/home/shop', $account['home']);
        // The kernel quota the agent was asked for: 2048 MB, 50 000 files, and a shell because of "ssh".
        self::assertSame(['blocks_kib' => 2048 * 1024, 'inodes' => 50000], $this->server->users['shop']['quota']);
        self::assertSame('bash', $this->server->users['shop']['shell']);

        $page = $rex->get('/admin/accounts/' . $account['id'])->body();
        self::assertStringContainsString('shop.example.com', $page);
        self::assertStringContainsString('8.0 KB', $page);
        self::assertStringContainsString('of 2.0 GB', $page);

        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $dashboard = $owner->get('/panel')->body();
        self::assertStringContainsString('shop.example.com', $dashboard);
        self::assertStringContainsString('Package Starter', $dashboard);
        self::assertContains('account.create', $this->actions());
    }

    public function test_resellers_see_only_their_own_and_administrators_see_everything(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $this->makeAccount('cafe', 'cafe.example.com', $this->packageFor((int) $this->rita, 'Rita Basic'), (int) $this->rita);
        $shop = $this->account('shop');

        $rita = $this->signedIn('admin', 'rita', 'rita-password-123');
        $alice = $this->signedIn('admin', 'alice', 'alice-password-123');

        $ritaList = $rita->get('/admin/accounts')->body();
        self::assertStringContainsString('cafe.example.com', $ritaList);
        self::assertStringNotContainsString('shop.example.com', $ritaList);
        self::assertSame(404, $rita->get('/admin/accounts/' . $shop['id'])->status());
        self::assertSame(404, $rita->post('/admin/accounts/' . $shop['id'] . '/suspend', ['reason' => 'mine now'])->status());
        self::assertStringNotContainsString('>Starter</strong>', $rita->get('/admin/packages')->body());
        self::assertStringContainsString('>Rita Basic</strong>', $rita->get('/admin/packages')->body());

        $all = $alice->get('/admin/accounts')->body();
        self::assertStringContainsString('cafe.example.com', $all);
        self::assertStringContainsString('shop.example.com', $all);
    }

    public function test_a_reseller_cannot_use_another_resellers_package_or_name_an_owner(): void
    {
        $ritaPackage = $this->packageFor((int) $this->rita, 'Rita Basic');
        $this->rexPackage();
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $response = $rex->post('/admin/accounts', [
            'domain' => 'sneaky.example.com', 'username' => 'sneaky', 'password' => 'sneaky-password-1', 'package' => (string) $ritaPackage, 'owner' => $this->rita,
        ]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose one of the packages offered.', $response->body());
        self::assertSame([], $this->server->executed);
    }

    public function test_bad_input_is_refused_before_the_agent_is_asked(): void
    {
        $root = $this->signedIn('admin', 'root', 'root-password-here');
        $package = $this->packageFor(null, 'Starter');

        $response = $root->post('/admin/accounts', ['domain' => 'not a domain', 'username' => 'root', 'password' => 'short', 'package' => (string) $package]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('That username is taken or reserved.', $response->body());
        self::assertStringContainsString('That is not a domain name', $response->body());
        self::assertStringContainsString('Use at least 12 characters.', $response->body());
        self::assertSame([], $this->server->executed);
    }

    public function test_a_failed_create_leaves_nothing_behind(): void
    {
        $this->server->failNext = ['op' => 'account.create', 'code' => 'exists', 'message' => 'a group named shop already exists'];
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $response = $root->post('/admin/accounts', ['domain' => 'shop.example.com', 'username' => 'shop', 'password' => 'shop-owner-password', 'package' => (string) $this->packageFor(null, 'Starter')]);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('The server already has a user, group or home folder by that name.', $response->body());
        self::assertNull($this->app->container()->get(Accounts::class)->findByUsernameUnscoped('shop'));
        self::assertNull($this->users->find('shop'));
        self::assertContains('failed', $this->outcomes('account.create'));
    }

    public function test_suspending_signs_out_the_owner_and_sub_users_and_unsuspending_lets_them_back(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $shop = $this->account('shop');
        $this->users->create(Kind::Subuser, 'shop.dev', '', null, new Secret('developer-password'), (int) $shop['login_id']);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $dev = $this->signedIn('user', 'shop.dev', 'developer-password');
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $rex->post('/admin/accounts/' . $shop['id'] . '/suspend', ['reason' => 'Unpaid invoice']);

        self::assertSame(Accounts::SUSPENDED, $this->account('shop')['status']);
        self::assertSame('Unpaid invoice', $this->account('shop')['suspend_reason']);
        self::assertTrue($this->server->users['shop']['suspended']);
        self::assertSame(302, $owner->get('/panel')->status());
        self::assertSame(302, $dev->get('/panel')->status());
        self::assertSame(422, $this->browser($this->app)->signIn('user', 'shop', 'shop-owner-password')->status());

        $rex->post('/admin/accounts/' . $shop['id'] . '/unsuspend');

        self::assertSame(Accounts::ACTIVE, $this->account('shop')['status']);
        self::assertFalse($this->server->users['shop']['suspended']);
        self::assertSame(303, $this->browser($this->app)->signIn('user', 'shop', 'shop-owner-password')->status());
        self::assertSame(['account.suspend', 'account.unsuspend'], \array_values(\array_intersect($this->actions(), ['account.suspend', 'account.unsuspend'])));
    }

    public function test_terminating_needs_the_username_and_removes_everything_once_the_agent_is_done(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $shop = $this->account('shop');
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $rex->post('/admin/accounts/' . $shop['id'] . '/terminate', ['confirm' => 'nope']);
        self::assertSame([], \array_values(\array_filter($this->server->executed, static fn(string $op): bool => $op === 'account.terminate')));

        $rex->post('/admin/accounts/' . $shop['id'] . '/terminate', ['confirm' => 'shop']);
        // The agent removes the home in the background; until it reports, the account shows as being removed.
        self::assertSame(Accounts::TERMINATING, $this->account('shop')['status']);
        self::assertArrayNotHasKey('shop', $this->server->users);

        $this->provisioner()->settleAll();

        self::assertNull($this->app->container()->get(Accounts::class)->findByUsernameUnscoped('shop'));
        self::assertNull($this->users->find('shop'));
        self::assertSame(1, $this->server->ran('account.terminate'));
    }

    public function test_an_unreachable_agent_records_the_change_and_settle_finishes_it_exactly_once(): void
    {
        $package = $this->rexPackage();
        $this->server->down = true;
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $rex->post('/admin/accounts', ['domain' => 'shop.example.com', 'username' => 'shop', 'password' => 'shop-owner-password', 'package' => (string) $package]);
        $pending = $this->account('shop');
        self::assertSame(Accounts::CREATING, $pending['status']);
        self::assertNotNull($pending['op_id']);

        $this->provisioner()->settleAll();
        self::assertSame(Accounts::CREATING, $this->account('shop')['status']);

        // Back, but the reply to the next request is lost after the work is done.
        $this->server->down = false;
        $this->server->loseNextReply = true;
        $this->provisioner()->settleAll();
        self::assertSame($pending['op_id'], $this->account('shop')['op_id'], 'still waiting for an answer, under the same id');
        self::assertArrayHasKey('shop', $this->server->users);

        // The retry carries the same id: the agent answers from its journal.
        $this->provisioner()->settleAll();

        self::assertSame(Accounts::ACTIVE, $this->account('shop')['status']);
        self::assertNull($this->account('shop')['op_id']);
        self::assertSame(1, $this->server->ran('account.create'));
    }

    public function test_one_change_at_a_time(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $this->server->down = true;
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $id = $this->account('shop')['id'];

        $rex->post('/admin/accounts/' . $id . '/suspend', ['reason' => 'first']);
        $page = $rex->post('/admin/accounts/' . $id . '/terminate', ['confirm' => 'shop']);
        $shown = $rex->get('/admin/accounts/' . $id)->body();

        self::assertSame(303, $page->status());
        self::assertStringContainsString('Another change to this account is still being made.', $shown);
        self::assertSame('account.suspend', $this->account('shop')['op']);
    }

    public function test_changing_a_package_resizes_every_account_on_it(): void
    {
        $package = $this->rexPackage();
        $this->makeAccount('shop', 'shop.example.com', $package, (int) $this->rex);
        $this->makeAccount('cafe', 'cafe.example.com', $package, (int) $this->rex);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $rex->post('/admin/packages/' . $package, ['name' => 'Starter', 'disk_mb' => '4096', 'inodes' => '0', 'features' => ['email']]);

        foreach (['shop', 'cafe'] as $name) {
            self::assertSame(['blocks_kib' => 4096 * 1024, 'inodes' => 0], $this->server->users[$name]['quota']);
        }

        self::assertSame(303, $rex->post('/admin/packages/' . $package . '/delete')->status());
        self::assertNotNull($this->app->container()->get(Packages::class)->byId($package), 'a package in use is not deleted');
    }

    public function test_moving_an_account_to_another_package(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $bigger = $this->packageFor((int) $this->rex, 'Business', 10240);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $rex->post('/admin/accounts/' . $this->account('shop')['id'] . '/package', ['package' => (string) $bigger]);

        self::assertSame($bigger, (int) $this->account('shop')['package_id']);
        self::assertSame(10240 * 1024, $this->server->users['shop']['quota']['blocks_kib']);
    }

    public function test_the_api_works_with_a_token_and_never_with_a_session_cookie(): void
    {
        $package = $this->rexPackage();
        $token = $this->app->container()->get(ApiTokens::class)->issue($this->rex, 'billing')['token'];

        $created = $this->api('POST', '/admin/api/v1/accounts', $token, ['domain' => 'api.example.com', 'username' => 'apishop', 'password' => 'api-owner-password', 'package' => 'Starter']);
        $listed = $this->api('GET', '/admin/api/v1/accounts', $token);
        $suspended = $this->api('POST', '/admin/api/v1/accounts/apishop/suspend', $token, ['reason' => 'unpaid']);
        $again = $this->api('POST', '/admin/api/v1/accounts/apishop/suspend', $token, []);
        $invalid = $this->api('POST', '/admin/api/v1/accounts', $token, ['domain' => 'x', 'username' => 'Bad Name', 'password' => '1', 'package' => $package]);
        $deleted = $this->api('DELETE', '/admin/api/v1/accounts/apishop', $token);

        self::assertSame(201, $created->status());
        self::assertSame('active', $this->json($created)['data']['status']);
        self::assertSame('apishop', $this->json($listed)['data'][0]['username']);
        self::assertSame(200, $suspended->status());
        self::assertSame('suspended', $this->json($suspended)['data']['status']);
        self::assertSame(409, $again->status());
        self::assertSame(422, $invalid->status());
        self::assertArrayHasKey('username', $this->json($invalid)['errors']);
        self::assertSame(202, $deleted->status());

        // Another reseller's token does not see it; a signed-in browser without a token is nobody here.
        $ritaToken = $this->app->container()->get(ApiTokens::class)->issue($this->rita, 'billing')['token'];
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/apishop', $ritaToken)->status());
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        self::assertSame(401, $rex->get('/admin/api/v1/accounts', ['Accept' => 'application/json'])->status());
        self::assertSame(401, $this->api('GET', '/admin/api/v1/accounts', 'lpat_not-a-real-token')->status());
    }

    public function test_tokens_are_made_on_their_page_shown_once_and_revoked(): void
    {
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $page = $rex->post('/admin/tokens', ['name' => 'Billing system', 'days' => '30'])->body();
        \preg_match('/lpat_[A-Za-z0-9_-]{43}/', $page, $m);
        $token = $m[0] ?? '';

        self::assertNotSame('', $token);
        self::assertSame(200, $this->api('GET', '/admin/api/v1/packages', $token)->status());
        self::assertStringNotContainsString($token, $rex->get('/admin/tokens')->body());

        $id = (string) $this->app->container()->get(ApiTokens::class)->of($this->rex)[0]['id'];
        $rex->post('/admin/tokens/' . $id . '/revoke');

        self::assertSame(401, $this->api('GET', '/admin/api/v1/packages', $token)->status());
        self::assertContains('api_token.revoke', $this->actions());
    }

    public function test_user_panel_logins_cannot_reach_hosting_administration(): void
    {
        $this->makeAccount('shop', 'shop.example.com', $this->rexPackage(), (int) $this->rex);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->account('shop')['login_id'], 'mine')['token'];

        self::assertSame(403, $owner->get('/admin/accounts')->status());
        self::assertSame(403, $this->api('GET', '/admin/api/v1/accounts', $token)->status());
    }

    // -----------------------------------------------------------------------

    private function signedIn(string $area, string $username, string $password): Browser
    {
        $browser = $this->browser($this->app);
        $browser->signIn($area, $username, $password);

        return $browser;
    }

    private function provisioner(): Provisioner
    {
        return $this->app->container()->get(Provisioner::class);
    }

    private function rexPackage(): int
    {
        return $this->packageFor((int) $this->rex, 'Starter');
    }

    private function packageFor(?int $owner, string $name, int $diskMb = 1024): int
    {
        $packages = $this->app->container()->get(Packages::class);
        [$clean] = $packages->validate(['name' => $name, 'disk_mb' => $diskMb, 'features' => ['email']], $owner);

        return (int) $packages->create($clean, $owner);
    }

    private function packageId(string $name): int
    {
        return (int) ($this->db()->table('packages')->where('name', $name)->first()['id'] ?? 0);
    }

    private function makeAccount(string $username, string $domain, int $package, ?int $owner): void
    {
        $root = $this->users->root()->identity;
        $outcome = $this->provisioner()->create($root, null, [
            'username' => $username, 'domain' => $domain, 'email' => '', 'name' => '',
            'password' => new Secret($username . '-owner-password'), 'owner_id' => $owner, 'package_id' => $package,
        ]);
        self::assertSame('done', $outcome->status);
    }

    /** @return array<string, mixed> */
    private function account(string $username): array
    {
        return $this->app->container()->get(Accounts::class)->findByUsernameUnscoped($username) ?? self::fail('no account ' . $username);
    }

    /** @param array<string, mixed>|null $body */
    private function api(string $method, string $path, string $token, ?array $body = null): Response
    {
        return $this->app->handle(Request::create($method, $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => $body === null ? '' : \json_encode($body, \JSON_THROW_ON_ERROR),
        ]));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = \json_decode($response->body(), true);

        return \is_array($data) ? $data : self::fail('not JSON: ' . $response->body());
    }

    /** @return list<string> */
    private function actions(): array
    {
        return \array_values(\array_map(static fn(array $row): string => (string) $row['action'], $this->db()->table('audit_log')->orderBy('id')->get()));
    }

    /** @return list<string> */
    private function outcomes(string $action): array
    {
        return \array_values(\array_map(static fn(array $row): string => (string) $row['outcome'], $this->db()->table('audit_log')->where('action', $action)->get()));
    }

    private function db(): \App\Engine\Database\Connection
    {
        return $this->app->container()->get(ConnectionManager::class)->connection();
    }
}
