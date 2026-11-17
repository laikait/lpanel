<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Mail\MailActions;
use App\Modules\Packages\Packages;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M6b: an account's spam settings and its mailboxes' filters, the package's
 * sending limit, the server's filtering and relay host login, the queue and
 * delivery tracking.
 */
final class MailFilteringTest extends TestCase
{
    use HostingFixtures;

    private const ID_A = '1tAAAA-00000000AAA-AAAA';
    private const ID_B = '1tBBBB-00000000BBB-BBBB';

    private Application $app;

    private FakeServer $server;

    private int $package;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->package = $this->packageFor(null, 'Starter', 2048, ['domains', 'email']);
        $this->makeAccount('shop', $this->package, null);
        $this->makeAccount('other', $this->package, null);
        $outcome = $this->app->container()->get(MailActions::class)->createMailbox(null, null, $this->account('shop'), $this->mailDomain('shop.example.com'), 'info', 'mail-password-1', '100');
        self::assertTrue($outcome->ok(), (string) $outcome->message);
    }

    public function test_spam_settings_have_defaults_are_checked_and_reach_the_server(): void
    {
        self::assertSame(['junk' => 60, 'delete' => 0, 'allow' => [], 'block' => []], $this->server->mail['shop']['spam'], 'Junk at 6 until the owner changes it');

        $owner = $this->owner();
        $page = $owner->get('/panel/email/spam');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('aria-current="page"', $page->body());
        self::assertStringContainsString('Spam filtering is off on this server', $page->body(), 'the owner is told nothing is scored');

        $r = $owner->post('/panel/email/spam', ['junk_on' => '1', 'junk' => '8', 'delete_on' => '1', 'delete' => '5', 'allow' => '', 'block' => '']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('higher score', $r->body());
        $r = $owner->post('/panel/email/spam', ['junk_on' => '1', 'junk' => '6', 'allow' => "not an address\n", 'block' => '']);
        self::assertSame(422, $r->status());
        self::assertSame(60, $this->server->mail['shop']['spam']['junk'], 'nothing was sent');

        $r = $owner->post('/panel/email/spam', ['junk_on' => '1', 'junk' => '6.5', 'delete_on' => '1', 'delete' => '15', 'allow' => "Boss@Partner.example\n@friends.example\nboss@partner.example", 'block' => '@spam.example']);
        self::assertSame(303, $r->status());
        self::assertSame(['junk' => 65, 'delete' => 150, 'allow' => ['boss@partner.example', '@friends.example'], 'block' => ['@spam.example']], $this->server->mail['shop']['spam']);
        self::assertContains('mail.spam', \array_column($this->auditRows(), 'action'));

        // Turning filing off sends 0.
        $owner->post('/panel/email/spam', ['junk' => '6.5', 'allow' => '', 'block' => '']);
        self::assertSame(0, $this->server->mail['shop']['spam']['junk']);
        self::assertSame(0, $this->server->mail['shop']['spam']['delete']);
    }

    public function test_filters_are_checked_kept_in_order_and_sent_with_their_mailbox(): void
    {
        $owner = $this->owner();
        $id = (string) $this->box()['id'];

        $r = $owner->post('/panel/email/mailboxes/' . $id . '/filters', ['field' => 'subject', 'test' => 'contains', 'value' => 'Invoice', 'action' => 'folder', 'target' => '../etc']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('folder name', $r->body());

        $owner->post('/panel/email/mailboxes/' . $id . '/filters', ['field' => 'subject', 'test' => 'contains', 'value' => 'Invoice', 'action' => 'folder', 'target' => 'Work.Billing', 'stop' => '1']);
        $owner->post('/panel/email/mailboxes/' . $id . '/filters', ['field' => 'from', 'test' => 'is', 'value' => 'news@shop.test', 'action' => 'forward', 'target' => 'Archive@Example.NET']);
        $owner->post('/panel/email/mailboxes/' . $id . '/filters', ['field' => 'to', 'test' => 'ends', 'value' => '@list.example', 'action' => 'discard']);

        $sent = $this->server->mail['shop']['domains'][0]['mailboxes'][0]['filters'] ?? self::fail('no filters were sent');
        self::assertSame([
            ['field' => 'subject', 'match' => 'contains', 'value' => 'Invoice', 'action' => 'folder', 'target' => 'Work.Billing', 'stop' => true],
            ['field' => 'from', 'match' => 'is', 'value' => 'news@shop.test', 'action' => 'forward', 'target' => 'Archive@example.net', 'stop' => false],
            ['field' => 'to', 'match' => 'ends', 'value' => '@list.example', 'action' => 'discard', 'target' => '', 'stop' => true],
        ], $sent);

        $page = $owner->get('/panel/email')->body();
        self::assertStringContainsString('Work.Billing', $page);
        self::assertStringContainsString('3 filters', $page);

        $filters = $this->box()['filters'];
        $owner->post('/panel/email/filters/' . $filters[1]['id'] . '/move', ['direction' => 'up']);
        self::assertSame(['from', 'subject', 'to'], \array_column($this->server->mail['shop']['domains'][0]['mailboxes'][0]['filters'], 'field'));
        $owner->post('/panel/email/filters/' . $filters[2]['id'] . '/delete');
        self::assertSame(['from', 'subject'], \array_column($this->server->mail['shop']['domains'][0]['mailboxes'][0]['filters'], 'field'));

        $actions = \array_column($this->auditRows(), 'action');
        foreach (['mail.filter_add', 'mail.filter_move', 'mail.filter_remove'] as $want) {
            self::assertContains($want, $actions);
        }

        // Another account's filter is not found.
        $this->makeAccountMailbox('other');
        $theirs = $this->box('info@other.example.com', 'other');
        $this->app->container()->get(MailActions::class)->addFilter(null, null, $this->account('other'), $theirs, ['field' => 'subject', 'test' => 'is', 'value' => 'x', 'action' => 'discard']);
        $theirId = (int) $this->box('info@other.example.com', 'other')['filters'][0]['id'];
        self::assertSame(404, $owner->post('/panel/email/filters/' . $theirId . '/delete')->status());
    }

    public function test_the_sending_limit_comes_from_the_package(): void
    {
        self::assertSame(0, $this->server->mail['shop']['limit']);
        $packages = $this->app->container()->get(Packages::class);
        $row = $packages->byId($this->package) ?? self::fail('no package');
        [$clean, $errors] = $packages->validate(['name' => $row['name'], 'disk_mb' => 2048, 'mail_per_hour' => '250', 'features' => $row['features']], null, $this->package);
        self::assertSame([], $errors);
        $packages->update($this->package, $clean);
        self::assertSame(250, $this->server->mail['shop']['limit'], 'a package change reaches every account on it');
        self::assertSame(250, $this->server->mail['other']['limit']);
    }

    public function test_the_administrator_sets_filtering_and_the_relay_login(): void
    {
        $root = $this->root();
        $page = $root->get('/admin/email');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Spam and virus filtering', $page->body());

        $r = $root->post('/admin/email/filtering', ['spam' => '0', 'virus' => '1', 'reject' => '15']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('needs spam filtering', $r->body());
        self::assertSame(422, $root->post('/admin/email/filtering', ['spam' => '1', 'reject' => '2'])->status());
        self::assertSame(303, $root->post('/admin/email/filtering', ['spam' => '1', 'virus' => '1', 'reject' => '12.5'])->status());
        self::assertSame(['spam' => true, 'virus' => true, 'reject' => 125], $this->server->filtering);

        // The relay host's login: the password is sent once and never kept.
        self::assertSame(303, $root->post('/admin/email/settings', ['smarthost' => 'relay.example.net:587', 'relay_user' => 'apikey', 'relay_password' => 'S3cret:pass^word'])->status());
        self::assertSame(['user' => 'apikey', 'password' => 'S3cret:pass^word'], $this->server->relayLogin);
        self::assertSame(303, $root->post('/admin/email/settings', ['smarthost' => 'relay2.example.net:2525', 'relay_user' => 'apikey', 'relay_password' => ''])->status());
        self::assertSame('S3cret:pass^word', $this->server->relayLogin['password'] ?? null, 'an empty password with the same user keeps it');
        self::assertSame(422, $root->post('/admin/email/settings', ['smarthost' => 'relay2.example.net:2525', 'relay_user' => 'someone-else', 'relay_password' => ''])->status());
        self::assertSame(303, $root->post('/admin/email/settings', ['smarthost' => 'relay2.example.net:2525', 'relay_user' => '', 'relay_password' => ''])->status());
        self::assertNull($this->server->relayLogin);

        $everything = \json_encode([$this->auditRows(), $this->db()->table('mail_settings')->get()], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('S3cret', $everything, 'the password is in neither the audit log nor the settings');
        self::assertContains('mail.filtering', \array_column($this->auditRows(), 'action'));
    }

    public function test_the_queue_and_delivery_tracking(): void
    {
        $this->server->queue = [
            ['id' => self::ID_A, 'age' => 7200, 'size' => 2048, 'sender' => 'info@shop.example.com', 'frozen' => false, 'recipients' => ['someone@gmail.com'], 'done' => 0],
            ['id' => self::ID_B, 'age' => 90000, 'size' => 512, 'sender' => '', 'frozen' => true, 'recipients' => ['bounce@nowhere.example'], 'done' => 1],
        ];
        $this->server->deliveries = [
            ['account' => 'shop', 'time' => '2026-11-17 09:00:00', 'id' => self::ID_A, 'from' => 'info@shop.example.com', 'to' => 'someone@gmail.com', 'status' => 'deferred', 'detail' => '451 try later', 'size' => 2048, 'login' => 'info@shop.example.com', 'done' => ''],
            ['account' => 'other', 'time' => '2026-11-17 08:00:00', 'id' => self::ID_B, 'from' => 'x@other.example.com', 'to' => 'secret-friend@example.org', 'status' => 'delivered', 'detail' => 'mx.example.org', 'size' => 10, 'login' => '', 'done' => ''],
        ];

        $root = $this->root();
        $page = $root->get('/admin/email/queue');
        self::assertSame(200, $page->status());
        self::assertStringContainsString(self::ID_A, $page->body());
        self::assertStringContainsString('Frozen', $page->body());
        self::assertSame(200, $root->get('/admin/email/queue/' . self::ID_A)->status());
        self::assertSame(404, $root->get('/admin/email/queue/1tZZZZ-00000000ZZZ-ZZZZ')->status());

        $root->post('/admin/email/queue', ['action' => 'remove', 'ids' => [self::ID_B, 'not an id']]);
        self::assertSame(['remove ' . self::ID_B], $this->server->queueActs, 'only real message ids reach the agent');
        self::assertCount(1, $this->server->queue);
        $root->post('/admin/email/queue', ['action' => 'flush']);
        self::assertContains('flush ', $this->server->queueActs);
        self::assertContains('mail.queue', \array_column($this->auditRows(), 'action'));

        self::assertStringContainsString('secret-friend@example.org', $root->get('/admin/email/track')->body(), 'the administrator sees every message');
        $owner = $this->owner()->get('/panel/email/track')->body();
        self::assertStringContainsString('someone@gmail.com', $owner);
        self::assertStringContainsString('Retrying', $owner);
        self::assertStringNotContainsString('secret-friend', $owner, 'an owner sees only their own mail');

        self::assertNotSame(200, $this->owner()->get('/admin/email/queue')->status());
    }

    public function test_the_api(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        $base = '/admin/api/v1/accounts/shop/email';

        $spam = $this->json($this->api('PUT', $base . '/spam', $token, ['junk' => 7, 'block' => ['@spam.example']]))['data'];
        self::assertEquals(['junk' => 7, 'delete' => 0, 'allow' => [], 'block' => ['@spam.example'], 'filtering' => false], $spam);
        self::assertSame(422, $this->api('PUT', $base . '/spam', $token, ['junk' => 'lots'])->status());

        $r = $this->api('POST', $base . '/mailboxes/info@shop.example.com/filters', $token, ['field' => 'subject', 'test' => 'contains', 'value' => 'Invoice', 'action' => 'folder', 'target' => 'Billing', 'stop' => true]);
        self::assertSame(201, $r->status());
        $filters = $this->json($r)['data']['filters'];
        self::assertSame('Billing', $filters[0]['target']);
        self::assertSame(422, $this->api('POST', $base . '/mailboxes/info@shop.example.com/filters', $token, ['field' => 'body', 'test' => 'contains', 'value' => 'x', 'action' => 'discard'])->status());
        self::assertSame(204, $this->api('DELETE', $base . '/filters/' . $filters[0]['id'], $token)->status());

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/email/filtering', $token, ['spam' => true, 'virus' => false, 'reject' => 20])->status());
        self::assertSame(200, $this->server->filtering['reject']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/email/filtering', $token, ['spam' => false, 'virus' => true])->status());

        $this->server->queue = [['id' => self::ID_A, 'age' => 60, 'size' => 100, 'sender' => 'a@b.example', 'frozen' => false, 'recipients' => ['c@d.example'], 'done' => 0]];
        self::assertSame(1, $this->json($this->api('GET', '/admin/api/v1/email/queue', $token))['data']['total']);
        self::assertSame(200, $this->api('POST', '/admin/api/v1/email/queue', $token, ['action' => 'freeze', 'ids' => [self::ID_A]])->status());
        self::assertSame(422, $this->api('POST', '/admin/api/v1/email/queue', $token, ['action' => 'freeze', 'ids' => []])->status());
        self::assertSame(200, $this->api('GET', '/admin/api/v1/email/track', $token)->status());
        self::assertSame(200, $this->api('GET', $base . '/track', $token)->status());
    }

    // -------------------------------------------------------------------------

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    private function root(): Browser
    {
        $this->rootPassword($this->app, 'root-password-123');

        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    private function makeAccountMailbox(string $account): void
    {
        $outcome = $this->app->container()->get(MailActions::class)->createMailbox(null, null, $this->account($account), $this->mailDomain($account . '.example.com'), 'info', 'mail-password-1', '100');
        self::assertTrue($outcome->ok(), (string) $outcome->message);
    }

    /** @return array<string, mixed> */
    private function mailDomain(string $name): array
    {
        return $this->db()->table('mail_domains')->where('name', $name)->first() ?? self::fail('no mail domain ' . $name);
    }

    /** @return array<string, mixed> */
    private function box(string $address = 'info@shop.example.com', string $account = 'shop'): array
    {
        return $this->app->container()->get(\App\Modules\Mail\Mailboxes::class)->mailbox((int) $this->account($account)['id'], $address) ?? self::fail('no mailbox ' . $address);
    }
}
