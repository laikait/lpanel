<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Provisioner;
use App\Modules\Dns\DnsActions;
use App\Modules\Dns\DnsRecords;
use App\Modules\Dns\DnsSync;
use App\Modules\Dns\DnsZones;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Resellers\Resellers;
use App\Modules\Web\AutoSsl;
use App\Modules\Web\DomainActions;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class DnsTest extends TestCase
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
        $actions = $this->app->container()->get(DnsActions::class);
        self::assertSame([], $actions->saveAddresses(null, null, ['ip4' => '192.0.2.10', 'ip6' => '2001:DB8::10']));
        self::assertSame([], $actions->saveProfile(null, null, DnsZones::SERVER, 'ns1.host.test, ns2.host.test', null));
        $this->package = $this->packageFor(null, 'Starter', 1024, ['domains', 'dns', 'ssl']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_a_new_account_gets_its_zone_from_the_template(): void
    {
        $zone = $this->server->zones['shop.example.com'] ?? self::fail('no zone was sent');
        self::assertSame('shop', $zone['username']);
        self::assertSame(['primary' => 'ns1.host.test', 'mail' => 'hostmaster.shop.example.com'], \array_intersect_key($zone['soa'], ['primary' => 1, 'mail' => 1]));

        $records = $this->sent('shop.example.com');
        foreach ([
            'shop.example.com NS ns1.host.test', 'shop.example.com NS ns2.host.test',
            'shop.example.com A 192.0.2.10', 'shop.example.com AAAA 2001:db8::10',
            'www.shop.example.com A 192.0.2.10', 'mail.shop.example.com A 192.0.2.10',
            'shop.example.com MX 10 mail.shop.example.com', 'shop.example.com TXT v=spf1 a mx ~all',
        ] as $want) {
            self::assertContains($want, $records);
        }

        self::assertSame(DnsSync::APPLIED, $this->zone('shop.example.com')['state']);
    }

    public function test_domains_bring_zones_and_records_and_take_them_away(): void
    {
        $domains = $this->app->container()->get(DomainActions::class);
        $account = $this->account('shop');

        $domains->add(null, null, $account, ['name' => 'other.example.com', 'kind' => 'addon']);
        self::assertArrayHasKey('other.example.com', $this->server->zones, 'an addon domain has a zone of its own');

        $domains->add(null, null, $account, ['name' => 'blog.shop.example.com', 'kind' => 'sub']);
        self::assertArrayNotHasKey('blog.shop.example.com', $this->server->zones, 'a subdomain has none');
        self::assertContains('blog.shop.example.com A 192.0.2.10', $this->sent('shop.example.com'), 'its address goes in the zone above it');

        $main = $this->db()->table('domains')->where('name', 'shop.example.com')->first() ?? self::fail('no main domain');
        $domains->change(null, null, $account, $main, ['wildcard' => true]);
        self::assertContains('*.shop.example.com A 192.0.2.10', $this->sent('shop.example.com'));
        $domains->change(null, null, $account, $this->db()->table('domains')->where('id', $main['id'])->first() ?? $main, ['wildcard' => false]);
        self::assertNotContains('*.shop.example.com A 192.0.2.10', $this->sent('shop.example.com'));

        // A record the owner removed is not made again by a later change.
        $zone = $this->zone('shop.example.com');
        $www = $this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', 'www')->where('type', 'A')->first() ?? self::fail('no www');
        $this->app->container()->get(DnsActions::class)->deleteRecord(null, null, $zone, $www);
        $domains->add(null, null, $account, ['name' => 'more.shop.example.com', 'kind' => 'sub']);
        self::assertNotContains('www.shop.example.com A 192.0.2.10', $this->sent('shop.example.com'));

        $sub = $this->db()->table('domains')->where('name', 'blog.shop.example.com')->first() ?? self::fail('no sub');
        $domains->remove(null, null, $account, $sub);
        self::assertNotContains('blog.shop.example.com A 192.0.2.10', $this->sent('shop.example.com'));

        $addon = $this->db()->table('domains')->where('name', 'other.example.com')->first() ?? self::fail('no addon');
        $domains->remove(null, null, $account, $addon);
        self::assertArrayNotHasKey('other.example.com', $this->server->zones, 'the zone goes with its domain');
        self::assertNull($this->db()->table('dns_zones')->where('name', 'other.example.com')->first());
    }

    public function test_the_owner_edits_records_and_mistakes_are_explained(): void
    {
        $owner = $this->owner();
        $zone = $this->zone('shop.example.com');
        $base = '/panel/dns/' . $zone['id'];

        self::assertSame(303, $owner->get('/panel/dns')->status(), 'one zone: straight to it');
        $page = $owner->get($base)->body();
        self::assertStringContainsString('ns1.host.test', $page);
        self::assertStringContainsString('v=spf1 a mx ~all', $page);

        $owner->post($base . '/records', ['name' => 'api', 'type' => 'A', 'content' => '192.0.2.44', 'ttl' => '300']);
        self::assertContains('api.shop.example.com A 192.0.2.44', $this->sent('shop.example.com'));

        $owner->post($base . '/records', ['name' => '_dmarc', 'type' => 'TXT', 'content' => '"v=DMARC1; p=none"', 'ttl' => '3600']);
        self::assertContains('_dmarc.shop.example.com TXT v=DMARC1; p=none', $this->sent('shop.example.com'), 'pasted quotes are taken off');

        $owner->post($base . '/records', ['name' => 'shop', 'type' => 'CNAME', 'content' => 'Stores.Example.NET.', 'ttl' => '3600']);
        self::assertContains('shop.shop.example.com CNAME stores.example.net', $this->sent('shop.example.com'));

        foreach ([
            [['name' => 'x', 'type' => 'A', 'content' => '999.1.1.1'], 'not an IPv4 address'],
            [['name' => 'x', 'type' => 'AAAA', 'content' => '192.0.2.1'], 'not an IPv6 address'],
            [['name' => '', 'type' => 'CNAME', 'content' => 'a.example.net'], 'cannot be a CNAME'],
            [['name' => '@', 'type' => 'NS', 'content' => 'ns.evil.test'], 'set by your host'],
            [['name' => 'api', 'type' => 'CNAME', 'content' => 'a.example.net'], 'can hold no other record'],
            [['name' => 'x', 'type' => 'MX', 'content' => 'mail.example.net'], 'priority'],
            [['name' => 'x', 'type' => 'CNAME', 'content' => 'localhost'], 'full host name'],
            [['name' => 'bad name', 'type' => 'A', 'content' => '192.0.2.1'], 'Use letters'],
            [['name' => 'x.other.example.org', 'type' => 'PTR', 'content' => 'a.example.net'], 'record types offered'],
            [['name' => 'x', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => '5'], '60 to 604800'],
            [['name' => 'api', 'type' => 'A', 'content' => '192.0.2.44'], 'there already'],
        ] as [$input, $why]) {
            $r = $owner->post($base . '/records', $input);
            self::assertSame(422, $r->status(), \json_encode($input, \JSON_THROW_ON_ERROR));
            self::assertStringContainsString($why, $r->body());
        }

        $api = $this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', 'api')->first() ?? self::fail('no record');
        $owner->post($base . '/records/' . $api['id'], ['name' => 'api', 'content' => '192.0.2.45', 'ttl' => '600']);
        self::assertContains('api.shop.example.com A 192.0.2.45', $this->sent('shop.example.com'));
        self::assertSame(422, $owner->post($base . '/records/' . $api['id'], ['name' => 'api', 'content' => 'nonsense', 'ttl' => '600'])->status());

        $owner->post($base . '/records/' . $api['id'] . '/delete');
        self::assertNotContains('api.shop.example.com A 192.0.2.45', $this->sent('shop.example.com'));

        $owner->post($base . '/reset');
        $records = $this->sent('shop.example.com');
        self::assertNotContains('_dmarc.shop.example.com TXT v=DMARC1; p=none', $records);
        self::assertContains('www.shop.example.com A 192.0.2.10', $records);

        // Another account's zone is not this owner's.
        $this->makeAccount('other', $this->package, null);
        self::assertSame(404, $owner->get('/panel/dns/' . $this->zone('other.example.com')['id'])->status());

        $actions = \array_column($this->auditRows(), 'action');
        foreach (['dns.record_add', 'dns.record_change', 'dns.record_remove', 'dns.zone_reset'] as $a) {
            self::assertContains($a, $actions);
        }
    }

    public function test_a_change_the_server_refuses_is_undone_and_one_it_cannot_take_waits(): void
    {
        $zone = $this->zone('shop.example.com');
        $actions = $this->app->container()->get(DnsActions::class);
        $before = $this->sent('shop.example.com');

        $this->server->failNext = ['op' => 'dns.apply', 'code' => 'dns_rejected', 'message' => 'PowerDNS refused it'];
        [$outcome] = $actions->addRecord(null, null, $zone, ['name' => 'x', 'type' => 'A', 'content' => '192.0.2.9']);
        self::assertSame('failed', $outcome->status);
        self::assertNull($this->db()->table('dns_records')->where('zone_id', $zone['id'])->where('name', 'x')->first());
        self::assertSame($before, $this->sent('shop.example.com'));
        self::assertSame(DnsSync::APPLIED, $this->zone('shop.example.com')['state']);

        $this->server->down = true;
        [$outcome] = $actions->addRecord(null, null, $zone, ['name' => 'y', 'type' => 'A', 'content' => '192.0.2.9']);
        self::assertSame('pending', $outcome->status);
        $this->server->down = false;
        $this->app->container()->get(DnsSync::class)->settleAll();
        self::assertContains('y.shop.example.com A 192.0.2.9', $this->sent('shop.example.com'));
        self::assertSame(DnsSync::APPLIED, $this->zone('shop.example.com')['state']);
    }

    public function test_nameservers_change_every_zone_and_a_reseller_has_their_own(): void
    {
        $rex = (int) $this->app->container()->get(Resellers::class)->create('rex', 'Rex', null, new Secret('rex-password-1234'), [
            'max_accounts' => 0, 'disk_mb' => 0, 'bandwidth_mb' => 0, 'oversell' => true, 'acl' => Resellers::ACL,
        ]);
        $mine = $this->packageFor($rex, 'Mine', 1024, ['domains', 'dns']);
        $this->makeAccount('client', $mine, $rex);
        self::assertContains('client.example.com NS ns1.host.test', $this->sent('client.example.com'), 'the host\'s, until the reseller sets their own');

        $reseller = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $page = $reseller->get('/admin/dns')->body();
        self::assertStringContainsString('ns1.host.test', $page);
        self::assertStringNotContainsString('Server addresses', $page, 'a reseller sees their own page, not the server\'s');
        self::assertSame(422, $reseller->post('/admin/dns/profile', ['what' => 'nameservers', 'nameservers' => 'ns1.rex.test'])->status(), 'one is not enough');

        $this->server->resolves = ['ns1.rex.test' => ['192.0.2.10'], 'ns2.rex.test' => ['198.51.100.7']];
        $reseller->post('/admin/dns/profile', ['what' => 'nameservers', 'nameservers' => "ns1.rex.test\nns2.rex.test"]);
        $records = $this->sent('client.example.com');
        self::assertContains('client.example.com NS ns1.rex.test', $records);
        self::assertNotContains('client.example.com NS ns1.host.test', $records);
        self::assertSame('ns1.rex.test', $this->server->zones['client.example.com']['soa']['primary']);
        self::assertContains('shop.example.com NS ns1.host.test', $this->sent('shop.example.com'), 'other accounts keep the host\'s');

        $page = $reseller->get('/admin/dns')->body();
        self::assertStringContainsString('Leads here', $page);
        self::assertStringContainsString('Leads elsewhere', $page);
        self::assertStringContainsString('192.0.2.10', $page, 'the address to register as glue');

        // The reseller's own template, for their accounts' new zones only.
        self::assertSame(422, $reseller->post('/admin/dns/profile', ['what' => 'template', 'template' => "@ 3600 A {ip}\nnot a record"])->status());
        $reseller->post('/admin/dns/profile', ['what' => 'template', 'template' => "@ 3600 A {ip}\nstatus 300 CNAME status.rex.test\n"]);
        $this->makeAccount('second', $mine, $rex);
        self::assertContains('status.second.example.com CNAME status.rex.test', $this->sent('second.example.com'));
        self::assertNotContains('mail.second.example.com A 192.0.2.10', $this->sent('second.example.com'));

        // A reseller cannot reach the server's settings or other zones.
        self::assertNotSame(200, $reseller->post('/admin/dns/addresses', ['ip4' => '203.0.113.1'])->status());
        self::assertSame('192.0.2.10', $this->app->container()->get(DnsZones::class)->addresses()['ip4']);
        self::assertNotSame(200, $reseller->get('/admin/dns/zones/' . $this->zone('shop.example.com')['id'])->status());

        // The host changes its nameservers: every zone without a reseller's follows.
        $this->app->container()->get(DnsActions::class)->saveProfile(null, null, DnsZones::SERVER, ['ns1.new.test', 'ns2.new.test'], null);
        self::assertContains('shop.example.com NS ns1.new.test', $this->sent('shop.example.com'));
        self::assertContains('client.example.com NS ns1.rex.test', $this->sent('client.example.com'));
    }

    public function test_the_administrator_has_server_zones_glue_and_every_zone(): void
    {
        $this->rootPassword($this->app, 'root-password-123');
        $root = $this->signedIn('admin', 'root', 'root-password-123');

        $page = $root->get('/admin/dns')->body();
        self::assertStringContainsString('4.9.4', $page);
        self::assertStringContainsString('shop.example.com', $page);
        self::assertStringContainsString('Server addresses', $page);

        // The nameservers' own domain, as a zone of the server's: glue is made.
        $r = $root->post('/admin/dns/zones', ['name' => 'host.test']);
        self::assertSame(303, $r->status());
        $records = $this->sent('host.test');
        self::assertSame('', $this->server->zones['host.test']['username']);
        self::assertContains('ns1.host.test A 192.0.2.10', $records);
        self::assertContains('ns2.host.test AAAA 2001:db8::10', $records);

        self::assertSame(422, $root->post('/admin/dns/zones', ['name' => 'shop.example.com'])->status(), 'a name an account holds');
        self::assertSame(422, $root->post('/admin/dns/zones', ['name' => 'not a zone'])->status());

        // A reverse zone holds PTR records, which only the administrator may add.
        $root->post('/admin/dns/zones', ['name' => '2.0.192.in-addr.arpa']);
        $reverse = $this->zone('2.0.192.in-addr.arpa');
        $root->post('/admin/dns/zones/' . $reverse['id'] . '/records', ['name' => '10', 'type' => 'PTR', 'content' => 'server.host.test']);
        self::assertSame(['2.0.192.in-addr.arpa NS ns1.host.test', '2.0.192.in-addr.arpa NS ns2.host.test', '10.2.0.192.in-addr.arpa PTR server.host.test'], $this->sent('2.0.192.in-addr.arpa'));

        // Any account's zone can be edited from here.
        $shop = $this->zone('shop.example.com');
        self::assertStringContainsString('v=spf1', $root->get('/admin/dns/zones/' . $shop['id'])->body());
        $root->post('/admin/dns/zones/' . $shop['id'] . '/records', ['name' => 'fix', 'type' => 'A', 'content' => '192.0.2.77']);
        self::assertContains('fix.shop.example.com A 192.0.2.77', $this->sent('shop.example.com'));

        $root->post('/admin/dns/zones/' . $shop['id'] . '/remove');
        self::assertArrayHasKey('shop.example.com', $this->server->zones, 'an account\'s zone goes only with its domain');
        $root->post('/admin/dns/zones/' . $reverse['id'] . '/remove');
        self::assertArrayNotHasKey('2.0.192.in-addr.arpa', $this->server->zones);

        self::assertStringContainsString('shop.example.com', $root->get('/admin/accounts/' . $this->account('shop')['id'])->body());
        self::assertNotSame(200, $this->owner()->get('/admin/dns')->status());
    }

    public function test_delegation_is_shown_and_termination_removes_the_zones(): void
    {
        $owner = $this->owner();
        $base = '/panel/dns/' . $this->zone('shop.example.com')['id'];
        self::assertStringContainsString('No nameservers are set', $owner->get($base)->body());

        $this->server->delegated['shop.example.com'] = ['ns1.registrar.test', 'ns2.registrar.test'];
        self::assertStringContainsString('uses other nameservers', $owner->get($base)->body());

        $this->server->delegated['shop.example.com'] = ['ns1.host.test', 'ns2.host.test'];
        $page = $owner->get($base)->body();
        self::assertStringContainsString('In use', $page);
        self::assertStringNotContainsString('uses other nameservers', $page);

        // A removal the agent misses waits, and is finished later.
        $account = $this->account('shop');
        $domains = $this->app->container()->get(DomainActions::class);
        $domains->add(null, null, $account, ['name' => 'gone.example.com', 'kind' => 'addon']);
        $addon = $this->db()->table('domains')->where('name', 'gone.example.com')->first() ?? self::fail('no addon');
        $this->app->container()->get(\App\Modules\Web\Domains::class)->delete((int) $addon['id']);
        $this->server->down = true;
        $this->app->container()->get(DnsSync::class)->reconcile((int) $account['id']);
        self::assertSame(DnsSync::REMOVING, $this->zone('gone.example.com')['state']);
        $this->server->down = false;
        $this->app->container()->get(DnsSync::class)->settleAll();
        self::assertArrayNotHasKey('gone.example.com', $this->server->zones);
        self::assertNull($this->db()->table('dns_zones')->where('name', 'gone.example.com')->first());

        $provisioner = $this->app->container()->get(Provisioner::class);
        $provisioner->terminate($this->app->container()->get(PanelUsers::class)->root()->identity, null, (int) $account['id']);
        $provisioner->settleAll();
        self::assertSame([], $this->db()->table('dns_zones')->get());
        self::assertSame([], $this->db()->table('dns_records')->get());
        self::assertSame([], $this->server->zones);
    }

    public function test_autossl_asks_for_a_wildcard_when_the_zone_is_here(): void
    {
        $account = $this->account('shop');
        $main = $this->db()->table('domains')->where('name', 'shop.example.com')->first() ?? self::fail('no main domain');
        $this->app->container()->get(DomainActions::class)->change(null, null, $account, $main, ['wildcard' => true]);

        $ssl = $this->app->container()->get(AutoSsl::class);
        $names = $ssl->namesOf($this->account('shop'))[(int) $main['id']];
        self::assertSame(['shop.example.com', 'www.shop.example.com', '*.shop.example.com'], $names);

        $this->server->pointing = ['shop.example.com', 'www.shop.example.com', '*.shop.example.com'];
        $ssl->run();
        $ssl->settleAll();
        $site = $this->db()->table('ssl_sites')->where('domain_id', $main['id'])->first() ?? self::fail('no certificate');
        self::assertContains('*.shop.example.com', (array) \json_decode((string) $site['names'], true));
    }

    public function test_the_template_rules(): void
    {
        [$rows, $why] = DnsRecords::parseTemplate("# a comment\n@ 3600 A {ip}\n\nwww 300 CNAME {domain}\n@ 3600 MX 10 mail.{domain}\n");
        self::assertNull($why);
        self::assertCount(3, $rows);

        foreach (['@ A {ip}', '@ 3600 A {nope}', '@ 3600 NS ns1.example.com', '@ 3600 CNAME {domain}', '@ 5 A {ip}', '@ 3600 BOGUS x'] as $bad) {
            self::assertNotNull(DnsRecords::parseTemplate("@ 3600 A {ip}\n" . $bad)[1], $bad);
        }

        self::assertSame('Dns.error_template_line|2', DnsRecords::parseTemplate("@ 3600 A {ip}\nbroken")[1]);

        // A line whose address is not set is left out.
        $records = DnsRecords::expand(DnsRecords::DEFAULT_TEMPLATE, 'a.test', '192.0.2.1', '');
        self::assertSame(['A', 'A', 'A', 'MX', 'TXT'], \array_column($records, 'type'));
        self::assertSame('10 mail.a.test', $records[3]['content']);
    }

    public function test_the_api(): void
    {
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        $this->server->delegated['shop.example.com'] = ['ns1.host.test'];

        $zones = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/dns', $token))['data'];
        self::assertSame('shop.example.com', $zones[0]['name']);

        $zone = $this->json($this->api('GET', '/admin/api/v1/accounts/shop/dns/shop.example.com', $token))['data'];
        self::assertSame(['ns1.host.test', 'ns2.host.test'], $zone['nameservers']);
        self::assertSame('ok', $zone['delegation']['state']);

        $r = $this->api('POST', '/admin/api/v1/accounts/shop/dns/shop.example.com/records', $token, ['name' => 'api', 'type' => 'a', 'content' => '192.0.2.50', 'ttl' => 300]);
        self::assertSame(201, $r->status());
        $added = \array_values(\array_filter($this->json($r)['data']['records'], static fn(array $x): bool => $x['name'] === 'api'))[0];
        self::assertSame(['api.shop.example.com', 'A', 300, '192.0.2.50'], [$added['full_name'], $added['type'], $added['ttl'], $added['content']]);

        $r = $this->api('POST', '/admin/api/v1/accounts/shop/dns/shop.example.com/records', $token, ['name' => 'api', 'type' => 'A', 'content' => 'nope']);
        self::assertSame(422, $r->status());
        self::assertArrayHasKey('content', $this->json($r)['errors']);
        self::assertSame(422, $this->api('POST', '/admin/api/v1/accounts/shop/dns/shop.example.com/records', $token, ['name' => '9', 'type' => 'PTR', 'content' => 'a.example.net'])->status(), 'PTR is not for an account\'s API');

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/accounts/shop/dns/shop.example.com/records/' . $added['id'], $token, ['content' => '192.0.2.51'])->status());
        self::assertContains('api.shop.example.com A 192.0.2.51', $this->sent('shop.example.com'));
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/accounts/shop/dns/shop.example.com/records/' . $added['id'], $token)->status());
        self::assertSame(200, $this->api('POST', '/admin/api/v1/accounts/shop/dns/shop.example.com/reset', $token)->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/shop/dns/other.example.com', $token)->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/accounts/nobody/dns', $token)->status());

        // The server's side.
        $settings = $this->json($this->api('GET', '/admin/api/v1/dns', $token))['data'];
        self::assertSame('192.0.2.10', $settings['addresses']['ip4']);
        self::assertSame('ns1.host.test', $settings['nameservers'][0]['name']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/dns', $token, ['ip4' => 'nope'])->status());
        $r = $this->api('PUT', '/admin/api/v1/dns', $token, ['nameservers' => ['ns1.api.test', 'ns2.api.test'], 'template' => "@ 3600 A {ip}\n"]);
        self::assertSame("@ 3600 A {ip}\n", $this->json($r)['data']['template']);
        self::assertContains('shop.example.com NS ns1.api.test', $this->sent('shop.example.com'));
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/dns', $token, ['template' => 'broken'])->status());

        self::assertSame(201, $this->api('POST', '/admin/api/v1/dns/zones', $token, ['name' => 'api.test'])->status());
        self::assertSame(201, $this->api('POST', '/admin/api/v1/dns/zones/api.test/records', $token, ['name' => 'x', 'type' => 'TXT', 'content' => 'hello'])->status());
        self::assertCount(2, $this->json($this->api('GET', '/admin/api/v1/dns/zones', $token))['data']);
        self::assertSame(422, $this->api('DELETE', '/admin/api/v1/dns/zones/shop.example.com', $token)->status());
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/dns/zones/api.test', $token)->status());
        self::assertArrayNotHasKey('api.test', $this->server->zones);
    }

    // -------------------------------------------------------------------------

    private function owner(): Browser
    {
        return $this->signedIn('user', 'shop', 'shop-owner-password');
    }

    /** @return array<string, mixed> */
    private function zone(string $name): array
    {
        return $this->db()->table('dns_zones')->where('name', $name)->first() ?? self::fail('no zone ' . $name);
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
