<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\FakeTransport;
use App\Engine\Http\Client\HttpClientException;
use App\Engine\Mail\Transport;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Alerts\AlertChannels;
use App\Modules\Operations\Alerts\AlertChecks;
use App\Modules\Operations\Alerts\Alerts;
use App\Modules\Operations\Alerts\AlertSettings;
use App\Modules\Operations\Alerts\Delivery;
use App\Modules\Operations\Commands\AlertCommands;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M10d: alerts. Where they go (email, Telegram, Slack, a webhook), what makes
 * the server speak up, and that a channel that does not work breaks nothing.
 */
final class AlertsTest extends TestCase
{
    use HostingFixtures;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    private const HOOK = 'https://hooks.example.com/services/T000/B000/SECRETSECRETSECRET';

    private Application $app;

    private FakeServer $server;

    private FakeTransport $http;

    protected function setUp(): void
    {
        $this->app = $this->app(['mail' => ['transport' => 'array', 'from' => ['address' => 'lpanel@server.example.com', 'name' => 'LPanel']]]);
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->http = new FakeTransport();
        $this->app->container()->get(Delivery::class)->useClient(new Client(
            transport: $this->http,
            resolve: static fn(string $host): array => ['93.184.216.34'],
        ));
    }

    public function test_channels_are_checked_and_their_secrets_sealed(): void
    {
        $channels = $this->channels();

        foreach ([
            ['kind' => 'email', 'name' => 'Me', 'to' => 'not an address'],
            ['kind' => 'email', 'name' => 'Me', 'to' => 'a@example.com, b@example.com'],
            ['kind' => 'telegram', 'name' => 'T', 'token' => 'nope', 'chat' => '1'],
            ['kind' => 'slack', 'name' => 'S', 'url' => 'http://hooks.example.com/x'],
            ['kind' => 'slack', 'name' => 'S', 'url' => 'https://user:pw@hooks.example.com/x'],
            ['kind' => 'webhook', 'name' => 'W', 'url' => 'https://localhost/x'],
            ['kind' => 'webhook', 'name' => 'W', 'url' => 'https://hooks.example.com/x', 'signing_key' => 'short'],
            ['kind' => 'sms', 'name' => 'X'],
            ['kind' => 'email', 'name' => '', 'to' => 'a@example.com'],
            ['kind' => 'email', 'name' => 'Me', 'to' => 'a@example.com', 'categories' => ['nonsense']],
            ['kind' => 'email', 'name' => 'Me', 'to' => 'a@example.com', 'min_severity' => 'dire'],
        ] as $bad) {
            self::assertNotSame([], $channels->validate($bad)[1], (string) \json_encode($bad));
        }

        [$c, $errors] = $channels->validate(['kind' => 'telegram', 'name' => 'Phone', 'token' => self::TOKEN, 'chat' => '-1001234567890']);
        self::assertSame([], $errors);
        $id = $channels->create($c, null, null);

        $raw = $this->db()->table('ops_alert_channels')->where('id', $id)->first();
        self::assertNotNull($raw);
        self::assertStringNotContainsString('AAHdq', (string) $raw['secret'], 'the token is sealed');
        self::assertSame('-1001234567890', $raw['target'], 'what is shown is the chat, not the token');
        self::assertSame(self::TOKEN, $channels->open($id)['config']['token'] ?? null);
        self::assertArrayNotHasKey('config', $channels->all()[0], 'listing never opens the secret');
    }

    public function test_an_alert_goes_to_the_channels_that_want_it(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $this->addChannel(['kind' => 'telegram', 'name' => 'Phone', 'token' => self::TOKEN, 'chat' => '-100123', 'categories' => ['services'], 'min_severity' => 'critical']);
        $this->addChannel(['kind' => 'slack', 'name' => 'Team', 'url' => self::HOOK, 'categories' => ['backups']]);
        $this->http->push(200, ['ok' => true]);

        $id = $this->alerts()->raise('services', 'services.httpd.failed', 'critical', 'httpd is down and could not be restarted', 'Job failed');

        $mail = $this->mailer()->sent();
        self::assertCount(1, $mail);
        self::assertSame(['ops@example.com'], $mail[0]->recipients);
        self::assertStringContainsString('CRITICAL: httpd is down', $mail[0]->subject);
        self::assertCount(1, $this->http->sent(), 'Telegram only: Slack wants backups');
        $req = $this->http->sent()[0];
        self::assertStringStartsWith('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', $req->url);
        self::assertStringContainsString('httpd+is+down', (string) $req->body);

        $row = $this->alert($id);
        self::assertSame([2, 0], [(int) $row['sent'], (int) $row['failed']]);

        // A warning is below Telegram's "critical", and not a backup for Slack: only the email.
        $this->alerts()->raise('services', 'services.crond.restarted', 'warning', 'crond restarted');
        self::assertCount(2, $this->mailer()->sent());
        self::assertCount(1, $this->http->sent());
    }

