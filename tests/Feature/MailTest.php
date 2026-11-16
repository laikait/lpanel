<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Modules\Accounts\Provisioner;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsZones;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailSync;
use App\Modules\Mail\MailUsage;
use App\Modules\Web\DomainActions;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class MailTest extends TestCase
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
        $dns = $this->app->container()->get(DnsActions::class);
        $dns->saveAddresses(null, null, ['ip4' => '192.0.2.10', 'ip6' => '']);
        $dns->saveProfile(null, null, DnsZones::SERVER, 'ns1.host.test, ns2.host.test', null);
        $this->package = $this->packageFor(null, 'Starter', 2048, ['domains', 'email', 'dns']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_a_new_account_gets_a_mail_domain_and_its_dkim_key_is_published(): void
    {
        $sent = $this->server->mail['shop'] ?? self::fail('no mail state was sent');
        self::assertSame('shop', $sent['username']);
        self::assertSame([['name' => 'shop.example.com', 'local' => true, 'catchall' => '', 'mailboxes' => [], 'forwarders' => []]], $sent['domains']);

        $domain = $this->mailDomain('shop.example.com');
        self::assertStringStartsWith('v=DKIM1; k=rsa; p=', (string) $domain['dkim'], 'the key the agent made is kept');
        $records = $this->sent('shop.example.com');
        self::assertContains('default._domainkey.shop.example.com TXT ' . $domain['dkim'], $records);
        self::assertContains('_dmarc.shop.example.com TXT ' . MailSync::DMARC, $records);
        self::assertContains('shop.example.com TXT v=spf1 a mx ~all', $records, 'SPF comes from the template');
        self::assertSame(MailSync::APPLIED, $this->state('shop')['state']);

        $zone = $this->db()->table('dns_zones')->where('name', 'shop.example.com')->first() ?? self::fail('no zone');
        $page = $this->owner()->get('/panel/dns/' . $zone['id']);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('default._domainkey', $page->body(), 'the owner sees the mail records in the zone editor');
        self::assertStringContainsString('v=spf1 a mx ~all', $page->body());
    }

    public function test_the_owner_makes_mailboxes_and_only_a_hash_reaches_the_server(): void
    {
        $owner = $this->owner();
        $domainId = (string) $this->mailDomain('shop.example.com')['id'];
        $r = $owner->post('/panel/email/mailboxes', ['local' => 'Info', 'domain' => $domainId, 'password' => 'mail-password-1', 'quota' => '500']);
        self::assertSame(303, $r->status());

        $box = $this->server->mail['shop']['domains'][0]['mailboxes'][0];
        self::assertSame('info', $box['local'], 'local parts are lowercase');
        self::assertSame(500, $box['quota_mb']);
        self::assertTrue(\password_verify('mail-password-1', $box['hash']));
        self::assertStringStartsWith('$2y$', $box['hash']);
        self::assertStringNotContainsString('mail-password-1', \json_encode($this->db()->table('mail_boxes')->get(), \JSON_THROW_ON_ERROR), 'the password is not kept');

        $page = $owner->get('/panel/email')->body();
        self::assertStringContainsString('info@shop.example.com', $page);
        self::assertStringContainsString('993', $page, 'what to type into a mail client');
        self::assertStringContainsString('default._domainkey.shop.example.com', $page, 'the DNS records it needs');

        $id = $this->box('info@shop.example.com')['id'];
        $owner->post('/panel/email/mailboxes/' . $id . '/password', ['password' => 'another-password-2']);
        self::assertTrue(\password_verify('another-password-2', $this->server->mail['shop']['domains'][0]['mailboxes'][0]['hash']));
        $owner->post('/panel/email/mailboxes/' . $id . '/quota', ['quota' => '0']);
        self::assertSame(0, $this->server->mail['shop']['domains'][0]['mailboxes'][0]['quota_mb'], '0 is no cap of its own');

        $actions = \array_column($this->auditRows(), 'action');
        foreach (['mail.mailbox_create', 'mail.mailbox_password', 'mail.mailbox_quota'] as $a) {
            self::assertContains($a, $actions);
        }
    }

    public function test_input_and_limits_are_checked_before_anything_is_sent(): void
    {
        $this->db()->table('packages')->where('id', $this->package)->update(['mailboxes' => 1]);
        $owner = $this->owner();
        $domainId = (string) $this->mailDomain('shop.example.com')['id'];
        $before = $this->server->ran('mail.apply');

        foreach ([
            ['local' => 'info+news', 'why' => 'cannot hold'],
            ['local' => '.info', 'why' => 'lowercase letters'],
            ['local' => 'a b', 'why' => 'lowercase letters'],
            ['local' => 'ok', 'password' => 'short', 'why' => 'at least 10'],
            ['local' => 'ok', 'quota' => '999999', 'why' => 'disk space'],
            ['local' => 'ok', 'quota' => '-1', 'why' => 'whole number'],
        ] as $case) {
            $r = $owner->post('/panel/email/mailboxes', ['local' => $case['local'], 'domain' => $domainId, 'password' => $case['password'] ?? 'mail-password-1', 'quota' => $case['quota'] ?? '100']);
            self::assertSame(422, $r->status(), $case['local']);
            self::assertStringContainsString($case['why'], $r->body());
        }

        self::assertSame($before, $this->server->ran('mail.apply'), 'nothing was sent');
        $owner->post('/panel/email/mailboxes', ['local' => 'info', 'domain' => $domainId, 'password' => 'mail-password-1', 'quota' => '100']);
        self::assertStringContainsString('all in use', $owner->post('/panel/email/mailboxes', ['local' => 'sales', 'domain' => $domainId, 'password' => 'mail-password-1', 'quota' => '100'])->body());
        $this->db()->table('packages')->where('id', $this->package)->update(['mailboxes' => 0]);
        self::assertStringContainsString('already', $owner->post('/panel/email/mailboxes', ['local' => 'info', 'domain' => $domainId, 'password' => 'mail-password-1', 'quota' => '100'])->body());

        // Another account's mail domain and mailbox are not this owner's.
        $this->makeAccount('other', $this->package, null);
        $other = $this->mailDomain('other.example.com');
        self::assertSame(404, $owner->post('/panel/email/mailboxes', ['local' => 'x', 'domain' => (string) $other['id'], 'password' => 'mail-password-1', 'quota' => '1'])->status());
        $this->app->container()->get(MailActions::class)->createMailbox(null, null, $this->account('other'), $other, 'boss', 'mail-password-1');
        self::assertSame(404, $owner->post('/panel/email/mailboxes/' . $this->box('boss@other.example.com', 'other')['id'] . '/delete')->status());

        self::assertSame(['someone@example.com', null], Mailboxes::checkAddress('someone@Example.COM'));
        foreach (['|/bin/sh@x.com', '/etc/passwd', 'a@b', 'a b@c.com', '"q"@c.com', 'x@c.com, y@d.com', ':include:/etc/aliases'] as $bad) {
            self::assertSame('Mail.error_address', Mailboxes::checkAddress($bad)[1], $bad);
        }
    }

    public function test_forwarders_and_the_catch_all(): void
    {
        $owner = $this->owner();
        $domainId = (string) $this->mailDomain('shop.example.com')['id'];
        $owner->post('/panel/email/forwarders', ['local' => 'sales', 'domain' => $domainId, 'destination' => 'Bob@Gmail.COM']);
        $owner->post('/panel/email/forwarders', ['local' => 'sales', 'domain' => $domainId, 'destination' => 'amy@example.net']);
        self::assertSame([['local' => 'sales', 'to' => ['Bob@gmail.com', 'amy@example.net']]], \array_map(
            static fn(array $f): array => ['local' => $f['local'], 'to' => \array_values(\array_intersect(['Bob@gmail.com', 'amy@example.net'], $f['to']))],
            $this->server->mail['shop']['domains'][0]['forwarders'],
        ));

        self::assertStringContainsString('already', $owner->post('/panel/email/forwarders', ['local' => 'sales', 'domain' => $domainId, 'destination' => 'amy@example.net'])->body());
        self::assertStringContainsString('itself', $owner->post('/panel/email/forwarders', ['local' => 'sales', 'domain' => $domainId, 'destination' => 'sales@shop.example.com'])->body());
        self::assertSame(422, $owner->post('/panel/email/forwarders', ['local' => 'sales', 'domain' => $domainId, 'destination' => '|/bin/true'])->status());

        $owner->post('/panel/email/domains/' . $domainId, ['catchall' => 'discard']);
        self::assertSame(Mailboxes::DISCARD, $this->server->mail['shop']['domains'][0]['catchall']);
        $r = $owner->post('/panel/email/domains/' . $domainId, ['catchall' => 'forward', 'catchall_to' => 'nope']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('email address', $r->body());
        $owner->post('/panel/email/domains/' . $domainId, ['catchall' => 'forward', 'catchall_to' => 'all@example.net', 'local' => '0']);
        self::assertSame('all@example.net', $this->server->mail['shop']['domains'][0]['catchall']);
        self::assertFalse($this->server->mail['shop']['domains'][0]['local'], 'mail delivered by another server');
        $owner->post('/panel/email/domains/' . $domainId, ['catchall' => 'refuse', 'local' => '1']);
        self::assertSame('', $this->server->mail['shop']['domains'][0]['catchall']);

        $forwarder = $this->db()->table('mail_forwarders')->where('destination', 'amy@example.net')->first() ?? self::fail('no forwarder');
        $owner->post('/panel/email/forwarders/' . $forwarder['id'] . '/delete');
        self::assertSame(['Bob@gmail.com'], $this->server->mail['shop']['domains'][0]['forwarders'][0]['to']);
        self::assertContains('mail.forwarder_remove', \array_column($this->auditRows(), 'action'));
    }

    public function test_the_autoresponder(): void
    {
        $owner = $this->owner();
        $this->mailbox('info');
        $id = $this->box('info@shop.example.com')['id'];

        $r = $owner->post('/panel/email/mailboxes/' . $id . '/responder', ['subject' => 'Away', 'body' => 'Back soon.', 'days' => '1', 'start' => '2026-12-10', 'end' => '2026-12-01']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('cannot end before it starts', $r->body());
        self::assertArrayNotHasKey('autoresponder', $this->server->mail['shop']['domains'][0]['mailboxes'][0]);

        $owner->post('/panel/email/mailboxes/' . $id . '/responder', ['subject' => 'Away "now"', 'body' => "Back Monday.\r\nBob", 'from_name' => 'Bob', 'days' => '3', 'start' => '', 'end' => '2026-12-31']);
        self::assertSame(
            ['subject' => 'Away "now"', 'body' => "Back Monday.\nBob", 'from_name' => 'Bob', 'days' => 3, 'start' => '', 'end' => '2026-12-31'],
            $this->server->mail['shop']['domains'][0]['mailboxes'][0]['autoresponder'],
        );
        self::assertStringContainsString('Autoresponder', $owner->get('/panel/email')->body());

        $owner->post('/panel/email/mailboxes/' . $id . '/responder/delete');
        self::assertArrayNotHasKey('autoresponder', $this->server->mail['shop']['domains'][0]['mailboxes'][0]);
    }

    public function test_a_deleted_mailboxs_mail_goes_once_the_server_no_longer_has_it(): void
    {
        $this->mailbox('info');
        $this->mailbox('bob');
        $owner = $this->owner();
        $owner->post('/panel/email/mailboxes/' . $this->box('info@shop.example.com')['id'] . '/delete');
        self::assertSame(['bob'], \array_column($this->server->mail['shop']['domains'][0]['mailboxes'], 'local'));
        self::assertSame(['shop shop.example.com info'], $this->server->mailPurged);

        // The agent cannot be reached: the mailbox goes now, its mail later.
        $this->server->down = true;
        $owner->post('/panel/email/mailboxes/' . $this->box('bob@shop.example.com')['id'] . '/delete');
        self::assertCount(1, $this->db()->table('mail_purges')->get());
        $this->server->down = false;
        $this->app->container()->get(MailSync::class)->settleAll();
        self::assertSame(['shop shop.example.com info', 'shop shop.example.com bob'], $this->server->mailPurged);
        self::assertSame([], $this->db()->table('mail_purges')->get());

        // A purge waiting for an address that is a mailbox again is dropped.
        $this->server->down = true;
        $owner->post('/panel/email/mailboxes', ['local' => 'bob', 'domain' => (string) $this->mailDomain('shop.example.com')['id'], 'password' => 'mail-password-1', 'quota' => '1']);
        $this->server->down = false;
        $this->app->container()->get(MailSync::class)->settleAll();
        self::assertCount(2, $this->server->mailPurged);
    }

    public function test_a_change_the_server_refuses_is_undone(): void
    {
        $this->server->failNext = ['op' => 'mail.apply', 'code' => 'mail_failed', 'message' => 'no'];
        $r = $this->owner()->post('/panel/email/mailboxes', ['local' => 'info', 'domain' => (string) $this->mailDomain('shop.example.com')['id'], 'password' => 'mail-password-1', 'quota' => '1']);
        self::assertSame(303, $r->status());
        self::assertSame([], $this->db()->table('mail_boxes')->get());
        self::assertSame([], $this->server->mail['shop']['domains'][0]['mailboxes']);
        self::assertSame(MailSync::APPLIED, $this->state('shop')['state'], 'the state before is back on the server');
    }

    public function test_mail_domains_and_their_records_follow_the_accounts_domains(): void
    {
        $domains = $this->app->container()->get(DomainActions::class);
        $account = $this->account('shop');
        $domains->add(null, null, $account, ['name' => 'other.example.com', 'kind' => 'addon']);
        $domains->add(null, null, $account, ['name' => 'blog.shop.example.com', 'kind' => 'sub']);

        self::assertSame(['blog.shop.example.com', 'other.example.com', 'shop.example.com'], \array_column($this->server->mail['shop']['domains'], 'name'));
        $other = $this->mailDomain('other.example.com');
        self::assertContains('default._domainkey.other.example.com TXT ' . $other['dkim'], $this->sent('other.example.com'));
        $blog = $this->mailDomain('blog.shop.example.com');
        $parent = $this->sent('shop.example.com');
        self::assertContains('default._domainkey.blog.shop.example.com TXT ' . $blog['dkim'], $parent, 'a subdomain\'s records go in the zone above it');
        self::assertContains('blog.shop.example.com TXT v=spf1 a mx ~all', $parent);
        self::assertNotContains('_dmarc.blog.shop.example.com TXT ' . MailSync::DMARC, $parent, 'a subdomain takes its domain\'s policy');

        // A record the owner removed stays removed; a zone reset brings it back.
        $zone = $this->db()->table('dns_zones')->where('name', 'other.example.com')->first() ?? self::fail('no zone');
        $dmarc = $this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', '_dmarc')->first() ?? self::fail('no dmarc');
        $dnsActions = $this->app->container()->get(DnsActions::class);
        $dnsActions->deleteRecord(null, null, $zone, $dmarc);
        $this->app->container()->get(MailSync::class)->touch((int) $account['id'], force: true);
        self::assertNotContains('_dmarc.other.example.com TXT ' . MailSync::DMARC, $this->sent('other.example.com'));
        $dnsActions->reset(null, null, $zone);
        self::assertContains('_dmarc.other.example.com TXT ' . MailSync::DMARC, $this->sent('other.example.com'));

        // The owner's own DMARC policy is never doubled.
        [$outcome] = $dnsActions->updateRecord(null, null, $zone, $this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', '_dmarc')->first() ?? [], ['content' => 'v=DMARC1; p=reject']);
        self::assertTrue($outcome->ok());
        $this->db()->table('dns_provided')->where('zone_id', $zone['id'])->delete();
        $this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', '_dmarc')->update(['source' => null]);
        $this->app->container()->get(MailSync::class)->publish((int) $account['id']);
        self::assertSame(['_dmarc.other.example.com TXT v=DMARC1; p=reject'], \array_values(\array_filter($this->sent('other.example.com'), static fn(string $r): bool => \str_starts_with($r, '_dmarc.'))));

        // A removed domain takes its mail domain with it, and its mail once the server lets go.
        $this->mailbox('info', 'other.example.com');
        $domains->remove(null, null, $account, $this->db()->table('domains')->where('name', 'other.example.com')->first() ?? []);
        self::assertNull($this->db()->table('mail_domains')->where('name', 'other.example.com')->first());
        self::assertSame([], $this->db()->table('mail_boxes')->get());
        self::assertNotContains('other.example.com', \array_column($this->server->mail['shop']['domains'], 'name'));
        self::assertContains('shop other.example.com ', $this->server->mailPurged);
    }

    public function test_suspension_termination_and_a_package_without_email(): void
    {
        $this->mailbox('info');
        $provisioner = $this->app->container()->get(Provisioner::class);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $id = (int) $this->account('shop')['id'];

        $provisioner->suspend($root, null, $id, 'unpaid');
        self::assertTrue($this->server->mail['shop']['suspended'], 'mailboxes stop logging in');
        $provisioner->unsuspend($root, null, $id);
        self::assertFalse($this->server->mail['shop']['suspended']);

        // Email turned off in the package: no mail domains on the server, no records in the zones.
        $this->db()->table('packages')->where('id', $this->package)->update(['features' => \json_encode(['domains', 'dns'], \JSON_THROW_ON_ERROR)]);
        $this->app->container()->get(\App\Engine\Hook\HookEngine::class)->do('packages.updated', $this->app->container()->get(\App\Modules\Packages\Packages::class)->byId($this->package));
        self::assertSame([], $this->server->mail['shop']['domains']);
        self::assertSame([], \array_values(\array_filter($this->sent('shop.example.com'), static fn(string $r): bool => \str_contains($r, 'DKIM') || \str_contains($r, 'DMARC'))));
        self::assertNotSame(200, $this->owner()->get('/panel/email')->status());
        self::assertSame(1, \count($this->db()->table('mail_boxes')->get()), 'the panel keeps the mailboxes');

        $provisioner->terminate($root, null, $id);
        $provisioner->settleAll();
        self::assertSame([], $this->db()->table('mail_domains')->get());
        self::assertSame([], $this->db()->table('mail_boxes')->get());
        self::assertSame([], $this->db()->table('mail_accounts')->get());
        self::assertArrayNotHasKey('shop', $this->server->mail);
    }

    public function test_usage_is_measured(): void
    {
        $this->mailbox('info');
        $this->server->mailUsage = ['info@shop.example.com' => 5_242_880, 'x@elsewhere.test' => 9];
        self::assertSame(1, $this->app->container()->get(MailUsage::class)->refresh());
        self::assertSame(5_242_880, (int) $this->box('info@shop.example.com')['used_bytes']);
        self::assertStringContainsString('5.0 MB', $this->owner()->get('/panel/email')->body());
    }

    public function test_the_api(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        $base = '/admin/api/v1/accounts/shop/email';

        $r = $this->api('POST', $base . '/mailboxes', $token, ['address' => 'info@shop.example.com', 'password' => 'api-password-123', 'quota_mb' => 250]);
        self::assertSame(201, $r->status());
        self::assertStringNotContainsString('api-password-123', $r->body());
        self::assertSame(250, $this->json($r)['data']['mailboxes'][0]['quota_mb']);
        self::assertSame(422, $this->api('POST', $base . '/mailboxes', $token, ['address' => 'x@nowhere.test', 'password' => 'api-password-123'])->status());

        self::assertSame(200, $this->api('PUT', $base . '/mailboxes/info@shop.example.com', $token, ['password' => 'api-password-456', 'quota_mb' => 0])->status());
        self::assertTrue(\password_verify('api-password-456', $this->server->mail['shop']['domains'][0]['mailboxes'][0]['hash']));
        self::assertSame(422, $this->api('PUT', $base . '/mailboxes/info@shop.example.com/autoresponder', $token, ['subject' => '', 'body' => 'x'])->status());
        $r = $this->api('PUT', $base . '/mailboxes/info@shop.example.com/autoresponder', $token, ['subject' => 'Away', 'body' => 'Back soon.', 'days' => 2]);
        self::assertSame(2, $this->json($r)['data']['mailboxes'][0]['autoresponder']['days']);
        self::assertSame(200, $this->api('DELETE', $base . '/mailboxes/info@shop.example.com/autoresponder', $token)->status());

        self::assertSame(201, $this->api('POST', $base . '/forwarders', $token, ['address' => 'sales@shop.example.com', 'to' => 'bob@example.net'])->status());
        $data = $this->json($this->api('PUT', $base . '/domains/shop.example.com', $token, ['catchall' => 'info@shop.example.com']))['data'];
        self::assertSame('info@shop.example.com', $data['domains'][0]['catchall']);
        self::assertStringStartsWith('v=DKIM1', (string) $data['domains'][0]['dkim']);
        self::assertSame(204, $this->api('DELETE', $base . '/forwarders/' . $data['forwarders'][0]['id'], $token)->status());
        self::assertSame(204, $this->api('DELETE', $base . '/mailboxes/info@shop.example.com', $token)->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/nobody/email', $token)->status());

        $server = $this->json($this->api('GET', '/admin/api/v1/email', $token))['data'];
        self::assertSame('4.100.1', $server['server']['exim']['version']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/email/settings', $token, ['smarthost' => 'bad host'])->status());
        self::assertSame('relay.example.net:587', $this->json($this->api('PUT', '/admin/api/v1/email/settings', $token, ['smarthost' => 'Relay.Example.net:587']))['data']['smarthost']);
        self::assertSame('relay.example.net:587', $this->server->smarthost);
    }

    public function test_the_admin_page(): void
    {
        $this->mailbox('info');
        $this->rootPassword($this->app, 'root-password-123');
        $root = $this->signedIn('admin', 'root', 'root-password-123');

        $page = $root->get('/admin/email')->body();
        self::assertStringContainsString('4.100.1', $page);
        self::assertStringContainsString('2.3.16', $page);
        self::assertStringContainsString('shop.example.com', $page);

        $r = $root->post('/admin/email/settings', ['smarthost' => 'not a host']);
        self::assertSame(422, $r->status());
        self::assertStringContainsString('host name', $r->body());
        $root->post('/admin/email/settings', ['smarthost' => 'smtp.example.net']);
        self::assertSame('smtp.example.net:25', $this->server->smarthost);
        $root->post('/admin/email/settings', ['smarthost' => '']);
        self::assertSame('', $this->server->smarthost);
        self::assertContains('mail.settings', \array_column($this->auditRows(), 'action'));

        self::assertStringContainsString('info@shop.example.com', $root->get('/admin/accounts/' . $this->account('shop')['id'])->body(), 'the account page shows its mail');
        self::assertNotSame(200, $this->owner()->get('/admin/email')->status());
    }

    // -------------------------------------------------------------------------

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    private function mailbox(string $local, string $domain = 'shop.example.com', string $account = 'shop'): void
    {
        $outcome = $this->app->container()->get(MailActions::class)->createMailbox(null, null, $this->account($account), $this->mailDomain($domain), $local, 'mail-password-1', '100');
        self::assertTrue($outcome->ok(), (string) $outcome->message);
    }

    /** @return array<string, mixed> */
    private function mailDomain(string $name): array
    {
        return $this->db()->table('mail_domains')->where('name', $name)->first() ?? self::fail('no mail domain ' . $name);
    }

    /** @return array<string, mixed> */
    private function box(string $address, string $account = 'shop'): array
    {
        return $this->app->container()->get(Mailboxes::class)->mailbox((int) $this->account($account)['id'], $address) ?? self::fail('no mailbox ' . $address);
    }

    /** @return array<string, mixed> */
    private function state(string $account): array
    {
        return $this->db()->table('mail_accounts')->where('account_id', (int) $this->account($account)['id'])->first() ?? self::fail('no mail state');
    }

    /** @return list<string> the zone's records as last sent to the server: "name TYPE content" */
    private function sent(string $zone): array
    {
        return \array_values(\array_map(
            static fn(array $r): string => $r['name'] . ' ' . $r['type'] . ' ' . $r['content'],
            ($this->server->zones[$zone] ?? self::fail('the server has no zone ' . $zone))['records'],
        ));
    }
}
