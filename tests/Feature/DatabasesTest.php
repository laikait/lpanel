<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Databases\Databases;
use App\Modules\Databases\DbUsage;
use App\Modules\Databases\PhpMyAdmin;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class DatabasesTest extends TestCase
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
        $this->package = $this->packageFor(null, 'Starter', 100, ['domains', 'databases']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_the_owner_makes_a_database_and_a_user_and_the_server_gets_them(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/databases', ['name' => 'store']);
        self::assertSame(['shop_store'], $this->server->dbs['shop']['databases']);

        $db = ['id' => $this->dbId('shop_store')];
        $owner->post('/panel/databases/users', ['name' => 'app', 'password' => 'a-long-password-1', 'database' => (string) $db['id']]);
        $user = $this->server->dbs['shop']['users'][0];
        self::assertSame('shop_app', $user['name']);
        self::assertSame(Databases::hash('a-long-password-1'), $user['hash'], 'only MariaDB\'s hash goes to the server');
        self::assertSame('*' . \strtoupper(\sha1(\sha1('a-long-password-1', true))), $user['hash']);
        self::assertSame(['shop_store' => ['ALL']], (array) $user['grants']);
        self::assertStringNotContainsString('a-long-password-1', \json_encode($this->db()->table('db_users')->get(), \JSON_THROW_ON_ERROR), 'the password is not kept');

        $u = $this->userRow('shop_app');
        $owner->post('/panel/databases/users/' . $u['id'] . '/grants', ['database' => (string) $db['id'], 'privileges' => ['SELECT', 'INSERT']]);
        self::assertSame(['shop_store' => ['SELECT', 'INSERT']], (array) $this->server->dbs['shop']['users'][0]['grants']);

        $owner->post('/panel/databases/users/' . $u['id'] . '/grants', ['database' => (string) $db['id'], 'revoke' => '1']);
        self::assertSame([], (array) $this->server->dbs['shop']['users'][0]['grants']);

        $owner->post('/panel/databases/users/' . $u['id'] . '/password', ['password' => 'another-password-2']);
        self::assertSame(Databases::hash('another-password-2'), $this->server->dbs['shop']['users'][0]['hash']);

        $page = $owner->get('/panel/databases')->body();
        self::assertStringContainsString('shop_store', $page);
        self::assertStringContainsString('shop_app', $page);
        self::assertStringContainsString('phpMyAdmin', $page);

        $owner->post('/panel/databases/users/' . $u['id'] . '/delete');
        self::assertSame([], $this->server->dbs['shop']['users']);

        $actions = \array_column($this->auditRows(), 'action');
        foreach (['database.create', 'database.user_create', 'database.grant', 'database.revoke', 'database.user_password', 'database.user_drop'] as $a) {
            self::assertContains($a, $actions);
        }
    }

    public function test_names_limits_and_input_are_checked_before_anything_is_sent(): void
    {
        $this->db()->table('packages')->where('id', $this->package)->update(['databases' => 2]);
        $owner = $this->owner();

        foreach (['Bad-Name' => 'Use lowercase', '_x' => 'Use lowercase', \str_repeat('a', 60) => 'too long'] as $name => $why) {
            $r = $owner->post('/panel/databases', ['name' => $name]);
            self::assertSame(422, $r->status(), $name);
            self::assertStringContainsString($why, $r->body());
        }

        self::assertSame(0, $this->server->ran('db.apply'));
        $owner->post('/panel/databases', ['name' => 'one']);
        self::assertStringContainsString('already a database', $owner->post('/panel/databases', ['name' => 'shop_one'])->body(), 'with or without the prefix');
        $owner->post('/panel/databases', ['name' => 'two']);
        $owner->post('/panel/databases', ['name' => 'three']);
        self::assertNull($this->dbRow('shop_three'), 'the package allows two');

        self::assertSame(422, $owner->post('/panel/databases/users', ['name' => 'app', 'password' => 'short'])->status());
        self::assertSame(422, $owner->post('/panel/databases/hosts', ['host' => "1.2.3.4' OR 1=1"])->status());
        self::assertSame(422, $owner->post('/panel/databases/hosts', ['host' => 'localhost'])->status());

        self::assertSame([['ALL'], null], Databases::checkPrivileges(Databases::PRIVILEGES), 'every privilege is ALL');
        self::assertSame('Databases.error_privilege', Databases::checkPrivileges(['FILE'])[1]);
        self::assertSame('Databases.error_privilege', Databases::checkPrivileges(['GRANT OPTION'])[1]);
        self::assertSame(['203.0.113.0/255.255.255.0', null], Databases::checkHost('203.0.113.77/24'));
        self::assertSame(['2001:db8::5', null], Databases::checkHost('2001:DB8::5'));
        self::assertSame(['%.example.com', null], Databases::checkHost('%.example.com'));

        // Another account's database is not this owner's to touch.
        $this->makeAccount('other', $this->package, null);
        $this->app->container()->get(\App\Modules\Databases\DbActions::class)->createDatabase(null, null, $this->account('other'), 'data');
        self::assertSame(404, $owner->post('/panel/databases/' . $this->dbId('other_data') . '/delete')->status());
    }

    public function test_a_change_the_server_refuses_is_undone(): void
    {
        $this->server->failNext = ['op' => 'db.apply', 'code' => 'db_failed', 'message' => 'ERROR 1007'];
        $owner = $this->owner();
        $owner->post('/panel/databases', ['name' => 'store']);

        self::assertNull($this->dbRow('shop_store'));
        self::assertStringContainsString('refused', $owner->get('/panel/databases')->body());
        self::assertSame('failed', $this->auditRows()[\count($this->auditRows()) - 1]['outcome']);
    }

    public function test_drop_and_rename_wait_for_earlier_changes_and_ask_the_server_first(): void
    {
        $owner = $this->owner();
        $this->server->down = true;
        $owner->post('/panel/databases', ['name' => 'store']);
        $db = ['id' => $this->dbId('shop_store')];

        $owner->post('/panel/databases/' . $db['id'] . '/delete');
        self::assertNotNull($this->dbRow('shop_store'), 'not dropped while the creation is still waiting');

        $this->server->down = false;
        $owner->post('/panel/databases/' . $db['id'] . '/rename', ['name' => 'shop']);
        self::assertSame(['shop_shop'], $this->server->dbs['shop']['databases']);
        self::assertNotNull($this->dbRow('shop_shop'));

        $owner->post('/panel/databases', ['name' => 'blog']);
        $this->server->dbTables['shop_blog'] = ['a_view'];
        $owner->post('/panel/databases/' . $this->dbId('shop_blog') . '/rename', ['name' => 'news']);
        self::assertNotNull($this->dbRow('shop_blog'), 'the server refused: the name stays');

        $owner->post('/panel/databases/' . $this->dbId('shop_shop') . '/delete');
        self::assertNull($this->dbRow('shop_shop'));
        self::assertSame(['shop_blog'], $this->server->dbs['shop']['databases']);
        self::assertSame(1, $this->server->ran('db.drop'));
    }

    public function test_remote_hosts_suspension_and_termination(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/databases/hosts', ['host' => '198.51.100.9/24', 'note' => 'office']);
        self::assertSame(['198.51.100.0/255.255.255.0'], $this->server->dbs['shop']['hosts']);
        self::assertStringContainsString('office', $owner->get('/panel/databases')->body());

        $provisioner = $this->app->container()->get(Provisioner::class);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $id = (int) $this->account('shop')['id'];
        $provisioner->suspend($root, null, $id, 'unpaid');
        self::assertTrue($this->server->dbs['shop']['suspended'], 'suspension locks the users');
        $provisioner->unsuspend($root, null, $id);
        self::assertFalse($this->server->dbs['shop']['suspended']);

        $provisioner->terminate($root, null, $id);
        $provisioner->settleAll();
        self::assertSame([], $this->db()->table('db_hosts')->get());
        self::assertSame([], $this->db()->table('db_accounts')->get());
        self::assertArrayNotHasKey('shop', $this->server->dbs);
    }

    public function test_database_space_counts_against_the_disk_quota(): void
    {
        $owner = $this->owner();
        $owner->post('/panel/databases', ['name' => 'store']);
        $this->server->dbSizes = ['shop_store' => 30 * 1048576, 'other_x' => 5];

        $result = $this->app->container()->get(DbUsage::class)->refresh();
        self::assertSame(['measured' => 1, 'quotas' => 1], $result);
        self::assertSame(30 * 1048576, (int) $this->account('shop')['db_used_bytes']);
        $this->app->container()->get(Provisioner::class)->settleAll();
        self::assertSame(70 * 1024, $this->server->users['shop']['quota']['blocks_kib'], '100 MB less 30 MB of databases');

        self::assertSame(['measured' => 1, 'quotas' => 0], $this->app->container()->get(DbUsage::class)->refresh(), 'unchanged: the quota stays');
        self::assertStringContainsString('30 MB', $owner->get('/panel/databases')->body());

        // A package change keeps the databases' share out.
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $bigger = $this->packageFor(null, 'Bigger', 200, ['domains', 'databases']);
        $this->app->container()->get(Provisioner::class)->changePackage($root, null, (int) $this->account('shop')['id'], $bigger);
        self::assertSame(170 * 1024, $this->server->users['shop']['quota']['blocks_kib']);
    }

    public function test_phpmyadmin_signs_the_owner_in_with_a_one_time_ticket(): void
    {
        $dir = \sys_get_temp_dir() . '/lpanel-pma-' . \bin2hex(\random_bytes(4));
        \mkdir($dir);
        $this->app->container()->get(Config::class)->set('Databases.phpmyadmin_tickets', $dir);

        try {
            $owner = $this->owner();
            $owner->post('/panel/databases', ['name' => 'store']);
            $r = $owner->post('/panel/databases/phpmyadmin');
            self::assertSame(303, $r->status());
            self::assertMatchesRegularExpression('#^/phpmyadmin/lpanel-signon\.php\?ticket=[0-9a-f]{64}$#', (string) $r->header('Location'));

            $token = \substr((string) $r->header('Location'), -64);
            $ticket = \json_decode((string) \file_get_contents($dir . '/' . $token), true);
            self::assertSame('lpsso-shop', $ticket['user']);
            self::assertSame($this->server->sso['shop'], $ticket['password']);
            self::assertGreaterThan(\time(), $ticket['expires']);
            self::assertNotNull(($this->db()->table('db_accounts')->first() ?? self::fail('no state'))['sso_at']);

            // Unused for eight hours: the login is removed, and stale tickets too.
            $this->db()->table('db_accounts')->updateAll(['sso_at' => '2000-01-01 00:00:00']);
            \touch($dir . '/' . $token, \time() - 3600);
            self::assertSame(1, $this->app->container()->get(PhpMyAdmin::class)->expire());
            self::assertArrayNotHasKey('shop', $this->server->sso);
            self::assertFileDoesNotExist($dir . '/' . $token);
        } finally {
            foreach (\glob($dir . '/*') ?: [] as $f) {
                \unlink($f);
            }

            \rmdir($dir);
        }
    }

    public function test_the_api(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];

        self::assertSame(201, $this->api('POST', '/admin/api/v1/accounts/shop/databases', $token, ['name' => 'store'])->status());
        $r = $this->api('POST', '/admin/api/v1/accounts/shop/databases/users', $token, ['name' => 'app', 'password' => 'api-password-123', 'grants' => ['shop_store' => ['SELECT']]]);
        self::assertSame(201, $r->status());
        self::assertSame(['shop_store' => ['SELECT']], $this->json($r)['data']['users'][0]['grants']);
        self::assertStringNotContainsString('api-password-123', $r->body());

        $r = $this->api('PUT', '/admin/api/v1/accounts/shop/databases/users/shop_app/grants/shop_store', $token, ['privileges' => 'ALL']);
        self::assertSame(['shop_store' => ['ALL']], $this->json($r)['data']['users'][0]['grants']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/accounts/shop/databases/users/shop_app/grants/shop_store', $token, ['privileges' => ['SUPER']])->status());
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/accounts/shop/databases/users/shop_app/password', $token, ['password' => 'x'])->status());

        self::assertSame(201, $this->api('POST', '/admin/api/v1/accounts/shop/databases/hosts', $token, ['host' => '203.0.113.5'])->status());
        $data = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/databases', $token))['data'];
        self::assertSame('shop_', $data['prefix']);
        self::assertSame('203.0.113.5', $data['hosts'][0]['host']);
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/accounts/shop/databases/hosts/' . $data['hosts'][0]['id'], $token)->status());

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/accounts/shop/databases/shop_store', $token, ['name' => 'shop_shop'])->status());
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/accounts/shop/databases/users/shop_app', $token)->status());
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/accounts/shop/databases/shop_shop', $token)->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/nobody/databases', $token)->status());

        $this->server->down = true;
        $this->api('POST', '/admin/api/v1/accounts/shop/databases', $token, ['name' => 'late']);
        self::assertSame(409, $this->api('DELETE', '/admin/api/v1/accounts/shop/databases/shop_late', $token)->status(), 'not while a change waits');
        $this->server->down = false;

        $this->server->dbProcesses = [
            ['id' => 7, 'user' => 'shop_app', 'host' => 'localhost', 'db' => 'shop_late', 'command' => 'Query', 'time' => 30, 'state' => 'Sending data', 'info' => 'SELECT 1', 'killable' => true],
            ['id' => 8, 'user' => 'lpanel', 'host' => 'localhost', 'db' => 'lpanel', 'command' => 'Sleep', 'time' => 2, 'state' => '', 'info' => '', 'killable' => false],
        ];
        $server = $this->json($this->api('GET', '/admin/api/v1/databases', $token))['data'];
        self::assertSame('10.5.27-MariaDB', $server['server']['version']);
        self::assertCount(2, $server['processes']);
        self::assertSame(403, $this->api('DELETE', '/admin/api/v1/databases/processes/8', $token)->status());
        self::assertSame(200, $this->api('DELETE', '/admin/api/v1/databases/processes/7', $token)->status());
    }

    public function test_the_admin_page(): void
    {
        $this->app->container()->get(\App\Modules\Databases\DbActions::class)->createDatabase(null, null, $this->account('shop'), 'store');
        $this->server->dbProcesses = [
            ['id' => 7, 'user' => 'shop_app', 'host' => 'localhost', 'db' => 'shop_store', 'command' => 'Query', 'time' => 3700, 'state' => 'Sending data', 'info' => 'SELECT * FROM orders', 'killable' => true],
        ];
        $this->rootPassword($this->app, 'root-password-123');
        $root = $this->signedIn('admin', 'root', 'root-password-123');

        $page = $root->get('/admin/databases')->body();
        self::assertStringContainsString('10.5.27', $page);
        self::assertStringContainsString('SELECT * FROM orders', $page);
        self::assertStringContainsString('1h 1m', $page);
        self::assertStringContainsString('shop_store', $page);

        $root->post('/admin/databases/processes/7/kill');
        self::assertSame([], $this->server->dbProcesses);
        self::assertStringContainsString('shop_store', $root->get('/admin/accounts/' . $this->account('shop')['id'])->body(), 'the account page shows its databases');

        // The owner never reaches the Admin panel's page.
        self::assertNotSame(200, $this->owner()->get('/admin/databases')->status());
    }

    // -------------------------------------------------------------------------

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    /** @return array<string, mixed>|null */
    private function dbRow(string $name): ?array
    {
        return $this->db()->table('db_databases')->where('name', $name)->first();
    }

    private function dbId(string $name): int
    {
        return (int) ($this->dbRow($name) ?? self::fail('no database ' . $name))['id'];
    }

    /** @return array<string, mixed> */
    private function userRow(string $name): array
    {
        return $this->db()->table('db_users')->where('name', $name)->first() ?? self::fail('no user ' . $name);
    }
}