    public function test_slack_and_a_signed_webhook(): void
    {
        $this->addChannel(['kind' => 'slack', 'name' => 'Team', 'url' => self::HOOK]);
        $key = 'a-long-enough-signing-key';
        $this->addChannel(['kind' => 'webhook', 'name' => 'Pager', 'url' => 'https://pager.example.com/hook?x=1', 'signing_key' => $key]);
        $this->http->push(200, 'ok')->push(202, '');

        $this->alerts()->raise('backups', 'backups.run.7', 'critical', 'A backup to S3 failed', '0 accounts backed up, 3 failed.');

        [$slack, $hook] = $this->http->sent();
        self::assertSame(self::HOOK, $slack->url);
        self::assertStringContainsString('A backup to S3 failed', (string) $slack->body);

        $body = (string) $hook->body;
        $sent = \json_decode($body, true);
        self::assertSame(['lpanel', 'backups', 'critical', 'A backup to S3 failed'], [$sent['source'], $sent['category'], $sent['severity'], $sent['title']]);
        self::assertSame('sha256=' . \hash_hmac('sha256', $body, $key), $hook->header('X-LPanel-Signature'), 'the receiver can check who sent it');
    }

    public function test_a_channel_that_fails_is_recorded_and_the_others_still_get_it(): void
    {
        $this->addChannel(['kind' => 'telegram', 'name' => 'Phone', 'token' => self::TOKEN, 'chat' => '-100123']);
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $this->http->pushAnswer(HttpClientException::noResponseQueued('POST', 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage'));

        $id = (int) $this->alerts()->raise('updates', 'updates.failed.1', 'critical', 'The update failed');

        self::assertCount(1, $this->mailer()->sent(), 'the email still went');
        $row = $this->alert($id);
        self::assertSame([1, 1], [(int) $row['sent'], (int) $row['failed']]);

        $deliveries = $this->alerts()->deliveries($id);
        self::assertSame(['Phone', 'Ops'], \array_column($deliveries, 'channel'));
        self::assertFalse($deliveries[0]['ok']);
        self::assertStringNotContainsString(self::TOKEN, $deliveries[0]['error'], 'the token is not copied into the history');

        $phone = $this->channels()->all()[1] ?? $this->channels()->all()[0];
        $failed = \array_values(\array_filter($this->channels()->all(), static fn(array $c): bool => $c['name'] === 'Phone'))[0];
        self::assertNotNull($failed['last_error']);
        self::assertNotNull($phone);
    }

    public function test_a_webhook_cannot_be_pointed_at_this_server(): void
    {
        $this->app->container()->get(Delivery::class)->useClient(new Client(transport: $this->http, resolve: static fn(string $host): array => ['169.254.169.254']));
        $id = $this->addChannel(['kind' => 'webhook', 'name' => 'Sneaky', 'url' => 'https://metadata.example.com/latest']);

        [$ok, $why] = $this->alerts()->test($id, null, null);

        self::assertFalse($ok);
        self::assertSame([], $this->http->sent(), 'nothing left the machine');
        self::assertNotSame('', $why);
    }

    public function test_the_same_alert_is_not_sent_twice_in_the_quiet_period(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);

        $this->alerts()->raise('services', 'services.exim.failed', 'critical', 'exim is down');
        $second = $this->alerts()->raise('services', 'services.exim.failed', 'critical', 'exim is down');
        $this->alerts()->raise('services', 'services.named.failed', 'critical', 'named is down');

        self::assertCount(2, $this->mailer()->sent(), 'exim once, named once');
        self::assertTrue((bool) $this->alert($second)['suppressed']);

        // After the quiet period it is told again.
        $this->db()->table('ops_alerts')->where('alert_key', 'services.exim.failed')->update(['at' => \gmdate('Y-m-d H:i:s', \time() - 7200)]);
        $this->alerts()->raise('services', 'services.exim.failed', 'critical', 'exim is down');
        self::assertCount(3, $this->mailer()->sent());
    }

    public function test_a_condition_is_told_when_it_begins_after_a_day_and_when_it_ends(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $a = $this->alerts();

        self::assertNull($a->condition('resources', 'resources.memory', false, 'warning', 'Memory is nearly all in use'), 'nothing to end');
        self::assertSame('raised', $a->condition('resources', 'resources.memory', true, 'warning', 'Memory is nearly all in use', '95% in use'));
        self::assertNull($a->condition('resources', 'resources.memory', true, 'warning', 'Memory is nearly all in use'), 'told once');
        self::assertCount(1, $this->mailer()->sent());

        $this->db()->table('ops_alert_active')->where('alert_key', 'resources.memory')->update(['told_at' => \gmdate('Y-m-d H:i:s', \time() - 90000)]);
        self::assertSame('reminded', $a->condition('resources', 'resources.memory', true, 'warning', 'Memory is nearly all in use'));
        self::assertCount(2, $this->mailer()->sent());

        self::assertSame('resolved', $a->condition('resources', 'resources.memory', false, 'warning', 'Memory is nearly all in use'));
        self::assertStringContainsString('Resolved: Memory', $this->mailer()->sent()[2]->subject);
        self::assertNull($a->condition('resources', 'resources.memory', false, 'warning', 'Memory is nearly all in use'), 'once');
    }

    public function test_what_the_rest_of_the_panel_announces_becomes_an_alert(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com', 'min_severity' => 'info']);
        $hooks = $this->app->container()->get(HookEngine::class);

        $hooks->do('ops.service_event', 'httpd', 'restarted', '');
        $hooks->do('ops.service_event', 'exim', 'gave_up', 'did not stay up after 3 restarts');
        $hooks->do('ops.update_event', 'available', '2 security updates are waiting');
        $hooks->do('ops.update_event', 'failed', 'the update (security) failed: dnf died');
        $hooks->do('backups.finished', 4, 'partial', 2, 1, 'Offsite', null);
        $hooks->do('backups.finished', 5, 'done', 3, 0, 'Offsite', null);

        $titles = \array_column($this->alerts()->history(20), 'title');
        self::assertEqualsCanonicalizing([
            'A backup to Offsite did not include every account',
            'The update (security) failed: dnf died',
            '2 security updates are waiting',
            'exim keeps falling over: the watchdog has stopped restarting it',
            'httpd was down and has been restarted',
        ], $titles, 'a successful backup says nothing');

        $bySeverity = [];

        foreach ($this->alerts()->history(20) as $h) {
            $bySeverity[$h['title']] = $h['severity'];
        }

        self::assertSame('critical', $bySeverity['The update (security) failed: dnf died']);
        self::assertSame('warning', $bySeverity['2 security updates are waiting']);
    }

    public function test_the_server_looks_at_itself(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $checks = $this->app->container()->get(AlertChecks::class);

        $this->server->facts = $this->facts(diskUsed: 60, memUsed: 50, load: 1.0);
        self::assertSame(['raised' => 0, 'resolved' => 0], $checks->run(), 'a healthy server says nothing');

        $this->server->facts = $this->facts(diskUsed: 96, memUsed: 93, load: 9.0);
        self::assertSame(['raised' => 3, 'resolved' => 0], $checks->run());
        $titles = \array_column($this->alerts()->history(10), 'title');
        self::assertContains('Disk /var is nearly full', $titles);
        self::assertContains('Memory is nearly all in use', $titles);
        self::assertContains('The server load is high', $titles);
        self::assertSame(['raised' => 0, 'resolved' => 0], $checks->run(), 'not again while it lasts');

        $this->server->facts = $this->facts(diskUsed: 40, memUsed: 40, load: 0.5);
        self::assertSame(['raised' => 0, 'resolved' => 3], $checks->run());

        // The agent not answering is an alert of its own; nothing else is concluded meanwhile.
        $this->server->facts = null;
        self::assertSame(['raised' => 1, 'resolved' => 0], $checks->run());
        self::assertContains('The server agent is not answering', \array_column($this->alerts()->history(10), 'title'));
        $this->server->facts = $this->facts(diskUsed: 40, memUsed: 40, load: 0.5);
        self::assertSame(['raised' => 0, 'resolved' => 1], $checks->run());
    }

    public function test_a_certificate_about_to_expire_is_told_and_forgotten_when_renewed(): void
    {
        $this->addChannel(['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $this->server->facts = $this->facts(diskUsed: 10, memUsed: 10, load: 0.1);
        $package = $this->packageFor(null, 'basic');
        $account = $this->makeAccount('ana', $package, null);
        $domain = $this->db()->table('domains')->where('account_id', (int) $account['id'])->first();
        self::assertNotNull($domain);
        $site = ['account_id' => (int) $account['id'], 'domain_id' => (int) $domain['id'], 'autossl' => 1, 'force_https' => 1, 'cert_id' => 'abc', 'source' => 'auto'];
        $this->db()->table('ssl_sites')->insert($site + ['not_after' => \gmdate('Y-m-d H:i:s', \time() + 3 * 86400)]);
        $checks = $this->app->container()->get(AlertChecks::class);

        self::assertSame(['raised' => 1, 'resolved' => 0], $checks->run());
        $alert = $this->alerts()->history(1)[0];
        self::assertSame('ssl', $alert['category']);
        self::assertStringContainsString('ana.example.com', $alert['title']);
        self::assertStringContainsString('AutoSSL could not renew it', $alert['detail']);

        $this->db()->table('ssl_sites')->where('domain_id', (int) $domain['id'])->update(['not_after' => \gmdate('Y-m-d H:i:s', \time() + 60 * 86400)]);
        self::assertSame(['raised' => 0, 'resolved' => 1], $checks->run());

        // Already expired: critical.
        $this->db()->table('ssl_sites')->where('domain_id', (int) $domain['id'])->update(['not_after' => \gmdate('Y-m-d H:i:s', \time() - 86400)]);
        $checks->run();
        self::assertSame('critical', $this->alerts()->history(1)[0]['severity']);
    }

    public function test_the_page_the_api_and_the_cli(): void
    {
        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $page = $admin->get('/admin/alerts');
        self::assertSame(200, $page->status(), \substr(\strip_tags($page->body()), 0, 600));
        self::assertStringContainsString('No one is told yet', $page->body());
        self::assertStringContainsString('href="/admin/alerts"', $admin->get('/admin')->body(), 'it is in the menu');

        // Adding by the form; a bad address says so.
        $admin->post('/admin/alerts/channels', ['kind' => 'email', 'name' => 'Ops', 'to' => 'nope']);
        self::assertCount(0, $this->channels()->all());
        $admin->post('/admin/alerts/channels', ['kind' => 'email', 'name' => 'Ops', 'to' => 'ops@example.com']);
        $channel = $this->channels()->all()[0] ?? self::fail('the channel was not added');
        $body = $admin->get('/admin/alerts')->body();
        self::assertStringContainsString('ops@example.com', $body);

        $admin->post('/admin/alerts/channels/' . $channel['id'] . '/test');
        self::assertCount(1, $this->mailer()->sent());
        self::assertStringContainsString('Test alert from LPanel', $this->mailer()->sent()[0]->subject);

        $admin->post('/admin/alerts/channels/' . $channel['id'], ['categories' => ['backups', 'ssl'], 'min_severity' => 'critical', 'enabled' => '0']);
        $changed = $this->channels()->all()[0];
        self::assertSame(['backups ssl', 'critical', false], [$changed['categories'], $changed['min_severity'], $changed['enabled']]);
        $this->alerts()->raise('backups', 'backups.run.1', 'critical', 'Disabled channels hear nothing');
        self::assertCount(1, $this->mailer()->sent(), 'still just the test');

        $admin->post('/admin/alerts/settings', ['disk' => '85', 'memory' => '0', 'load' => '2', 'ssl' => '14', 'quiet' => '30']);
        self::assertSame(['disk' => 85, 'memory' => 0, 'load' => 2, 'ssl' => 14, 'quiet' => 30], $this->app->container()->get(AlertSettings::class)->get());
        $admin->post('/admin/alerts/settings', ['disk' => '5']);
        self::assertSame(85, $this->app->container()->get(AlertSettings::class)->get()['disk'], 'refused: unchanged');

        // The API.
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];
        $made = $this->api('POST', '/admin/api/v1/alerts/channels', $token, ['kind' => 'slack', 'name' => 'Team', 'url' => self::HOOK]);
        self::assertSame(201, $made->status(), $made->body());
        self::assertStringNotContainsString('SECRETSECRET', $made->body(), 'the address is a credential');
        $id = (int) $this->json($made)['data']['id'];
        self::assertSame('hooks.example.com', $this->json($made)['data']['target']);
        self::assertSame(422, $this->api('POST', '/admin/api/v1/alerts/channels', $token, ['kind' => 'slack', 'name' => 'x', 'url' => 'http://insecure.example.com/x'])->status());
        self::assertSame(200, $this->api('PUT', '/admin/api/v1/alerts/channels/' . $id, $token, ['min_severity' => 'info'])->status());
        self::assertSame('info', $this->channels()->find($id)['min_severity'] ?? null);

        $this->http->push(500, 'boom');
        $test = $this->api('POST', '/admin/api/v1/alerts/channels/' . $id . '/test', $token);
        self::assertSame(502, $test->status(), 'the test says it did not arrive');
        self::assertSame(404, $this->api('POST', '/admin/api/v1/alerts/channels/9999/test', $token)->status());

        $list = $this->json($this->api('GET', '/admin/api/v1/alerts', $token))['data'];
        self::assertCount(2, $list['channels']);
        self::assertNotEmpty($list['alerts']);
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/alerts/channels/' . $id, $token)->status());
        self::assertSame(404, $this->api('DELETE', '/admin/api/v1/alerts/channels/' . $id, $token)->status());

        // The CLI.
        [$code, $out] = $this->cli('add', 'telegram', 'Phone', null, self::TOKEN, '-100999');
        self::assertSame(0, $code, $out);
        [$code, $out] = $this->cli('add', 'email', 'Bad', 'nope');
        self::assertSame(1, $code);
        [$code, $out] = $this->cli('status');
        self::assertSame(0, $code);
        self::assertStringContainsString('Phone', $out);
        self::assertStringNotContainsString(self::TOKEN, $out);
        self::assertStringContainsString('Disabled channels hear nothing', $out);
        [$code] = $this->cli('setSettings', '70');
        self::assertSame(0, $code);
        self::assertSame(70, $this->app->container()->get(AlertSettings::class)->get()['disk']);
        [$code] = $this->cli('check');
        self::assertSame(0, $code);

        foreach (['alerts.channel_add', 'alerts.channel_change', 'alerts.channel_remove', 'alerts.test', 'alerts.settings'] as $action) {
            self::assertContains($action, \array_column($this->auditRows(), 'action'), $action . ' is in the audit log');
        }
    }

    public function test_only_root_looks_after_alerts(): void
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));
        $staff = $this->signedIn('admin', 'staff', 'staff-password-123');

