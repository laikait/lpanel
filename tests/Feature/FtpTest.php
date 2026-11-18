<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M7b: FTP logins on Pure-FTPd, in the User panel, the Admin panel, the API
 * and the CLI, over the agent's ftp.* operations.
 */
final class FtpTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private int $package;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->package = $this->packageFor(null, 'Starter', 2048, ['domains', 'file_manager', 'ftp']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_the_owner_makes_a_login_shut_in_a_folder(): void
    {
        $owner = $this->owner();
        $page = $owner->get('/panel/ftp');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('No FTP logins yet', $page->body());
        self::assertStringContainsString('<option value="shop.example.com"', $page->body());

        $made = $owner->post('/panel/ftp', ['name' => 'Designer', 'domain' => 'shop.example.com', 'password' => 'Designer-Pass-1', 'folder' => '/public_html/new/']);
        self::assertSame(303, $made->status());
        $sent = $this->server->ftp['shop'];
        self::assertFalse($sent['suspended']);
        self::assertCount(1, $sent['users']);
        self::assertSame('designer@shop.example.com', $sent['users'][0]['login'], 'names are lowercased');
        self::assertSame('public_html/new', $sent['users'][0]['folder']);
        self::assertTrue(\password_verify('Designer-Pass-1', $sent['users'][0]['hash']), 'only the bcrypt hash goes to the server');
        self::assertStringNotContainsString('Designer-Pass-1', \json_encode($this->auditRows(), \JSON_THROW_ON_ERROR), 'the password is never audited');

        $listed = $owner->get('/panel/ftp')->body();
        self::assertStringContainsString('designer@shop.example.com', $listed);
        self::assertStringContainsString('/public_html/new', $listed);

        // Refused: a domain that is not the account's, a taken name, a bad folder, a short password.
        $this->makeAccount('blog', $this->package, null);
        foreach ([
            ['name' => 'x', 'domain' => 'blog.example.com', 'password' => 'Long-Enough-1', 'folder' => ''],
            ['name' => 'designer', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => ''],
            ['name' => 'bob', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => 'public_html/../../etc'],
            ['name' => 'bob', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => 'a:b'],
            ['name' => 'bob', 'domain' => 'shop.example.com', 'password' => 'short', 'folder' => ''],
            ['name' => '-bob', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => ''],
        ] as $bad) {
            self::assertSame(422, $owner->post('/panel/ftp', $bad)->status(), \json_encode($bad, \JSON_THROW_ON_ERROR));
        }

        self::assertCount(1, $this->server->ftp['shop']['users']);
    }

    public function test_password_folder_and_removal(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/ftp', ['name' => 'dev', 'domain' => 'shop.example.com', 'password' => 'Dev-Password-1', 'folder' => 'public_html']);
        $id = (int) ($this->db()->table('ftp_users')->where('login', 'dev@shop.example.com')->first()['id'] ?? 0);

        self::assertSame(303, $owner->post('/panel/ftp/' . $id . '/password', ['password' => 'Dev-Password-2'])->status());
        self::assertTrue(\password_verify('Dev-Password-2', $this->server->ftp['shop']['users'][0]['hash']));

        self::assertSame(303, $owner->post('/panel/ftp/' . $id . '/folder', ['folder' => '/'])->status());
        self::assertSame('', $this->server->ftp['shop']['users'][0]['folder'], '"/" is the whole home');

        // Another account's login is not found.
        $this->makeAccount('blog', $this->package, null);
        $blog = $this->signedIn('user', 'blog', 'blog-owner-password');
        self::assertSame(404, $blog->post('/panel/ftp/' . $id . '/delete', [])->status());

        self::assertSame(303, $owner->post('/panel/ftp/' . $id . '/delete', [])->status());
        self::assertSame([], $this->server->ftp['shop']['users']);

        $actions = \array_column(\array_filter($this->auditRows(), static fn(array $r): bool => \str_starts_with((string) $r['action'], 'ftp.')), 'action');
        self::assertSame(['ftp.create', 'ftp.password', 'ftp.folder', 'ftp.delete'], $actions);
    }

    public function test_the_package_limits_logins(): void
    {
        $this->db()->table('packages')->where('id', $this->package)->update(['ftp_accounts' => 2]);
        $owner = $this->owner();

        foreach (['a', 'b'] as $name) {
            self::assertSame(303, $owner->post('/panel/ftp', ['name' => $name, 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => ''])->status());
        }

        self::assertSame(422, $owner->post('/panel/ftp', ['name' => 'c', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => ''])->status());
        self::assertStringContainsString('as many FTP logins as its package allows', $owner->get('/panel/ftp')->body());
        self::assertCount(2, $this->server->ftp['shop']['users']);
    }

    public function test_a_refused_change_is_undone(): void
    {
        $this->server->failNext = ['op' => 'ftp.apply', 'code' => 'ftp_taken', 'message' => 'x@shop.example.com is another account\'s login'];
        $owner = $this->owner();
        $owner->post('/panel/ftp', ['name' => 'x', 'domain' => 'shop.example.com', 'password' => 'Long-Enough-1', 'folder' => '']);
        self::assertNull($this->db()->table('ftp_users')->where('login', 'x@shop.example.com')->first(), 'the login is not kept');
        self::assertStringContainsString('refused', $owner->get('/panel/ftp')->body());
        $failed = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'ftp.create'));
        self::assertSame('failed', $failed[0]['outcome']);
    }

    public function test_suspension_shuts_logins_out_and_terminate_forgets_them(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/ftp', ['name' => 'dev', 'domain' => 'shop.example.com', 'password' => 'Dev-Password-1', 'folder' => '']);
        $this->server->ftpSessions = [['pid' => 4242, 'login' => 'dev@shop.example.com', 'account' => 'shop', 'seconds' => 10, 'state' => 'IDLE', 'file' => '', 'peer' => '203.0.113.9', 'current' => 0, 'total' => 0, 'percent' => 0, 'bandwidth' => 0]];

        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $provisioner = $this->app->container()->get(Provisioner::class);
        $id = (int) $this->account('shop')['id'];
        $provisioner->suspend($root, null, $id, 'unpaid');
        self::assertTrue($this->server->ftp['shop']['suspended']);
        self::assertSame([], $this->server->ftpSessions, 'its sessions end');
        self::assertCount(1, $this->server->ftp['shop']['users'], 'the logins are kept for later');

        $provisioner->unsuspend($root, null, $id);
        self::assertFalse($this->server->ftp['shop']['suspended']);

        $provisioner->terminate($root, null, $id);
        $provisioner->settleAll();
        self::assertArrayNotHasKey('shop', $this->server->ftp);
        self::assertNull($this->db()->table('ftp_users')->where('login', 'dev@shop.example.com')->first());
    }

    public function test_sessions_are_listed_and_ended_per_account(): void
    {
        $this->makeAccount('blog', $this->package, null);
        $session = static fn(int $pid, string $login, string $account): array => ['pid' => $pid, 'login' => $login, 'account' => $account, 'seconds' => 75, 'state' => 'DL', 'file' => '/public_html/big.zip', 'peer' => '203.0.113.9', 'current' => 10, 'total' => 100, 'percent' => 10, 'bandwidth' => 500];
        $this->server->ftpSessions = [$session(101, 'dev@shop.example.com', 'shop'), $session(102, 'cat@blog.example.com', 'blog')];

        $owner = $this->owner();
        $page = $owner->get('/panel/ftp')->body();
        self::assertStringContainsString('dev@shop.example.com', $page);
        self::assertStringNotContainsString('cat@blog.example.com', $page, 'an owner sees only their own sessions');
        self::assertStringContainsString('Downloading', $page);

        $owner->post('/panel/ftp/sessions/102/kill', []);
        self::assertSame([], $this->server->ftpKilled, 'another account\'s session is not ended');
        $owner->post('/panel/ftp/sessions/101/kill', []);
        self::assertSame([101], $this->server->ftpKilled);

        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $all = $admin->get('/admin/ftp');
        self::assertSame(200, $all->status());
        self::assertStringContainsString('cat@blog.example.com', $all->body());
        self::assertSame(303, $admin->post('/admin/ftp/sessions/102/kill', [])->status());
        self::assertSame([101, 102], $this->server->ftpKilled);
        self::assertContains('ftp.kill', \array_column($this->auditRows(), 'action'));
    }

    public function test_the_admin_changes_the_servers_settings(): void
    {
        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        self::assertStringContainsString('For logins', $admin->get('/admin/ftp')->body());

        $bad = $admin->post('/admin/ftp/settings', ['tls' => 'strict', 'passive_from' => '30100', 'passive_to' => '30000', 'passive_ip' => 'nope', 'max_clients' => '10', 'max_per_ip' => '20']);
        self::assertSame(422, $bad->status());
        self::assertNull($this->server->ftpSettings, 'nothing reached the server');

        $ok = $admin->post('/admin/ftp/settings', ['tls' => 'strict', 'passive_from' => '40000', 'passive_to' => '40050', 'passive_ip' => '203.0.113.5', 'max_clients' => '50', 'max_per_ip' => '5']);
        self::assertSame(303, $ok->status());
        self::assertSame(['tls' => 'strict', 'passive_from' => 40000, 'passive_to' => 40050, 'passive_ip' => '203.0.113.5', 'max_clients' => 50, 'max_per_ip' => 5], $this->server->ftpSettings);
        self::assertStringContainsString('value="40050"', $admin->get('/admin/ftp')->body(), 'kept once the server took them');
        self::assertContains('ftp.settings', \array_column($this->auditRows(), 'action'));

        // An owner's page shows how to connect with the new mode.
        self::assertStringContainsString('required for everything', $this->owner()->get('/panel/ftp')->body());

        // Owners and resellers cannot reach it.
        self::assertContains($this->owner()->get('/admin/ftp')->status(), [302, 403, 404]);
    }

    public function test_without_the_feature_there_is_no_ftp(): void
    {
        $this->makeAccount('plain', $this->packageFor(null, 'Bare', 1024, ['domains']), null);
        $plain = $this->signedIn('user', 'plain', 'plain-owner-password');
        self::assertContains($plain->get('/panel/ftp')->status(), [403, 404]);
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->rootToken();
        $base = '/admin/api/v1/accounts/shop/ftp';
        $made = $this->api('POST', $base, $token, ['login' => 'ci@shop.example.com', 'password' => 'Deploy-Pass-123', 'folder' => 'public_html/app']);
        self::assertSame(201, $made->status());
        self::assertSame([['login' => 'ci@shop.example.com', 'folder' => '/public_html/app']], \array_map(
            static fn(array $l): array => ['login' => $l['login'], 'folder' => $l['folder']],
            $this->json($made)['data']['logins'],
        ));
        self::assertStringNotContainsString('hash', $made->body(), 'no hash is returned');
        self::assertSame(422, $this->api('POST', $base, $token, ['login' => 'ci@shop.example.com', 'password' => 'Deploy-Pass-123'])->status());

        self::assertSame(200, $this->api('PUT', $base . '/ci@shop.example.com', $token, ['folder' => 'public_html', 'password' => 'Deploy-Pass-456'])->status());
        self::assertSame('public_html', $this->server->ftp['shop']['users'][0]['folder']);
        self::assertTrue(\password_verify('Deploy-Pass-456', $this->server->ftp['shop']['users'][0]['hash']));
        self::assertSame(404, $this->api('PUT', $base . '/nobody@shop.example.com', $token, ['folder' => ''])->status());

        $server = $this->json($this->api('GET', '/admin/api/v1/ftp', $token))['data'];
        self::assertSame('required', $server['settings']['tls']);
        self::assertSame('shop', $server['logins'][0]['account']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/ftp/settings', $token, ['tls' => 'never'])->status());
        self::assertSame(200, $this->api('PUT', '/admin/api/v1/ftp/settings', $token, ['tls' => 'optional'])->status());
        self::assertSame('optional', $this->server->ftpSettings['tls'] ?? null);
        self::assertSame(404, $this->api('DELETE', '/admin/api/v1/ftp/sessions/999', $token)->status());

        self::assertSame(204, $this->api('DELETE', $base . '/ci@shop.example.com', $token)->status());
        self::assertSame([], $this->server->ftp['shop']['users']);
    }

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    private function rootToken(): string
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;

        return $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
    }
}
