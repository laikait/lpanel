<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Backups\BackupRuns;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M8a: destinations, the schedule, runs and snapshots, in the Admin panel,
 * the API and the scheduler, over the agent's backup.* operations.
 */
final class BackupsTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $package = $this->packageFor(null, 'Starter', 2048, ['domains', 'file_manager', 'ftp']);
        $this->makeAccount('shop', $package, null);
        $this->makeAccount('blog', $package, null);
    }

    public function test_a_destination_is_kept_only_once_the_server_reaches_it(): void
    {
        $admin = $this->admin();
        $page = $admin->get('/admin/backups');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('No destinations yet', $page->body());

        // Refused in the panel: a system folder, a bad name.
        self::assertSame(422, $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => 'home', 'path' => '/home/backups'])->status());
        self::assertSame(422, $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => '', 'path' => '/backup'])->status());
        self::assertCount(0, $this->server->backupDestinations);

        // The server cannot reach it: nothing is kept.
        $this->server->backupUnreachable = true;
        $failed = $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => 'disk2', 'path' => '/backup/lpanel']);
        self::assertSame(422, $failed->status());
        self::assertStringContainsString('i/o timeout', $failed->body());
        self::assertSame([], $this->db()->table('backup_destinations')->get());
        $this->server->backupUnreachable = false;

        self::assertSame(303, $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => 'disk2', 'path' => '/backup/lpanel/'])->status());
        $row = $this->db()->table('backup_destinations')->where('name', 'disk2')->first();
        self::assertNotNull($row);
        $kept = $this->server->backupDestinations['d' . $row['id']] ?? [];
        self::assertSame(['type' => 'local', 'path' => '/backup/lpanel'], \array_intersect_key($kept, ['type' => 0, 'path' => 0]));
        self::assertStringContainsString('/backup/lpanel', $admin->get('/admin/backups')->body());
        $added = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'backup.destination_add'));
        self::assertSame(['failed', 'ok'], \array_column($added, 'outcome'));
    }

    public function test_secrets_go_to_the_server_and_stay_there(): void
    {
        $token = $this->rootToken();
        $made = $this->api('POST', '/admin/api/v1/backups/destinations', $token, [
            'name' => 'wasabi', 'type' => 's3', 'endpoint' => 's3.eu-central-1.wasabisys.com', 'region' => 'eu-central-1',
            'bucket' => 'acme-backups', 'prefix' => '/server1/', 'access_key' => 'AKIAEXAMPLE', 'secret_key' => 'Very/Secret+Key',
        ]);
        self::assertSame(201, $made->status());
        $id = $this->json($made)['data']['id'];
        self::assertSame('Very/Secret+Key', $this->server->backupDestinations['d' . $id]['secret_key']);
        self::assertSame('server1', $this->server->backupDestinations['d' . $id]['prefix']);

        $everything = \json_encode([$this->db()->table('backup_destinations')->get(), $this->auditRows()], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Very/Secret+Key', $everything);
        self::assertStringNotContainsString('AKIAEXAMPLE', $everything);
        self::assertStringNotContainsString('Very/Secret+Key', $this->api('GET', '/admin/api/v1/backups', $token)->body());

        // Changed without its keys: the server keeps the ones it has.
        self::assertSame(200, $this->api('PUT', '/admin/api/v1/backups/destinations/' . $id, $token, ['prefix' => 'server2'])->status());
        self::assertSame('Very/Secret+Key', $this->server->backupDestinations['d' . $id]['secret_key']);
        self::assertSame('server2', $this->server->backupDestinations['d' . $id]['prefix']);

        // A new one without keys is refused.
        self::assertSame(422, $this->api('POST', '/admin/api/v1/backups/destinations', $token, ['name' => 'b2', 'type' => 'b2', 'bucket' => 'acme-b2-bucket'])->status());
    }

    public function test_an_sftp_destination_shows_the_key_to_authorize(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/backups/destinations', ['type' => 'sftp', 'name' => 'box', 'host' => 'u123.your-storagebox.de', 'port' => '23', 'user' => 'u123', 'path' => '/lpanel']);
        $page = $admin->get('/admin/backups')->body();
        self::assertStringContainsString('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTests lpanel-backup', $page);
        self::assertStringContainsString('u123@u123.your-storagebox.de:23:/lpanel', $page);
    }

    public function test_the_schedule_needs_a_destination_and_starts_once_a_day(): void
    {
        $admin = $this->admin();
        self::assertSame(422, $admin->post('/admin/backups/settings', ['enabled' => '1', 'time' => '02:30', 'destination_id' => '0', 'keep_daily' => '7', 'keep_weekly' => '4', 'keep_monthly' => '3', 'prune_day' => '0'])->status());
        self::assertSame(422, $admin->post('/admin/backups/settings', ['enabled' => '0', 'time' => '25:00', 'destination_id' => '0', 'keep_daily' => '0', 'keep_weekly' => '0', 'keep_monthly' => '0', 'prune_day' => '0'])->status());

        $id = $this->destination();
        self::assertSame(303, $admin->post('/admin/backups/settings', ['enabled' => '1', 'time' => '02:30', 'destination_id' => (string) $id, 'keep_daily' => '7', 'keep_weekly' => '4', 'keep_monthly' => '3', 'prune_day' => '0'])->status());

        $runs = $this->app->container()->get(BackupRuns::class);
        self::assertFalse($runs->due(new \DateTimeImmutable('2026-11-18 01:00')), 'not before its time');
        self::assertTrue($runs->due(new \DateTimeImmutable('2026-11-18 02:31')));
        self::assertCount(1, $this->server->backupRuns);
        $names = \array_column($this->server->backupRuns[0]['accounts'], 'username');
        \sort($names);
        self::assertSame(['blog', 'shop'], $names, 'every account on the server');
        self::assertSame(['daily' => 7, 'weekly' => 4, 'monthly' => 3], $this->server->backupRuns[0]['keep']);
        $runs->settle();
        self::assertFalse($runs->due(new \DateTimeImmutable('2026-11-18 23:00')), 'once a day');
        self::assertCount(1, $this->server->backupRuns);
    }

    public function test_a_run_carries_each_accounts_description_and_finishes(): void
    {
        $this->destination(schedule: true);
        $this->server->backupHold = true;
        $admin = $this->admin();
        self::assertSame(303, $admin->post('/admin/backups/run')->status());
        $run = $this->firstRun();
        self::assertSame('running', $run['state']);

        // The account's description: its row, its owner's login without a second factor's secret.
        $shop = \array_values(\array_filter($this->server->backupRuns[0]['accounts'], static fn(array $a): bool => $a['username'] === 'shop'))[0];
        self::assertSame('shop', $shop['meta']['account']['username']);
        self::assertSame('shop.example.com', $shop['meta']['tables']['domains'][0]['name']);
        self::assertArrayHasKey('password_hash', $shop['meta']['login']);
        self::assertArrayNotHasKey('totp_secret', $shop['meta']['login']);
        self::assertTrue($this->server->backupRuns[0]['prune'], 'a full run by hand frees space');

        // One run at a time.
        self::assertStringContainsString('A backup is running', $admin->get('/admin/backups')->body());
        self::assertSame(303, $admin->post('/admin/backups/run')->status());
        self::assertCount(1, $this->server->backupRuns);

        $this->server->backupHold = false;
        $this->app->container()->get(BackupRuns::class)->settle();
        $run = $this->firstRun();
        self::assertSame('done', $run['state']);
        self::assertSame(2, (int) $run['ok']);
        $finished = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'backup.finished'));
        self::assertSame('ok', $finished[0]['outcome']);
    }

    public function test_a_failed_account_shows_on_its_page_and_the_run_is_partial(): void
    {
        $this->destination(schedule: true);
        $this->server->backupFail = ['blog' => 'restic backup: Fatal: disk full'];
        $admin = $this->admin();
        $admin->post('/admin/backups/run');
        $runs = $this->app->container()->get(BackupRuns::class);
        $runs->settle();
        $run = $this->firstRun();
        self::assertSame('partial', $run['state']);
        self::assertStringContainsString('disk full', $admin->get('/admin/backups')->body());
        self::assertStringContainsString('disk full', $admin->get('/admin/accounts/' . $this->account('blog')['id'])->body());
        self::assertStringContainsString('Last backed up', $admin->get('/admin/accounts/' . $this->account('shop')['id'])->body());

        // One account by hand, from its page.
        $admin->post('/admin/backups/run', ['username' => 'blog']);
        self::assertSame(['blog'], \array_column($this->server->backupRuns[1]['accounts'], 'username'));
        self::assertFalse($this->server->backupRuns[1]['prune'], 'one account does not prune');
    }

    public function test_a_scheduled_destination_cannot_be_removed(): void
    {
        $id = $this->destination(schedule: true);
        $admin = $this->admin();
        $admin->post('/admin/backups/destinations/' . $id . '/delete');
        self::assertNotNull($this->db()->table('backup_destinations')->where('id', $id)->first());

        $other = $this->destination('spare', '/backup/spare');
        $admin->post('/admin/backups/destinations/' . $other . '/delete');
        self::assertNull($this->db()->table('backup_destinations')->where('id', $other)->first());
        self::assertArrayNotHasKey('d' . $other, $this->server->backupDestinations);
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->rootToken();
        $id = $this->destination(schedule: true);
        self::assertSame(409, $this->api('DELETE', '/admin/api/v1/backups/destinations/' . $id, $token)->status());

        $started = $this->api('POST', '/admin/api/v1/backups/runs', $token, ['accounts' => ['shop']]);
        self::assertSame(202, $started->status());
        $run = $this->json($started)['data']['id'];
        self::assertSame(422, $this->api('POST', '/admin/api/v1/backups/runs', $token, ['accounts' => ['nobody']])->status());

        $shown = $this->json($this->api('GET', '/admin/api/v1/backups/runs/' . $run, $token))['data'];
        self::assertSame('done', $shown['state']);
        self::assertSame('shop', $shown['results'][0]['username']);

        $account = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/backups', $token))['data'];
        self::assertTrue($account['last']['ok']);
        self::assertSame('shop', $account['snapshots'][0]['username']);

        self::assertSame(422, $this->api('PUT', '/admin/api/v1/backups/settings', $token, ['keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0])->status());
        self::assertSame(200, $this->api('PUT', '/admin/api/v1/backups/settings', $token, ['time' => '04:15'])->status());
    }

    public function test_only_the_server_side_sees_backups(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        self::assertContains($owner->get('/admin/backups')->status(), [302, 403, 404]);
    }

    // -------------------------------------------------------------------------

    private function destination(string $name = 'disk2', string $path = '/backup/lpanel', bool $schedule = false): int
    {
        $admin = $this->admin();
        $admin->post('/admin/backups/destinations', ['type' => 'local', 'name' => $name, 'path' => $path]);
        $id = (int) ($this->db()->table('backup_destinations')->where('name', $name)->first() ?? throw new \LogicException('not added'))['id'];

        if ($schedule) {
            $admin->post('/admin/backups/settings', ['enabled' => '1', 'time' => '02:30', 'destination_id' => (string) $id, 'keep_daily' => '7', 'keep_weekly' => '4', 'keep_monthly' => '3', 'prune_day' => (string) (((int) \gmdate('w') + 1) % 7)]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private function firstRun(): array
    {
        return $this->db()->table('backup_runs')->orderBy('id')->first() ?? throw new \LogicException('no run');
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
