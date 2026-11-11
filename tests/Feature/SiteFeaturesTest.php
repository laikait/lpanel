<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\Domains;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class SiteFeaturesTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private Browser $owner;

    /** @var array<string, mixed> */
    private array $main;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $package = $this->packageFor(null, 'Starter', 1024, ['domains', 'php_settings']);
        $this->makeAccount('shop', $package, null);
        $this->owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $this->main = $this->domain('shop.example.com');
    }

    public function test_the_owner_sets_up_a_site_and_the_server_gets_all_of_it(): void
    {
        $base = '/panel/domains/' . $this->main['id'];

        self::assertSame(303, $this->owner->post($base, ['docroot' => 'public_html', 'php' => '83', 'canonical' => 'www'])->status());
        $this->owner->post($base . '/redirects', ['path' => 'old-shop', 'to' => 'https://shop.example.com/shop', 'code' => '301']);
        $this->owner->post($base . '/redirects', ['path' => '/sale', 'to' => 'https://deals.example.net/', 'code' => '302']);
        $this->owner->post($base . '/errors', ['page_404' => '/errors/404.html', 'page_500' => 'errors/500.html']);
        $this->owner->post($base . '/protected', ['path' => 'members/', 'realm' => 'Members', 'login' => 'ann', 'password' => 'ann-password']);
        $this->owner->post($base . '/hotlink', ['on' => '1', 'extensions' => 'JPG, .png mp4', 'allow' => 'Partner.Example.org']);

        $site = $this->server->sites['shop']['sites'][0];
        self::assertSame('www', $site['canonical']);
        self::assertSame([
            ['path' => '/old-shop', 'to' => 'https://shop.example.com/shop', 'code' => 301],
            ['path' => '/sale', 'to' => 'https://deals.example.net/', 'code' => 302],
        ], $site['redirects']);
        self::assertSame(['404' => '/errors/404.html', '500' => '/errors/500.html'], $site['error_pages']);
        self::assertSame(['extensions' => ['jpg', 'png', 'mp4'], 'allow' => ['partner.example.org']], $site['hotlink']);
        self::assertSame('members', $site['protected'][0]['path']);
        self::assertSame('ann', $site['protected'][0]['users'][0]['name']);
        self::assertTrue(\password_verify('ann-password', $site['protected'][0]['users'][0]['hash']), 'only a bcrypt hash reaches the server');
        self::assertStringStartsWith('$2y$', $site['protected'][0]['users'][0]['hash']);

        $page = $this->owner->get($base)->body();
        foreach (['/old-shop', 'https://deals.example.net/', '/members', 'ann', '/errors/404.html', 'partner.example.org'] as $shown) {
            self::assertStringContainsString($shown, $page);
        }
        self::assertStringNotContainsString('ann-password', $page);

        $actions = \array_column($this->auditRows(), 'action');
        foreach (['domain.change', 'domain.redirect', 'domain.error_pages', 'domain.protect', 'domain.hotlink'] as $action) {
            self::assertContains($action, $actions);
        }
        self::assertStringNotContainsString('ann-password', (string) \json_encode($this->auditRows()), 'no password in the audit log');
    }

    public function test_bad_input_is_shown_on_the_page_and_never_reaches_the_server(): void
    {
        $base = '/panel/domains/' . $this->main['id'];
        $before = $this->server->sites['shop'];

        foreach ([
            [$base . '/redirects', ['path' => '/a b', 'to' => 'https://x.test/'], 'characters allowed in a web address'],
            [$base . '/redirects', ['path' => '/a', 'to' => 'javascript:alert(1)'], 'starting with http'],
            [$base . '/errors', ['page_404' => '"Gone"'], 'path on this site'],
            [$base . '/protected', ['path' => '../up', 'login' => 'ann', 'password' => 'long-enough'], 'folder inside the site'],
            [$base . '/protected', ['path' => 'x', 'login' => 'ann', 'password' => 'short'], 'at least 8'],
            [$base . '/hotlink', ['on' => '1', 'extensions' => 'j.p.g'], 'file kinds'],
            [$base, ['docroot' => 'public_html', 'php' => '83', 'canonical' => 'everything'], 'addresses offered'],
        ] as [$url, $input, $message]) {
            $response = $this->owner->post($url, $input);
            self::assertSame(422, $response->status(), $url);
            self::assertStringContainsString($message, $response->body(), $url);
        }

        self::assertSame($before, $this->server->sites['shop']);
    }

    public function test_a_change_the_server_refuses_is_undone(): void
    {
        $this->server->failNext = ['op' => 'web.apply', 'code' => 'config_rejected', 'message' => 'Apache refused the configuration'];

        $this->owner->post('/panel/domains/' . $this->main['id'] . '/redirects', ['path' => '/old', 'to' => 'https://x.test/', 'code' => '301']);

        self::assertSame(0, $this->db()->table('web_redirects')->count());
        self::assertSame([], $this->server->sites['shop']['sites'][0]['redirects']);
        self::assertStringContainsString('could not make that change', $this->owner->get('/panel/domains/' . $this->main['id'])->body());
    }

    public function test_logins_are_added_changed_and_removed(): void
    {
        $base = '/panel/domains/' . $this->main['id'];
        $this->owner->post($base . '/protected', ['path' => '', 'realm' => 'Staff', 'login' => 'ann', 'password' => 'ann-password']);
        $row = $this->db()->table('web_protected')->first();
        self::assertNotNull($row);
        $folder = (int) $row['id'];

        $this->owner->post($base . '/protected/' . $folder . '/logins', ['login' => 'bob', 'password' => 'bob-password']);
        $this->owner->post($base . '/protected/' . $folder . '/logins', ['login' => 'ann', 'password' => 'new-ann-password']);
        $users = \array_column($this->server->sites['shop']['sites'][0]['protected'][0]['users'], 'hash', 'name');
        self::assertSame(['bob', 'ann'], \array_keys($users));
        self::assertTrue(\password_verify('new-ann-password', $users['ann']), 'adding a login again changes its password');

        $this->owner->post($base . '/protected/' . $folder . '/logins/bob/delete');
        self::assertSame(['ann'], \array_column($this->server->sites['shop']['sites'][0]['protected'][0]['users'], 'name'));

        $this->owner->post($base . '/protected/' . $folder . '/delete');
        self::assertSame([], $this->server->sites['shop']['sites'][0]['protected']);
        self::assertSame(404, $this->owner->post($base . '/protected/' . $folder . '/delete')->status());
    }

    public function test_an_alias_has_no_settings_of_its_own(): void
    {
        $this->owner->post('/panel/domains', ['kind' => 'alias', 'name' => 'shop.test', 'target' => (string) $this->main['id']]);
        $alias = $this->domain('shop.test');

        self::assertStringContainsString('is an alias', $this->owner->get('/panel/domains/' . $alias['id'])->body());
        self::assertSame(422, $this->owner->post('/panel/domains/' . $alias['id'] . '/redirects', ['path' => '/a', 'to' => 'https://x.test/'])->status());
    }

    public function test_php_settings_go_into_the_accounts_pool(): void
    {
        self::assertStringContainsString('memory_limit', $this->owner->get('/panel/php')->body());

        $saved = $this->owner->post('/panel/php/83', ['memory_limit' => '256M', 'display_errors' => 'On', 'date_timezone' => 'Asia/Dhaka', 'max_execution_time' => '']);
        self::assertSame(303, $saved->status());
        self::assertSame(['83' => ['date.timezone' => 'Asia/Dhaka', 'display_errors' => 'On', 'memory_limit' => '256M']], $this->server->sites['shop']['php_settings']);

        $bad = $this->owner->post('/panel/php/83', ['memory_limit' => '256M; php_admin_value', 'date_timezone' => 'Mars/Olympus']);
        self::assertSame(422, $bad->status());
        self::assertSame('256M', $this->server->sites['shop']['php_settings']['83']['memory_limit'], 'unchanged');
        self::assertSame(422, $this->owner->post('/panel/php/74', ['memory_limit' => '128M'])->status(), 'a version no site uses');

        // Without the feature, no PHP settings page.
        $bare = $this->packageFor(null, 'Bare', 1024, ['domains']);
        $this->makeAccount('bare', $bare, null);
        self::assertSame(403, $this->signedIn('user', 'bare', 'bare-owner-password')->get('/panel/php')->status());
    }

    public function test_removing_a_domain_removes_its_features(): void
    {
        $this->owner->post('/panel/domains', ['kind' => 'addon', 'name' => 'other.test', 'php' => '83']);
        $other = $this->domain('other.test');
        $this->owner->post('/panel/domains/' . $other['id'] . '/redirects', ['path' => '/a', 'to' => 'https://x.test/']);
        $this->owner->post('/panel/domains/' . $other['id'] . '/protected', ['path' => 'p', 'login' => 'ann', 'password' => 'ann-password']);

        $this->owner->post('/panel/domains/' . $other['id'] . '/delete');

        self::assertSame(0, $this->db()->table('web_redirects')->count());
        self::assertSame(0, $this->db()->table('web_protected')->count());
    }

    public function test_the_api(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'billing')['token'];
        $base = '/admin/api/v1/accounts/shop/domains/shop.example.com';

        $redirect = $this->api('POST', $base . '/redirects', $token, ['path' => '/old', 'to' => 'https://shop.example.com/new']);
        $bad = $this->api('POST', $base . '/redirects', $token, ['path' => '/x', 'to' => 'ftp://x.test/']);
        $pages = $this->api('PUT', $base . '/error-pages', $token, ['404' => '/404.html']);
        $hotlink = $this->api('PUT', $base . '/hotlink', $token, ['extensions' => ['jpg', 'webp']]);
        $protect = $this->api('POST', $base . '/protected', $token, ['path' => 'private', 'login' => 'ann', 'password' => 'ann-password']);
        $folder = $this->json($protect)['data']['protected'][0]['id'];
        $login = $this->api('PUT', $base . '/protected/' . $folder . '/logins/bob', $token, ['password' => 'bob-password']);
        $php = $this->api('PUT', '/admin/api/v1/accounts/shop/php/83', $token, ['memory_limit' => '512M']);
        $shown = $this->json($this->api('GET', $base, $token))['data'];

        self::assertSame(201, $redirect->status());
        self::assertSame(422, $bad->status());
        self::assertSame(200, $pages->status());
        self::assertSame(['jpg', 'webp'], $this->json($hotlink)['data']['hotlink']['extensions']);
        self::assertSame(201, $protect->status());
        self::assertSame(['ann', 'bob'], $this->json($login)['data']['protected'][0]['logins']);
        self::assertSame(['8.3' => ['memory_limit' => '512M']], $this->json($php)['data']['settings']);
        self::assertSame('/old', $shown['redirects'][0]['path']);
        self::assertSame(['404' => '/404.html'], $shown['error_pages']);
        self::assertArrayNotHasKey('users', $shown['protected'][0], 'the API never shows hashes');

        $redirectId = $shown['redirects'][0]['id'];
        self::assertSame(200, $this->api('DELETE', $base . '/redirects/' . $redirectId, $token)->status());
        self::assertSame(200, $this->api('DELETE', $base . '/hotlink', $token)->status());
        self::assertSame([], $this->server->sites['shop']['sites'][0]['redirects']);
        self::assertNull($this->server->sites['shop']['sites'][0]['hotlink']);
    }

    /** @return array<string, mixed> */
    private function domain(string $name): array
    {
        $row = $this->app->container()->get(Domains::class)->findByName((int) $this->account('shop')['id'], $name);
        self::assertNotNull($row, $name . ' exists');

        return $row;
    }
}
