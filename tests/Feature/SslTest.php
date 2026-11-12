<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\SslSites;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class SslTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private int $package;

    private int $now;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->package = $this->packageFor(null, 'Starter', 1024, ['domains', 'ssl']);
        $this->makeAccount('shop', $this->package, null);
        $this->now = \time();
        $this->ssl()->setClock(fn(): int => $this->now);
    }

    public function test_autossl_gets_a_new_site_a_certificate_and_serves_it_over_https(): void
    {
        $this->server->pointing = ['shop.example.com'];

        $this->autossl();

        $tls = $this->tls('shop.example.com');
        $row = $this->site('shop.example.com');
        self::assertNotNull($tls, 'the site serves its certificate');
        self::assertSame(['cert' => $row['cert_id'], 'force' => true], $tls, 'and sends HTTP to HTTPS by default');
        self::assertSame([SslSites::AUTO, 'letsencrypt', ['shop.example.com']], [$row['source'], $row['authority'], $row['names']]);
        self::assertSame('www.shop.example.com', $row['excluded'][0]['name'], 'www. does not point here yet: left out, and said why');
        self::assertSame(1, $this->server->ran('ssl.issue'));

        // Nothing to do while the certificate is fresh, and www. was checked today.
        $this->autossl();
        self::assertSame(1, $this->server->ran('ssl.issue'));

        // www. is checked again a day later; now it points here.
        $this->server->pointing[] = 'www.shop.example.com';
        $this->now += 25 * 3600;
        $this->autossl();
        $row = $this->site('shop.example.com');
        self::assertSame(['shop.example.com', 'www.shop.example.com'], $row['names']);
        self::assertSame([], $row['excluded']);

        $issued = \array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'ssl.issue' && $r['outcome'] === 'ok');
        self::assertCount(2, $issued);
    }

    public function test_certificates_are_renewed_in_their_last_third(): void
    {
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $this->autossl();
        $first = $this->site('shop.example.com')['cert_id'];

        $this->now += 55 * 86400;
        $this->autossl();
        self::assertSame($first, $this->site('shop.example.com')['cert_id'], '35 days left: not yet');

        $this->now += 6 * 86400;
        $this->autossl();
        $second = $this->site('shop.example.com')['cert_id'];
        self::assertNotSame($first, $second, '29 days left: renewed');
        self::assertSame($second, $this->tls('shop.example.com')['cert'] ?? null, 'and served');

        // A short-lived certificate is renewed once two thirds have passed.
        $this->server->validDays = 6;
        $this->now += 61 * 86400;
        $this->autossl();
        $short = $this->site('shop.example.com');
        $this->now = (int) \strtotime($short['not_before'] . ' UTC') + 3 * 86400;
        self::assertNull($this->ssl()->dueReason($short, ['shop.example.com', 'www.shop.example.com']));
        $this->now += 2 * 86400;
        self::assertSame('expiring', $this->ssl()->dueReason($short, ['shop.example.com', 'www.shop.example.com']));
    }

    public function test_failures_wait_longer_each_time(): void
    {
        $this->autossl();
        $row = $this->site('shop.example.com');
        self::assertNull($row['cert_id']);
        self::assertSame(1, $row['failures']);
        self::assertStringContainsString('no DNS address', (string) $row['last_error']);
        self::assertSame($this->now + 3600, (int) \strtotime($row['retry_at'] . ' UTC'));
        self::assertNull($this->tls('shop.example.com'));

        $this->autossl();
        self::assertSame(1, $this->server->ran('ssl.issue'), 'not before the hour is up');

        $this->now += 3601;
        $this->autossl();
        self::assertSame(2, $this->server->ran('ssl.issue'));
        self::assertSame($this->now + 7200, (int) \strtotime($this->site('shop.example.com')['retry_at'] . ' UTC'), 'then two hours');

        for ($i = 0; $i < 6; $i++) {
            $this->now = (int) \strtotime($this->site('shop.example.com')['retry_at'] . ' UTC') + 1;
            $this->autossl();
        }

        self::assertSame($this->now + 86400, (int) \strtotime($this->site('shop.example.com')['retry_at'] . ' UTC'), 'never more than a day');

        // Pointed now: the owner need not wait for the next try.
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $owner->post('/panel/ssl/' . $this->domainId('shop.example.com') . '/issue');
        $this->ssl()->settleAll();
        $row = $this->site('shop.example.com');
        self::assertNotNull($row['cert_id']);
        self::assertSame([0, null, null], [$row['failures'], $row['retry_at'], $row['last_error']]);
    }

    public function test_zerossl_is_asked_when_lets_encrypt_does_not_issue(): void
    {
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $this->server->authorityDown = ['letsencrypt'];

        // Without its EAB credentials ZeroSSL cannot be used.
        $this->autossl();
        self::assertNull($this->site('shop.example.com')['cert_id']);
        self::assertSame(['letsencrypt'], $this->ssl()->authorities());

        $this->root()->post('/admin/ssl/settings', ['enabled' => '1', 'fallback' => '1', 'primary' => 'letsencrypt', 'eab_kid' => 'kid_123', 'eab_hmac' => \str_repeat('h', 43), 'host_autossl' => '1']);
        self::assertSame(['letsencrypt', 'zerossl'], $this->ssl()->authorities());
        $this->now += 3601;
        $this->autossl();

        $row = $this->site('shop.example.com');
        self::assertSame('zerossl', $row['authority']);
        self::assertStringContainsString('ZeroSSL', (string) $row['issuer']);
        $args = \json_decode((string) $this->lastOrder()['op_args'], true);
        self::assertSame(['kid' => 'kid_123', 'hmac' => \str_repeat('h', 43)], $args['zerossl_eab']);

        // A failed check is not retried at the other authority.
        $this->makeAccount('blog', $this->package, null);
        $this->server->pointing[] = 'blog.example.com';
        $this->server->authorityDown = [];
        $this->server->checkFails = ['blog.example.com'];
        $this->autossl();
        $order = $this->lastOrder();
        self::assertStringContainsString('letsencrypt: blog.example.com did not pass its check', (string) $order['error']);
        self::assertStringNotContainsString('zerossl', (string) $order['error']);
    }

    public function test_the_owner_brings_a_certificate_and_chooses_https(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $id = $this->domainId('shop.example.com');
        $cert = "-----BEGIN CERTIFICATE-----\nnames: shop.example.com,www.shop.example.com\n-----END CERTIFICATE-----\n";

        // Another site's certificate: refused.
        $page = $owner->post('/panel/ssl/install', ['domain' => (string) $id, 'certificate' => "-----BEGIN CERTIFICATE-----\nnames: other.test\n-----END CERTIFICATE-----\n", 'key' => 'SECRET-42 PRIVATE KEY'])->body();
        self::assertStringContainsString('does not cover this site', $page);
        self::assertStringNotContainsString('SECRET-42', $page, 'the key is never sent back');

        // Without a key, and no request made here: refused.
        self::assertStringContainsString('private key is needed', $owner->post('/panel/ssl/install', ['domain' => (string) $id, 'certificate' => $cert])->body());

        // A request made here: the certificate comes back without its key.
        $page = $owner->post('/panel/ssl/csr', ['domain' => (string) $id, 'country' => 'bd', 'key_type' => 'rsa'])->body();
        self::assertStringContainsString('BEGIN CERTIFICATE REQUEST', $page);
        self::assertSame(303, $owner->post('/panel/ssl/install', ['domain' => (string) $id, 'certificate' => $cert])->status());
        $row = $this->site('shop.example.com');
        self::assertSame([SslSites::CUSTOM, 'Commercial CA'], [$row['source'], $row['issuer']]);
        self::assertSame(['cert' => $row['cert_id'], 'force' => true], $this->tls('shop.example.com'));

        // AutoSSL leaves a valid certificate the owner brought.
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $this->autossl();
        self::assertSame(0, $this->server->ran('ssl.issue'));

        // Plain HTTP stays plain HTTP when the owner says so.
        $owner->post('/panel/ssl/' . $id . '/options', ['field' => 'force', 'value' => '0']);
        self::assertFalse($this->tls('shop.example.com')['force'] ?? null);

        $page = $owner->get('/panel/ssl')->body();
        self::assertStringContainsString('Commercial CA', $page);
        self::assertStringContainsString('aria-checked="false"', $page);

        // Removed: plain HTTP only; AutoSSL may now get one.
        $owner->post('/panel/ssl/' . $id . '/remove');
        self::assertNull($this->tls('shop.example.com'));
        self::assertTrue((bool) $this->site('shop.example.com')['autossl']);
        $this->autossl();
        self::assertSame(SslSites::AUTO, $this->site('shop.example.com')['source']);

        // Removing AutoSSL's turns AutoSSL off for the site, or it would be back.
        $owner->post('/panel/ssl/' . $id . '/remove');
        self::assertFalse((bool) $this->site('shop.example.com')['autossl']);
        $this->autossl();
        self::assertSame(1, $this->server->ran('ssl.issue'));
        self::assertSame(['ssl.csr', 'ssl.install', 'ssl.options', 'ssl.remove', 'ssl.remove'], \array_values(\array_map(
            static fn(array $r): string => $r['action'],
            \array_filter($this->auditRows(), static fn(array $r): bool => \in_array($r['action'], ['ssl.install', 'ssl.csr', 'ssl.options', 'ssl.remove'], true) && $r['outcome'] === 'ok'),
        )));
    }

    public function test_an_expired_certificate_is_not_served(): void
    {
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $this->server->issuedAt = \time() - 100 * 86400;
        $this->autossl();
        $sync = $this->app->container()->get(\App\Modules\Web\WebSync::class);
        $sync->touch((int) $this->account('shop')['id']);

        self::assertNull($this->tls('shop.example.com'), 'an expired certificate would only stop visitors');
    }

    public function test_the_hostname_gets_a_certificate_too(): void
    {
        $this->server->pointing = ['server.example.net'];
        $admin = $this->root();
        $admin->post('/admin/ssl/settings', ['enabled' => '1', 'fallback' => '1', 'primary' => 'letsencrypt', 'hostname' => 'Server.Example.NET', 'host_autossl' => '1']);
        self::assertSame('server.example.net', $this->ssl()->hostname());
        self::assertSame('self_signed', $this->ssl()->hostDue());

        $this->autossl();
        self::assertSame(['server.example.net'], $this->server->hostCert['names'] ?? null);
        self::assertNull($this->ssl()->hostDue());

        $page = $admin->get('/admin/ssl')->body();
        self::assertStringContainsString('server.example.net', $page);
        self::assertStringContainsString('Encrypt E7', $page);

        // Installed by hand.
        $admin->post('/admin/ssl/hostname/install', ['certificate' => "-----BEGIN CERTIFICATE-----\nnames: server.example.net\n-----END CERTIFICATE-----\n", 'key' => '-----BEGIN PRIVATE KEY-----']);
        self::assertSame('Commercial CA', $this->server->hostCert['issuer']);
        self::assertStringContainsString('Paste both', $admin->post('/admin/ssl/hostname/install', ['certificate' => 'x'])->body());
    }

    public function test_settings_are_checked(): void
    {
        $page = $this->root()->post('/admin/ssl/settings', ['enabled' => '1', 'primary' => 'zerossl', 'eab_kid' => '', 'hostname' => 'not a host'])->body();
        self::assertStringContainsString('ZeroSSL needs its EAB', $page);
        self::assertStringContainsString('full host name', $page);
        self::assertSame(AutoSsl::LETSENCRYPT, $this->ssl()->settings()['primary'], 'nothing was saved');

        // Resellers and owners cannot reach the server's settings.
        $this->app->container()->get(PanelUsers::class)->create(Kind::Reseller, 'rita', 'Rita', null, new Secret('rita-password-123'));
        self::assertSame(403, $this->signedIn('admin', 'rita', 'rita-password-123')->get('/admin/ssl')->status());
    }

    public function test_the_api_and_the_owner_page(): void
    {
        $this->server->pointing = ['shop.example.com', 'www.shop.example.com'];
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];

        // Recorded while the agent cannot be reached; a second order waits for it.
        $this->server->down = true;
        self::assertSame(202, $this->api('POST', '/admin/api/v1/accounts/shop/ssl/shop.example.com/issue', $token)->status());
        self::assertSame(409, $this->api('POST', '/admin/api/v1/accounts/shop/ssl/shop.example.com/issue', $token)->status(), 'one order at a time');
        $this->server->down = false;
        $order = (int) $this->lastOrder()['id'];
        $this->ssl()->settleAll();
        self::assertSame('done', $this->json($this->api('GET', '/admin/api/v1/ssl/orders/' . $order, $token))['data']['state']);

        $list = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/ssl', $token))['data'];
        self::assertSame('shop.example.com', $list[0]['domain']);
        self::assertSame(['shop.example.com', 'www.shop.example.com'], $list[0]['certificate']['names']);
        self::assertTrue($list[0]['force_https']);

        $r = $this->api('PUT', '/admin/api/v1/accounts/shop/ssl/shop.example.com', $token, ['force_https' => false]);
        self::assertSame(200, $r->status());
        self::assertFalse($this->json($r)['data']['force_https']);
        self::assertFalse($this->tls('shop.example.com')['force'] ?? null);

        self::assertSame(422, $this->api('PUT', '/admin/api/v1/accounts/shop/ssl/shop.example.com/certificate', $token, ['certificate' => 'nonsense'])->status());
        self::assertStringContainsString('CERTIFICATE REQUEST', $this->json($this->api('POST', '/admin/api/v1/accounts/shop/ssl/shop.example.com/csr', $token, ['country' => 'US']))['data']['csr']);
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/nobody/ssl', $token)->status());

        $r = $this->api('PUT', '/admin/api/v1/ssl', $token, ['hostname' => 'host.example.net', 'autossl' => true]);
        self::assertSame('host.example.net', $this->json($r)['data']['hostname']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/ssl', $token, ['primary' => 'nobody'])->status());

        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $page = $owner->get('/panel/ssl')->body();
        self::assertStringContainsString('Secure', $page);
        self::assertStringContainsString('Encrypt E7', $page);
        self::assertStringContainsString('www.shop.example.com', $page);
    }

    // -------------------------------------------------------------------------

    private function ssl(): AutoSsl
    {
        return $this->app->container()->get(AutoSsl::class);
    }

    /** One AutoSSL run, and the agent's answers to what it ordered. */
    private function autossl(): void
    {
        $this->ssl()->run();
        $this->ssl()->settleAll();
    }

    private function root(): \App\Tests\Support\Browser
    {
        $this->rootPassword($this->app, 'root-password-123');

        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    /** @return array<string, mixed> the newest order */
    private function lastOrder(): array
    {
        return $this->db()->table('ssl_orders')->orderBy('id', 'desc')->first() ?? self::fail('no order was recorded');
    }

    private function domainId(string $name): int
    {
        return (int) ($this->db()->table('domains')->where('name', $name)->first()['id'] ?? 0);
    }

    /** @return array<string, mixed> */
    private function site(string $name): array
    {
        return $this->app->container()->get(SslSites::class)->forDomain($this->domainId($name)) ?? [];
    }

    /** @return array<string, mixed>|null what the server was last told for the site's HTTPS */
    private function tls(string $name): ?array
    {
        foreach ($this->server->sites['shop']['sites'] ?? [] as $site) {
            if ($site['name'] === $name) {
                return $site['tls'] ?? null;
            }
        }

        return null;
    }
}
