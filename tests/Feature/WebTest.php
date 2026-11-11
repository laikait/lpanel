<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Accounts\AccountRules;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\Domains;
use App\Modules\Web\WebSync;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class WebTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private int $package;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->package = $this->packageFor(null, 'Starter', 1024, ['domains', 'email']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_a_new_account_gets_its_main_site_on_the_server(): void
    {
        $site = $this->server->sites['shop'] ?? null;

        self::assertNotNull($site, 'the account was given its site');
        self::assertFalse($site['suspended']);
        self::assertCount(1, $site['sites']);
        self::assertSame(['name' => 'shop.example.com', 'aliases' => ['www.shop.example.com'], 'docroot' => 'public_html', 'php' => '83'], \array_intersect_key($site['sites'][0], ['name' => 1, 'aliases' => 1, 'docroot' => 1, 'php' => 1]));
        self::assertSame(['', [], [], [], null], [$site['sites'][0]['canonical'], $site['sites'][0]['redirects'], (array) $site['sites'][0]['error_pages'], $site['sites'][0]['protected'], $site['sites'][0]['hotlink']], 'no features yet');
        self::assertSame(WebSync::APPLIED, $this->web('shop')['state']);
    }

    public function test_the_owner_adds_a_website_a_subdomain_and_an_alias(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $main = $this->existing('shop', 'shop.example.com');

        $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'Other.Test', 'php' => '82']);
        $owner->post('/panel/domains', ['kind' => 'sub', 'name' => 'blog.shop.example.com', 'php' => '83']);
        $owner->post('/panel/domains', ['kind' => 'alias', 'name' => 'shop.test', 'target' => (string) $main['id']]);
        $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'wild.test', 'php' => '83', 'wildcard' => '1', 'docroot' => '/public_html/wild/']);

        $sites = \array_column($this->server->sites['shop']['sites'], null, 'name');
        self::assertSame(['shop.example.com', 'blog.shop.example.com', 'other.test', 'wild.test'], \array_keys($sites));
        self::assertSame(['www.shop.example.com', 'shop.test', 'www.shop.test'], $sites['shop.example.com']['aliases']);
        self::assertSame(['docroot' => 'public_html/other.test', 'php' => '82'], \array_intersect_key($sites['other.test'], ['docroot' => 1, 'php' => 1]));
        self::assertSame([], $sites['blog.shop.example.com']['aliases'], 'a subdomain has no www.');
        self::assertSame('public_html/blog', $sites['blog.shop.example.com']['docroot']);
        self::assertSame(['www.wild.test', '*.wild.test'], $sites['wild.test']['aliases']);
        self::assertSame('public_html/wild', $sites['wild.test']['docroot']);

        $page = $owner->get('/panel/domains')->body();
        self::assertStringContainsString('blog.shop.example.com', $page);
        self::assertStringContainsString('Leads to shop.example.com', $page);
        self::assertCount(4, \array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'domain.add' && $r['outcome'] === 'ok'));
    }

    public function test_names_folders_and_limits_are_checked(): void
    {
        $this->makeAccount('other', $this->package, null);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $add = static fn(array $input): string => $owner->post('/panel/domains', $input + ['kind' => 'addon', 'php' => '83'])->body();

        self::assertStringContainsString('Leave out', $add(['name' => 'www.new.test']));
        self::assertStringContainsString('already on this server', $add(['name' => 'other.example.com']));
        self::assertStringContainsString('Leave out', $add(['name' => 'www.other.example.com']));
        self::assertStringContainsString('must end with one of your domains', $add(['kind' => 'sub', 'name' => 'blog.other.example.com']));
        self::assertStringContainsString('inside it', $add(['name' => 'new.test', 'docroot' => '../other/public_html']));
        self::assertStringContainsString('inside it', $add(['name' => 'new.test', 'docroot' => 'tmp/site']));
        self::assertStringContainsString('inside it', $add(['name' => 'new.test', 'docroot' => 'site']));
        self::assertStringContainsString('PHP versions offered', $add(['name' => 'new.test', 'php' => '74']));
        self::assertSame(1, \count($this->server->sites['shop']['sites']), 'nothing reached the server');

        // A package with 2 domains: the main one and one more.
        $small = $this->packageFor(null, 'Small', 1024, ['domains']);
        $this->db()->table('packages')->where('id', $small)->update(['domains' => 2, 'subdomains' => 1]);
        $this->db()->table('accounts')->where('username', 'shop')->update(['package_id' => $small]);
        self::assertSame(303, $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'two.test', 'php' => '83'])->status());
        self::assertStringContainsString('no more domains', $add(['name' => 'three.test']));
        self::assertSame(303, $owner->post('/panel/domains', ['kind' => 'sub', 'name' => 'a.shop.example.com', 'php' => '83'])->status());
        self::assertStringContainsString('no more subdomains', $add(['kind' => 'sub', 'name' => 'b.shop.example.com']));

        // A new account cannot take a name an existing site answers to.
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        [, $errors] = $this->app->container()->get(AccountRules::class)->validateNew(
            ['username' => 'third', 'domain' => 'two.test', 'password' => 'third-owner-password', 'package' => (string) $this->package],
            $root,
        );
        self::assertSame('Accounts.error_domain_taken', $errors['domain'] ?? null);
    }

    public function test_a_change_the_server_refuses_is_undone(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $before = $this->server->sites['shop'];
        $this->server->failNext = ['op' => 'web.apply', 'code' => 'config_rejected', 'message' => 'Apache refused the configuration: AH00526'];

        $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'broken.test', 'php' => '83']);

        self::assertNull($this->domain('shop', 'broken.test'), 'the domain was taken back out');
        self::assertSame($before, $this->server->sites['shop']);
        self::assertSame(WebSync::APPLIED, $this->web('shop')['state'], 'and the server was told the state it still has');
        self::assertStringContainsString('could not make that change', $owner->get('/panel/domains')->body());
        $failed = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'domain.add'));
        self::assertSame('failed', $failed[0]['outcome']);
    }

    public function test_an_unreachable_agent_records_the_change_and_settle_sends_it_once(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $this->server->down = true;

        $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'later.test', 'php' => '83']);

        self::assertNotNull($this->domain('shop', 'later.test'), 'the change is kept');
        self::assertSame(WebSync::PENDING, $this->web('shop')['state']);
        self::assertStringContainsString('waiting for the server', $owner->get('/panel/domains')->body());

        // Back up, and the reply to the first try is lost: the retry must
        // not apply it twice.
        $this->server->down = false;
        $this->server->loseNextReply = true;
        $sync = $this->app->container()->get(WebSync::class);
        $sync->settleAll();
        $sync->settleAll();

        self::assertSame(WebSync::APPLIED, $this->web('shop')['state']);
        self::assertContains('later.test', \array_column($this->server->sites['shop']['sites'], 'name'));
        self::assertSame(2, $this->server->ran('web.apply'), 'once at creation, once for the change');
    }

    public function test_suspending_and_terminating_follow_through_to_the_sites(): void
    {
        $provisioner = $this->app->container()->get(Provisioner::class);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $id = (int) $this->account('shop')['id'];

        $provisioner->suspend($root, null, $id, 'unpaid');
        self::assertTrue($this->server->sites['shop']['suspended']);

        $provisioner->unsuspend($root, null, $id);
        self::assertFalse($this->server->sites['shop']['suspended']);

        $provisioner->terminate($root, null, $id);
        $provisioner->settle($id);
        self::assertArrayNotHasKey('shop', $this->server->sites);
        self::assertSame(0, $this->db()->table('domains')->count());
        self::assertSame(0, $this->db()->table('web_accounts')->count());
    }

    public function test_changing_and_removing(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $main = $this->existing('shop', 'shop.example.com');
        $owner->post('/panel/domains', ['kind' => 'alias', 'name' => 'shop.test', 'target' => (string) $main['id']]);
        $owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'other.test', 'php' => '83']);

        $owner->post('/panel/domains/' . $main['id'], ['docroot' => 'public_html/live', 'php' => '82', 'wildcard' => '1']);
        $site = $this->server->sites['shop']['sites'][0];
        self::assertSame(['public_html/live', '82'], [$site['docroot'], $site['php']]);
        self::assertContains('*.shop.example.com', $site['aliases']);

        self::assertStringContainsString('cannot be removed', $this->followDelete($owner, $main));
        self::assertNotNull($this->domain('shop', 'shop.example.com'));

        $other = $this->existing('shop', 'other.test');
        $this->followDelete($owner, $other);
        self::assertNull($this->domain('shop', 'other.test'));
        self::assertNotContains('other.test', \array_column($this->server->sites['shop']['sites'], 'name'));
    }

    public function test_the_api_and_the_feature_gate(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'billing')['token'];

        $added = $this->api('POST', '/admin/api/v1/accounts/shop/domains', $token, ['name' => 'api.test', 'php' => '8.2']);
        self::assertSame(422, $added->status(), 'the version is two digits in the API too: "82"');
        $added = $this->api('POST', '/admin/api/v1/accounts/shop/domains', $token, ['name' => 'api.test', 'php' => '82']);
        $alias = $this->api('POST', '/admin/api/v1/accounts/shop/domains', $token, ['kind' => 'alias', 'name' => 'api-alias.test', 'target' => 'api.test']);
        $changed = $this->api('PUT', '/admin/api/v1/accounts/shop/domains/api.test', $token, ['php' => '83']);
        $blocked = $this->api('DELETE', '/admin/api/v1/accounts/shop/domains/api.test', $token);
        $this->api('DELETE', '/admin/api/v1/accounts/shop/domains/api-alias.test', $token);
        $removed = $this->api('DELETE', '/admin/api/v1/accounts/shop/domains/api.test', $token);
        $listed = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/domains', $token));

        self::assertSame(201, $added->status());
        self::assertSame(['name' => 'api.test', 'kind' => 'addon', 'php' => '8.2'], \array_intersect_key($this->json($added)['data'], ['name' => 1, 'kind' => 1, 'php' => 1]));
        self::assertSame(201, $alias->status());
        self::assertSame('8.3', $this->json($changed)['data']['php']);
        self::assertSame(409, $blocked->status(), 'an alias still points at it');
        self::assertSame(200, $removed->status());
        self::assertSame(['shop.example.com'], \array_column($listed['data'], 'name'));

        // Without the feature in the package, there is no Domains page.
        $bare = $this->packageFor(null, 'Bare', 1024, ['email']);
        $this->makeAccount('bare', $bare, null);
        self::assertSame(403, $this->signedIn('user', 'bare', 'bare-owner-password')->get('/panel/domains')->status());
        self::assertSame(200, $this->signedIn('user', 'shop', 'shop-owner-password')->get('/panel/domains')->status());
    }

    public function test_accounts_from_before_get_their_site_from_settle(): void
    {
        $this->db()->table('domains')->deleteAll();
        $this->db()->table('web_accounts')->deleteAll();
        unset($this->server->sites['shop']);

        $this->app->container()->get(WebSync::class)->settleAll();

        self::assertNotNull($this->domain('shop', 'shop.example.com'));
        self::assertSame('shop.example.com', $this->server->sites['shop']['sites'][0]['name']);
    }

    /** @return array<string, mixed>|null */
    private function domain(string $username, string $name): ?array
    {
        return $this->app->container()->get(Domains::class)->findByName((int) $this->account($username)['id'], $name);
    }

    /** @return array<string, mixed> */
    private function existing(string $username, string $name): array
    {
        $row = $this->domain($username, $name);
        self::assertNotNull($row, $name . ' exists');

        return $row;
    }

    /** @return array<string, mixed> */
    private function web(string $username): array
    {
        return $this->app->container()->get(WebSync::class)->stateOf((int) $this->account($username)['id']) ?? [];
    }

    /** @param array<string, mixed> $domain */
    private function followDelete(\App\Tests\Support\Browser $browser, array $domain): string
    {
        $browser->post('/panel/domains/' . $domain['id'] . '/delete');

        return $browser->get('/panel/domains')->body();
    }

}
