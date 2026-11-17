<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsZones;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailChecks;
use App\Modules\Web\SslSites;
use App\Modules\Web\WebSettings;
use App\Modules\Web\WebSync;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M6c: the mail names of each site, a certificate for each mail domain,
 * mail clients' setup (autoconfig, autodiscover, the setup page, the Apple
 * profile), webmail's sign-on and the deliverability checks.
 */
final class MailClientTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private int $package;

    private string $tickets;

    protected function setUp(): void
    {
        $this->app = $this->app();
        // Before anything signs in: the sign-in service keeps the checker it starts with.
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->app->container()->get(WebSettings::class)->set('ssl_hostname', 'panel.host.test');
        $dns = $this->app->container()->get(DnsActions::class);
        $dns->saveAddresses(null, null, ['ip4' => '192.0.2.10', 'ip6' => '']);
        $dns->saveProfile(null, null, DnsZones::SERVER, 'ns1.host.test, ns2.host.test', null);
        $this->package = $this->packageFor(null, 'Starter', 2048, ['domains', 'email', 'dns']);
        $this->makeAccount('shop', $this->package, null);
        $outcome = $this->app->container()->get(MailActions::class)->createMailbox(null, null, $this->account('shop'), $this->mailDomain('shop.example.com'), 'info', 'mail-password-1', '100');
        self::assertTrue($outcome->ok(), (string) $outcome->message);
        $this->tickets = \sys_get_temp_dir() . '/lpanel-webmail-' . \bin2hex(\random_bytes(4));
        \mkdir($this->tickets);
        $this->app->container()->get(Config::class)->set('Mail.webmail_tickets', $this->tickets);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tickets . '/*') ?: [] as $f) {
            \unlink($f);
        }

        @\rmdir($this->tickets);
        parent::tearDown();
    }

    public function test_sites_answer_to_the_mail_names_and_send_webmail_on(): void
    {
        $site = $this->server->sites['shop']['sites'][0];
        self::assertSame(['www.shop.example.com', 'mail.shop.example.com', 'autoconfig.shop.example.com', 'autodiscover.shop.example.com', 'webmail.shop.example.com'], $site['aliases']);
        self::assertTrue($site['mail_setup']);
        self::assertSame(['webmail.shop.example.com'], $site['webmail']);
        self::assertSame('https://panel.host.test:7444/webmail/', $this->server->sites['shop']['webmail_url']);

        // A name that is a domain of its own stays that domain's.
        $owner = $this->owner();
        $owner->post('/panel/domains', ['kind' => 'sub', 'name' => 'webmail.shop.example.com', 'php' => '83']);
        $sites = \array_column($this->server->sites['shop']['sites'], null, 'name');
        self::assertNotContains('webmail.shop.example.com', $sites['shop.example.com']['aliases']);
        self::assertArrayNotHasKey('webmail', $sites['shop.example.com']);
        self::assertSame([], $sites['webmail.shop.example.com']['aliases']);

        // The names are in the account's zone.
        $records = $this->records('shop.example.com');
        self::assertContains('autoconfig.shop.example.com A 192.0.2.10', $records);
        self::assertContains('autodiscover.shop.example.com A 192.0.2.10', $records);

        // Without email in the package, none of it.
        $bare = $this->packageFor(null, 'Bare', 1024, ['domains']);
        $this->makeAccount('plain', $bare, null);
        $plain = $this->server->sites['plain']['sites'][0];
        self::assertSame(['www.plain.example.com'], $plain['aliases']);
        self::assertArrayNotHasKey('mail_setup', $plain);
    }

    public function test_a_certificate_that_covers_mail_serves_the_mail_domain_too(): void
    {
        self::assertArrayNotHasKey('cert', $this->server->mail['shop']['domains'][0], 'no certificate yet');
        $account = $this->account('shop');
        $domain = $this->db()->table('domains')->where('name', 'shop.example.com')->first() ?? self::fail('no domain');
        $info = ['id' => 'abcdef0123456789', 'names' => ['shop.example.com', 'www.shop.example.com', 'mail.shop.example.com'], 'issuer' => 'Test CA', 'serial' => '01', 'key_type' => 'ecdsa', 'self_signed' => false, 'not_before' => \time() - 60, 'not_after' => \time() + 86400 * 60];
        $this->app->container()->get(SslSites::class)->install((int) $account['id'], (int) $domain['id'], $info, SslSites::AUTO, 'letsencrypt');
        $this->server->certs['shop']['abcdef0123456789'] = $info;
        $this->app->container()->get(WebSync::class)->touch((int) $account['id']);

        self::assertSame('abcdef0123456789', $this->server->mail['shop']['domains'][0]['cert'] ?? null, 'web.applied brought the certificate to mail');
        $page = $this->owner()->get('/panel/email/mailboxes/' . $this->box()['id'] . '/setup')->body();
        self::assertStringContainsString('mail.shop.example.com', $page);
        self::assertStringNotContainsString('own name, for now', $page);
    }

    public function test_mail_clients_find_their_settings(): void
    {
        $app = $this->app;
        $auto = $app->handle(Request::create('GET', '/mail-setup/autoconfig', ['headers' => ['Host' => 'autoconfig.shop.example.com']]));
        self::assertSame(200, $auto->status());
        self::assertStringContainsString('application/xml', (string) $auto->header('Content-Type'));
        self::assertStringContainsString('<emailProvider id="shop.example.com">', $auto->body());
        self::assertStringContainsString('<hostname>panel.host.test</hostname>', $auto->body(), 'the hostname until the domain has its own certificate');
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $auto->body());
        self::assertNotFalse(\simplexml_load_string($auto->body()), 'well formed');

        // By the address asked about, from any of the site's names.
        $q = $app->handle(Request::create('GET', '/mail-setup/autoconfig?emailaddress=info%40shop.example.com', ['headers' => ['Host' => 'shop.example.com']]));
        self::assertStringContainsString('<domain>shop.example.com</domain>', $q->body());
        self::assertSame(404, $app->handle(Request::create('GET', '/mail-setup/autoconfig', ['headers' => ['Host' => 'autoconfig.nobody.test']]))->status());

        $body = '<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006"><Request><EMailAddress>Info@Shop.Example.com</EMailAddress><AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>';
        $r = $app->handle(Request::create('POST', '/mail-setup/autodiscover', ['headers' => ['Host' => 'autodiscover.shop.example.com', 'Content-Type' => 'text/xml'], 'body' => $body]));
        self::assertSame(200, $r->status(), $r->body());
        self::assertStringContainsString('<LoginName>info@shop.example.com</LoginName>', $r->body());
        self::assertStringContainsString('<Type>IMAP</Type>', $r->body());
        self::assertStringContainsString('<Port>465</Port>', $r->body());
        self::assertNotFalse(\simplexml_load_string($r->body()));
        $sync = \str_replace('outlook/responseschema/2006a', 'mobilesync/responseschema/2006', $body);
        self::assertSame(404, $app->handle(Request::create('POST', '/mail-setup/autodiscover', ['headers' => ['Content-Type' => 'text/xml'], 'body' => $sync]))->status(), 'ActiveSync is not offered');
        $evil = '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Autodiscover><Request><EMailAddress>&e;</EMailAddress></Request></Autodiscover>';
        self::assertSame(404, $app->handle(Request::create('POST', '/mail-setup/autodiscover', ['headers' => ['Content-Type' => 'text/xml'], 'body' => $evil]))->status());

        // The setup page and the Apple profile.
        $owner = $this->owner();
        $id = (string) $this->box()['id'];
        $page = $owner->get('/panel/email/mailboxes/' . $id . '/setup');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('info@shop.example.com', $page->body());
        self::assertStringContainsString('993', $page->body());
        self::assertStringContainsString('https://panel.host.test:7444/webmail/', $page->body());
        $profile = $owner->get('/panel/email/mailboxes/' . $id . '/setup/profile');
        self::assertSame('application/x-apple-aspen-config', $profile->header('Content-Type'));
        self::assertStringContainsString('<string>com.apple.mail.managed</string>', $profile->body());
        self::assertStringContainsString('<key>IncomingMailServerHostName</key><string>panel.host.test</string>', $profile->body());
        self::assertNotFalse(\simplexml_load_string($profile->body()));
        self::assertSame($profile->body(), $owner->get('/panel/email/mailboxes/' . $id . '/setup/profile')->body(), 'the same profile each time');

        // Another account's mailbox is not found.
        $this->makeAccount('other', $this->package, null);
        $mine = $this->signedIn('user', 'other', 'other-owner-password');
        self::assertSame(404, $mine->get('/panel/email/mailboxes/' . $id . '/setup')->status());
    }

    public function test_webmail_opens_with_a_one_time_ticket(): void
    {
        $owner = $this->owner();
        $box = $this->box();
        $r = $owner->post('/panel/email/mailboxes/' . $box['id'] . '/webmail');
        self::assertSame(303, $r->status());
        $location = (string) $r->header('Location');
        self::assertMatchesRegularExpression('#^/webmail/\?_task=login&_lpanel=[0-9a-f]{64}$#', $location);
        $token = \substr($location, -64);
        $ticket = \json_decode((string) \file_get_contents($this->tickets . '/' . $token), true);
        self::assertSame('info@shop.example.com', $ticket['user']);
        self::assertSame($this->server->mailSso['info@shop.example.com']['password'], $ticket['password']);
        self::assertLessThanOrEqual(\time() + 60, $ticket['expires']);
        self::assertNotNull($this->box()['webmail_until']);
        self::assertContains('mail.webmail', \array_column($this->auditRows(), 'action'));
        self::assertStringNotContainsString($ticket['password'], \json_encode($this->auditRows(), \JSON_THROW_ON_ERROR), 'the password is not audited');

        // A new password ends the sign-on.
        $this->app->container()->get(MailActions::class)->setPassword(null, null, $this->account('shop'), $this->box(), 'another-password-2');
        self::assertContains('info@shop.example.com', $this->server->mailSsoRevoked);
        self::assertNull($this->box()['webmail_until']);

        // Expired ones are forgotten by mail:settle.
        $owner->post('/panel/email/mailboxes/' . $box['id'] . '/webmail');
        $this->db()->table('mail_boxes')->where('id', (int) $box['id'])->update(['webmail_until' => '2020-01-01 00:00:00']);
        $this->app->container()->get(\App\Modules\Mail\Webmail::class)->expire();
        self::assertContains('expired', $this->server->mailSsoRevoked);
        self::assertNull($this->box()['webmail_until']);

        // Not while suspended; not without webmail installed.
        $this->db()->table('accounts')->where('username', 'shop')->update(['status' => 'suspended']);
        $r = $owner->post('/panel/email/mailboxes/' . $box['id'] . '/webmail');
        self::assertSame('/panel/email', $r->header('Location'));
        $this->db()->table('accounts')->where('username', 'shop')->update(['status' => 'active']);
        $this->app->container()->get(Config::class)->set('Mail.webmail_tickets', $this->tickets . '/missing');
        self::assertSame('/panel/email', $owner->post('/panel/email/mailboxes/' . $box['id'] . '/webmail')->header('Location'));

        // The API and the CLI give a full address.
        $this->app->container()->get(Config::class)->set('Mail.webmail_tickets', $this->tickets);
        $token = $this->rootToken();
        $api = $this->json($this->api('POST', '/admin/api/v1/accounts/shop/email/mailboxes/info@shop.example.com/webmail', $token));
        self::assertStringStartsWith('https://panel.host.test:7444/webmail/?_task=login&_lpanel=', $api['data']['url']);
        self::assertSame(404, $this->api('POST', '/admin/api/v1/accounts/shop/email/mailboxes/nobody@shop.example.com/webmail', $token)->status());
        $setup = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/email/mailboxes/info@shop.example.com/setup', $token))['data'];
        self::assertSame(['panel.host.test', 993, 465], [$setup['server'], $setup['imap']['port'], $setup['smtp']['port']]);
    }

    public function test_the_deliverability_checks(): void
    {
        $domain = $this->mailDomain('shop.example.com');
        $this->server->mailCheck = ['domains' => ['shop.example.com' => [
            'spf' => 'v=spf1 ip4:203.0.113.9 -all', 'spf_result' => ['192.0.2.10' => 'fail'],
            'dkim' => (string) $domain['dkim'], 'dmarc' => '', 'mx' => [['host' => 'mx.elsewhere.test', 'pref' => 10, 'addresses' => ['203.0.113.9'], 'here' => false]],
        ]], 'ips' => ['192.0.2.10' => ['ptr' => ['other.name.test'], 'forward' => true, 'lists' => ['zen.spamhaus.org' => 'listed', 'bl.spamcop.net' => 'clear', 'b.barracudacentral.org' => 'unknown']]]];

        $page = $this->owner()->get('/panel/email/checks');
        self::assertSame(200, $page->status());
        $body = $page->body();
        self::assertStringContainsString('does not allow this server (192.0.2.10)', $body);
        self::assertStringContainsString('matches the one this server signs with', $body);
        self::assertStringContainsString('There is no DMARC record', $body);
        self::assertStringContainsString('goes to mx.elsewhere.test', $body);
        self::assertStringContainsString('hostname panel.host.test', $body);
        self::assertStringContainsString('The address is on zen.spamhaus.org', $body);
        self::assertSame(['shop.example.com'], $this->server->mailChecks[0]['domains']);
        self::assertSame('192.0.2.10', $this->server->mailChecks[0]['ip']);

        // A key that is not this server's.
        $this->server->mailCheck['domains']['shop.example.com']['dkim'] = 'v=DKIM1; k=rsa; p=SOMETHINGELSE';
        self::assertStringContainsString('is not this server&#039;s', $this->owner()->get('/panel/email/checks')->body());

        $checks = $this->app->container()->get(MailChecks::class)->run([$domain]);
        $states = \array_column($checks['domains'][0]['checks'], 'state', 'key');
        self::assertSame(['spf' => 'bad', 'dkim' => 'bad', 'dmarc' => 'bad', 'mx' => 'bad'], $states);

        // The Admin panel: the server, and any account by name.
        $root = $this->root();
        $admin = $root->get('/admin/email/checks');
        self::assertSame(200, $admin->status());
        self::assertStringContainsString('zen.spamhaus.org', $admin->body());
        self::assertStringContainsString('shop.example.com', $root->get('/admin/email/checks?account=shop')->body());
        self::assertStringContainsString('There is no account named nobody', $root->get('/admin/email/checks?account=nobody')->body());

        $token = $this->rootToken();
        $api = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/email/checks', $token))['data'];
        self::assertSame('bad', $api['domains'][0]['worst']);
        self::assertNotSame('', $api['domains'][0]['checks'][0]['message']);
        self::assertSame(200, $this->api('GET', '/admin/api/v1/email/checks', $token)->status());

        $this->server->down = true;
        self::assertStringContainsString('agent cannot be reached', $this->owner()->get('/panel/email/checks')->body());
    }

    // -------------------------------------------------------------------------

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    private function root(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    private function rootToken(): string
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;

        return $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
    }

    /** @return array<string, mixed> */
    private function mailDomain(string $name): array
    {
        return $this->db()->table('mail_domains')->where('name', $name)->first() ?? self::fail('no mail domain ' . $name);
    }

    /** @return array<string, mixed> */
    private function box(string $address = 'info@shop.example.com', string $account = 'shop'): array
    {
        return $this->app->container()->get(Mailboxes::class)->mailbox((int) $this->account($account)['id'], $address) ?? self::fail('no mailbox ' . $address);
    }

    /** @return list<string> the records the server has for a zone, as "name TYPE content" */
    private function records(string $zone): array
    {
        $out = [];

        foreach ($this->server->zones[$zone]['records'] ?? [] as $r) {
            $out[] = $r['name'] . ' ' . $r['type'] . ' ' . $r['content'];
        }

        return $out;
    }
}