        self::assertSame(403, $staff->get('/admin/alerts')->status());
        self::assertSame(403, $staff->post('/admin/alerts/channels', ['kind' => 'email', 'name' => 'x', 'to' => 'a@example.com'])->status());
        self::assertStringNotContainsString('href="/admin/alerts"', $staff->get('/admin')->body());
        self::assertSame([], $this->channels()->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(int $diskUsed, int $memUsed, float $load): array
    {
        return [
            'hostname' => 'server', 'cpus' => 2, 'uptime_seconds' => 1000,
            'load' => ['one' => $load, 'five' => $load, 'fifteen' => $load],
            'memory' => ['total_bytes' => 8_000_000_000, 'available_bytes' => (int) (8_000_000_000 * (100 - $memUsed) / 100), 'swap_total_bytes' => 0, 'swap_free_bytes' => 0],
            'disks' => [['mount' => '/var', 'fs_type' => 'xfs', 'total_bytes' => 100_000_000_000, 'used_bytes' => $diskUsed * 1_000_000_000, 'available_bytes' => (100 - $diskUsed) * 1_000_000_000, 'total_inodes' => 1000, 'free_inodes' => 900]],
        ];
    }

    /** @return array<string, mixed> */
    private function alert(?int $id): array
    {
        return $this->db()->table('ops_alerts')->where('id', $id)->first() ?? self::fail('no alert ' . $id);
    }

    /** @param array<string, mixed> $input */
    private function addChannel(array $input): int
    {
        [$c, $errors] = $this->channels()->validate($input);
        self::assertSame([], $errors);

        return $this->channels()->create($c, null, null);
    }

    /** @return array{0: int, 1: string} */
    private function cli(string $method, mixed ...$args): array
    {
        $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $code = $this->app->container()->get(AlertCommands::class)->{$method}(new Output($out, $err), ...$args);
        \rewind($out);
        \rewind($err);

        return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
    }

    private function channels(): AlertChannels
    {
        return $this->app->container()->get(AlertChannels::class);
    }

    private function alerts(): Alerts
    {
        return $this->app->container()->get(Alerts::class);
    }

    private function mailer(): \App\Engine\Mail\Transports\ArrayTransport
    {
        $t = $this->app->container()->get(Transport::class);

        return $t instanceof \App\Engine\Mail\Transports\ArrayTransport ? $t : self::fail('the mail transport is not the array one');
    }
}
