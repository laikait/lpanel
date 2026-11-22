<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Backups\BackupRestores;
use App\Modules\Backups\BackupRuns;
use App\Modules\Backups\Commands\BackupCommands;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M8b: restoring files, mailboxes, databases and whole accounts from
 * snapshots, by owners in the User panel and administrators in the Admin
 * panel, the API and the command line; downloads; an account made again
 * from its snapshot; another server's repository.
 */
final class RestoreTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private int $destination = 0;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $package = $this->packageFor(null, 'Starter', 2048, ['domains', 'file_manager', 'email', 'databases', 'backups']);
        $plain = $this->packageFor(null, 'Plain', 1024, ['domains']);
        $shop = $this->makeAccount('shop', $package, null);
        $this->makeAccount('blog', $package, null);
        $this->makeAccount('plain', $plain, null);

        // A database and a mailbox, as the panel keeps them.
        $now = \gmdate('Y-m-d H:i:s');
        $this->db()->table('db_databases')->insert(['account_id' => (int) $shop['id'], 'name' => 'shop_wp', 'size_bytes' => 0, 'created_at' => $now]);
        $mailDomain = (int) ($this->db()->table('mail_domains')->where('name', 'shop.example.com')->first() ?? self::fail('no mail domain'))['id'];
        $this->db()->table('mail_boxes')->insert(['account_id' => (int) $shop['id'], 'mail_domain_id' => $mailDomain, 'local' => 'info', 'hash' => '{BLF-CRYPT}$2y$10$abc', 'quota_mb' => 0, 'used_bytes' => 0, 'created_at' => $now]);

        $admin = $this->admin();
        $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => 'disk2', 'path' => '/backup/lpanel']);
        $this->destination = (int) ($this->db()->table('backup_destinations')->where('name', 'disk2')->first() ?? self::fail('no destination'))['id'];
        $admin->post('/admin/backups/settings', ['enabled' => '1', 'time' => '02:30', 'destination_id' => (string) $this->destination, 'keep_daily' => '7', 'keep_weekly' => '4', 'keep_monthly' => '3', 'prune_day' => (string) (((int) \gmdate('w') + 1) % 7)]);
        $admin->post('/admin/backups/run');
        $this->app->container()->get(BackupRuns::class)->settle();
    }

    public function test_an_owner_puts_files_back_from_their_own_snapshot(): void
    {
        $snap = FakeServer::snapshotId(0, 'shop');
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $page = $owner->get('/panel/backups');
        self::assertSame(200, $page->status());
        self::assertStringContainsString(\substr($snap, 0, 8), $page->body());
        self::assertStringContainsString('href="/panel/backups"', $owner->get('/panel')->body());
        self::assertStringNotContainsString(\substr(FakeServer::snapshotId(0, 'blog'), 0, 8), $page->body(), 'only its own snapshots');

        $open = $owner->get('/panel/backups?snapshot=' . \substr($snap, 0, 8))->body();
        self::assertStringContainsString('public_html', $open);
        self::assertStringContainsString('shop_wp', $open);
        self::assertStringContainsString('info@shop.example.com', $open);

        $done = $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['public_html'], 'mode' => 'place']);
        self::assertSame(303, $done->status());
        self::assertCount(1, $this->server->backupRestores);
        self::assertSame(['op' => 'backup.restore', 'destination' => 'd' . $this->destination, 'snapshot' => $snap, 'username' => 'shop', 'paths' => ['public_html'], 'databases' => [], 'folder' => false], $this->server->backupRestores[0]);
        $this->restores()->settle();
        $row = $this->db()->table('backup_restores')->first() ?? self::fail('no restore');
        self::assertSame(['done', 1], [$row['state'], (int) $row['by_owner']]);
        self::assertStringContainsString('3 files', $owner->get('/panel/backups')->body());

        // Into a new folder.
        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['public_html/index.html'], 'mode' => 'folder']);
        self::assertTrue($this->server->backupRestores[1]['folder']);

        // One at a time per account.
        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['public_html'], 'mode' => 'place']);
        self::assertCount(2, $this->server->backupRestores);
        $this->restores()->settle();

        // Never a way out of the home; never another account's snapshot.
        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['../../etc'], 'mode' => 'place']);
        self::assertCount(2, $this->server->backupRestores);
        $owner->post('/panel/backups/restore', ['snapshot' => FakeServer::snapshotId(0, 'blog'), 'kind' => 'files', 'paths' => ['public_html'], 'mode' => 'place']);
        self::assertCount(2, $this->server->backupRestores, 'the server refuses another account\'s snapshot');
        $this->restores()->settle();
        self::assertSame('failed', ($this->db()->table('backup_restores')->orderBy('id', 'desc')->first() ?? [])['state'] ?? null);

        // Without backups in its package: no page.
        $plain = $this->signedIn('user', 'plain', 'plain-owner-password');
        self::assertSame(403, $plain->get('/panel/backups')->status());
        self::assertStringNotContainsString('href="/panel/backups"', $plain->get('/panel')->body());

        $audit = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => \str_starts_with((string) $r['action'], 'backup.restore')));
        self::assertSame(['backup.restore', 'backup.restored'], \array_slice(\array_column($audit, 'action'), 0, 2));
    }

    public function test_a_database_a_mailbox_and_downloads(): void
    {
        $snap = FakeServer::snapshotId(0, 'shop');
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');

        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'database', 'databases' => ['shop_wp']]);
        $this->restores()->settle();
        self::assertSame(['shop_wp'], $this->server->backupRestores[0]['databases']);

        // A database the panel does not have for the account, or another's: refused here.
        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'database', 'databases' => ['blog_wp']]);
        self::assertCount(1, $this->server->backupRestores);

        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'mailbox', 'paths' => ['mail/shop.example.com/info']]);
        $this->restores()->settle();
        self::assertSame(['mail/shop.example.com/info'], $this->server->backupRestores[1]['paths']);
        $owner->post('/panel/backups/restore', ['snapshot' => $snap, 'kind' => 'mailbox', 'paths' => ['public_html']]);
        self::assertCount(2, $this->server->backupRestores, 'a mailbox is one of the account\'s');

        $owner->post('/panel/backups/download', ['snapshot' => $snap, 'database' => 'shop_wp']);
        $this->restores()->settle();
        self::assertSame(['op' => 'backup.download', 'destination' => 'd' . $this->destination, 'snapshot' => $snap, 'username' => 'shop', 'path' => '', 'database' => 'shop_wp'], $this->server->backupRestores[2]);
        self::assertStringContainsString('lpanel-backups/shop_wp-20261118-023000.sql.gz', $owner->get('/panel/backups')->body());

        // Everything back, by the administrator.
        $admin = $this->admin();
        self::assertSame(200, $admin->get('/admin/backups/accounts/shop')->status());
        $admin->post('/admin/backups/accounts/shop/restore', ['destination' => (string) $this->destination, 'snapshot' => $snap, 'kind' => 'account']);
        self::assertSame(['paths' => [''], 'databases' => ['shop_wp'], 'folder' => false], \array_intersect_key($this->server->backupRestores[3], ['paths' => 0, 'databases' => 0, 'folder' => 0]));
    }

    public function test_a_terminated_account_is_made_again_from_its_snapshot(): void
    {
        $snap = FakeServer::snapshotId(0, 'shop');
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $provisioner = $this->app->container()->get(Provisioner::class);
        $provisioner->terminate($root, null, (int) $this->account('shop')['id']);
        $provisioner->settleAll();
        self::assertNull($this->db()->table('accounts')->where('username', 'shop')->first());
        self::assertSame([], $this->db()->table('db_databases')->get());

        $admin = $this->admin();
        $overview = $admin->get('/admin/backups/restore')->body();
        self::assertStringContainsString('Not on this server', $overview);
        self::assertStringContainsString('Make again', $overview);
        $plan = $admin->get('/admin/backups/restore/plan?destination=' . $this->destination . '&snapshot=' . $snap)->body();
        self::assertStringContainsString('Make shop again', $plan);
        self::assertStringContainsString('shop.example.com', $plan);

        self::assertSame(303, $admin->post('/admin/backups/restore/account', ['destination' => (string) $this->destination, 'snapshot' => $snap, 'package_id' => '0'])->status());
        $shop = $this->account('shop');
        self::assertSame('active', $shop['status']);
        self::assertSame('shop_wp', ($this->db()->table('db_databases')->where('account_id', (int) $shop['id'])->first() ?? [])['name'] ?? null);
        self::assertSame('info', ($this->db()->table('mail_boxes')->where('account_id', (int) $shop['id'])->first() ?? [])['local'] ?? null);
        $mailDomain = $this->db()->table('mail_domains')->where('account_id', (int) $shop['id'])->first() ?? self::fail('no mail domain');
        $box = $this->db()->table('mail_boxes')->where('account_id', (int) $shop['id'])->first() ?? self::fail('no mailbox');
        self::assertSame((int) $mailDomain['id'], (int) $box['mail_domain_id'], 'references point at the new rows');

        // Its files and databases go back once it is there.
        $last = $this->server->backupRestores[\count($this->server->backupRestores) - 1];
        self::assertSame(['shop', [''], ['shop_wp']], [$last['username'], $last['paths'], $last['databases']]);
        $this->restores()->settle();
        self::assertSame('done', ($this->db()->table('backup_restores')->orderBy('id', 'desc')->first() ?? [])['state'] ?? null);

        // The owner signs in with the password they had.
        self::assertSame(200, $this->signedIn('user', 'shop', 'shop-owner-password')->get('/panel')->status());
        $actions = \array_column($this->auditRows(), 'action');
        self::assertContains('backup.account_restore', $actions);

        // An account that is here cannot be made again.
        $token = $this->rootToken();
        $conflict = $this->api('GET', '/admin/api/v1/backups/snapshots/' . FakeServer::snapshotId(0, 'blog') . '/plan', $token);
        self::assertSame(409, $conflict->status());
        self::assertStringContainsString('The username blog is in use here.', $conflict->body());
    }

    public function test_the_api_restores_and_another_servers_repository_needs_its_password(): void
    {
        $token = $this->rootToken();
        $snap = FakeServer::snapshotId(0, 'shop');
        $files = $this->api('GET', '/admin/api/v1/accounts/shop/backups/files?snapshot=' . $snap . '&path=public_html', $token);
        self::assertSame(200, $files->status());
        self::assertSame('index.html', $this->json($files)['data']['entries'][0]['name']);

        $made = $this->api('POST', '/admin/api/v1/accounts/shop/backups/restores', $token, ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['public_html'], 'folder' => true]);
        self::assertSame(202, $made->status());
        $id = $this->json($made)['data']['id'];
        $shown = $this->json($this->api('GET', '/admin/api/v1/backups/restores/' . $id, $token))['data'];
        self::assertSame(['done', 'restored-20261119-101500'], [$shown['state'], $shown['result']['folder']]);
        self::assertSame(422, $this->api('POST', '/admin/api/v1/accounts/shop/backups/restores', $token, ['snapshot' => $snap, 'kind' => 'files', 'paths' => ['a/../../b']])->status());

        $download = $this->api('POST', '/admin/api/v1/accounts/shop/backups/downloads', $token, ['snapshot' => $snap]);
        self::assertSame(202, $download->status());

        // Another server's repository: its password goes to the server alone.
        $added = $this->api('POST', '/admin/api/v1/backups/destinations', $token, ['name' => 'old', 'type' => 'local', 'path' => '/mnt/old', 'password' => 'f00dfeedf00dfeed']);
        self::assertSame(201, $added->status());
        self::assertSame('f00dfeedf00dfeed', $this->server->backupDestinations['d' . $this->json($added)['data']['id']]['password']);
        $everything = \json_encode([$this->db()->table('backup_destinations')->get(), $this->auditRows()], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('f00dfeedf00dfeed', $everything);
    }

    public function test_the_command_line_restores_too(): void
    {
        $commands = $this->app->container()->get(BackupCommands::class);
        $short = \substr(FakeServer::snapshotId(0, 'shop'), 0, 8);
        $run = function (string $method, mixed ...$args) use ($commands): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $commands->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('accounts');
        self::assertSame(0, $code, $text);
        self::assertMatchesRegularExpression('/^shop +[0-9a-f]{8} .* yes?$/m', $text);

        [$code, $text] = $run('files', 'shop', $short);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('public_html/', $text);
        self::assertStringContainsString('shop_wp', $text);

        [$code, $text] = $run('restore', 'shop', $short, 'public_html', null, null, false, true, null, true);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('done', $text);

        [$code, $text] = $run('restore', 'shop', $short, null, 'info@shop.example.com', null, false, false, null, true);
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('restore', 'shop', $short, null, null, null, true, false, null, true);
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('download', 'shop', $short, 'public_html', null, null, true);
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('restores');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('download', $text);
        [$code, $text] = $run('settle');
        self::assertSame(0, $code, $text);

        // An account that is here cannot be made again; a dry run says why.
        [$code, $text] = $run('recreate', FakeServer::snapshotId(0, 'blog'), null, null, true);
        self::assertSame(1, $code);
        self::assertStringContainsString('in use', $text);

        // Another server's repository, by its password.
        [$code, $text] = $run('add', 'old', 'local', '/mnt/old', null, null, null, null, null, null, null, null, true);
        self::assertSame(1, $code, 'the password comes on standard input');
    }

    // -------------------------------------------------------------------------

    private function restores(): BackupRestores
    {
        return $this->app->container()->get(BackupRestores::class);
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    private function rootToken(): string
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;

        return $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
    }
}
