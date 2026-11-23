<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Security\Commands\FirewallCommands;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Countries;
use App\Modules\Security\Firewall\Entries;
use App\Modules\Security\Firewall\Firewall;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M9a: the firewall manager: ports, the allow and deny lists, blocked
 * countries, through the Admin panel, the API and the command line, and the
 * refusals that keep the administrator from locking themselves out.
 */
final class FirewallTest extends TestCase
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
    }

    public function test_the_page_shows_the_firewall_and_only_root_sees_it(): void
    {
        $admin = $this->admin();
        $page = $admin->get('/admin/security/firewall');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('80/tcp', $page->body());
        self::assertStringContainsString('7443/tcp', $page->body());
        self::assertStringContainsString('href="/admin/security/firewall"', $admin->get('/admin')->body(), 'it is in the menu');
        self::assertSame(403, $this->staffMember()->get('/admin/security/firewall')->status(), 'an administrator who is not root');

        $this->makeAccount('shop', $this->packageFor(null, 'Starter', 2048, ['domains']), null);
        self::assertContains($this->signedIn('user', 'shop', 'shop-owner-password')->get('/admin/security/firewall')->status(), [403, 404, 302, 303]);
    }

    public function test_a_server_without_firewalld_says_so_and_changes_nothing(): void
    {
        $this->server->fw['running'] = false;
        $admin = $this->admin();
        self::assertStringContainsString('firewalld is not running', $admin->get('/admin/security/firewall')->body());

        $admin->post('/admin/security/firewall/entries', ['list' => 'deny', 'address' => '198.51.100.9']);
        self::assertSame([], $this->entries()->all(Entries::DENY), 'nothing is kept that the server did not do');
        self::assertStringContainsString('This server has no firewalld', $admin->get('/admin/security/firewall')->body());
    }

    public function test_a_port_is_opened_limited_and_closed(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/security/firewall/ports', ['port' => '8080', 'proto' => 'tcp', 'scope' => 'all']);
        self::assertContains(['port' => '8080', 'proto' => 'tcp', 'sources' => []], $this->server->fw['ports']);
        self::assertStringContainsString('8080/tcp', $admin->get('/admin/security/firewall')->body());

        $admin->post('/admin/security/firewall/ports', ['port' => '8080', 'proto' => 'tcp', 'scope' => 'only', 'sources' => "203.0.113.7\n198.51.100.0/24, 2001:db8::1"]);
        self::assertContains(['port' => '8080', 'proto' => 'tcp', 'sources' => ['203.0.113.7', '198.51.100.0/24', '2001:db8::1']], $this->server->fw['ports']);
        self::assertCount(1, \array_filter($this->server->fw['ports'], static fn(array $p): bool => $p['port'] === '8080'));

        $page = $admin->get('/admin/security/firewall')->body();
        self::assertStringContainsString('198.51.100.0/24', $page);

        $admin->post('/admin/security/firewall/ports/close', ['port' => '8080', 'proto' => 'tcp']);
        self::assertCount(0, \array_filter($this->server->fw['ports'], static fn(array $p): bool => $p['port'] === '8080'));

        // Only-these-addresses with none is not a thing to guess at.
        $admin->post('/admin/security/firewall/ports', ['port' => '9000', 'proto' => 'tcp', 'scope' => 'only', 'sources' => '']);
        self::assertCount(0, \array_filter($this->server->fw['ports'], static fn(array $p): bool => $p['port'] === '9000'));

        self::assertSame(['firewall.port:ok', 'firewall.port:ok', 'firewall.port:ok'], \array_slice($this->audited('firewall.port'), 0, 3));
    }

    public function test_the_ports_the_panel_and_ssh_use_cannot_be_closed_or_limited_away_from_the_administrator(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/security/firewall/ports/close', ['port' => '22', 'proto' => 'tcp']);
        self::assertContains(['port' => '22', 'proto' => 'tcp', 'sources' => []], $this->server->fw['ports']);
        self::assertContains('failed', $this->outcomes('firewall.port'));

        // Limiting the panel's port to addresses that leave the administrator out.
        $firewall = $this->firewall();
        $me = $this->requestFrom('203.0.113.7');
        [$ok, $why] = $firewall->openPort($this->root(), $me, '7443', 'tcp', ['198.51.100.1']);
        self::assertFalse($ok);
        self::assertStringContainsString('no longer reach', (string) $why);

        [$ok] = $firewall->openPort($this->root(), $me, '7443', 'tcp', ['198.51.100.1', '203.0.113.0/24']);
        self::assertTrue($ok);
    }

    public function test_addresses_are_allowed_and_blocked_and_the_server_gets_both_lists(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/security/firewall/entries', ['list' => 'allow', 'address' => "203.0.113.7\n10.0.0.0/8", 'note' => 'the office']);
        $admin->post('/admin/security/firewall/entries', ['list' => 'deny', 'address' => '198.51.100.0/24', 'note' => 'scanner', 'duration' => '1d']);

        self::assertSame(['10.0.0.0/8', '203.0.113.7'], $this->server->fw['allow']);
        self::assertSame(['198.51.100.0/24'], $this->server->fw['deny']);
        $deny = $this->entries()->all(Entries::DENY)[0];
        self::assertSame('scanner', $deny['note']);
        self::assertGreaterThan(\gmdate('Y-m-d H:i:s', \time() + 80000), (string) $deny['expires_at']);

        $page = $admin->get('/admin/security/firewall')->body();
        self::assertStringContainsString('203.0.113.7', $page);
        self::assertStringContainsString('scanner', $page);

        $admin->post('/admin/security/firewall/entries/' . $deny['id'] . '/delete');
        self::assertSame([], $this->server->fw['deny']);
        self::assertSame([], $this->entries()->all(Entries::DENY));
        self::assertContains('firewall.deny:ok', $this->audited('firewall.deny'));
        self::assertContains('firewall.remove:ok', $this->audited('firewall.remove'));
    }

    public function test_bad_addresses_are_refused_before_the_server_hears_of_them(): void
    {
        $firewall = $this->firewall();
        $root = $this->root();

        foreach (['not an address', '300.1.1.1', '10.0.0.0/33', '0.0.0.0/0', '::/0', '10.0.0.0/4', '::ffff:1.2.3.4', '1.2.3.4/abc'] as $bad) {
            [$ok, $why] = $firewall->addEntry($root, null, 'deny', $bad);
            self::assertFalse($ok, $bad);
            self::assertStringStartsWith('Security.fw_error_', (string) $why, $bad);
        }

        self::assertFalse($firewall->addEntry($root, null, 'nowhere', '1.2.3.4')[0]);
        self::assertFalse($firewall->addEntry($root, null, 'deny', '1.2.3.4', '', 'forever')[0]);
        self::assertSame([], $this->server->executed, 'the agent was not called');

        self::assertTrue($firewall->addEntry($root, null, 'deny', '198.51.100.9')[0]);
        self::assertSame('Security.fw_error_exists', $firewall->addEntry($root, null, 'deny', '198.51.100.9')[1]);
        // The same network written another way is the same entry.
        self::assertSame('Security.fw_error_exists', $firewall->addEntry($root, null, 'deny', '198.51.100.9/32')[1]);
    }

    public function test_the_administrator_cannot_block_their_own_address(): void
    {
        $firewall = $this->firewall();
        $me = $this->requestFrom('203.0.113.7');

        [$ok, $why] = $firewall->addEntry($this->root(), $me, 'deny', '203.0.113.0/24');
        self::assertFalse($ok);
        self::assertStringContainsString('shut you out', (string) $why);
        self::assertSame([], $this->entries()->all(Entries::DENY));
        self::assertSame([], $this->server->fw['deny']);

        // Once allowed, the network may be blocked: the allow list wins.
        self::assertTrue($firewall->addEntry($this->root(), $me, 'allow', '203.0.113.7')[0]);
        self::assertTrue($firewall->addEntry($this->root(), $me, 'deny', '203.0.113.0/24')[0]);

        // A failed attempt is in the audit log with the server's reason.
        self::assertContains('failed', $this->outcomes('firewall.deny'));
    }

    public function test_a_blocked_address_ends_by_itself(): void
    {
        $firewall = $this->firewall();
        self::assertTrue($firewall->addEntry($this->root(), null, 'deny', '198.51.100.9', 'brief', '1h')[0]);
        self::assertTrue($firewall->addEntry($this->root(), null, 'deny', '198.51.100.10', 'for good')[0]);
        self::assertSame(0, $firewall->expire(), 'not yet');

        $this->db()->table('firewall_entries')->where('cidr', '198.51.100.9')->update(['expires_at' => \gmdate('Y-m-d H:i:s', \time() - 5)]);
        self::assertSame(['198.51.100.10'], $this->entries()->active(Entries::DENY), 'an ended entry is not sent');

        self::assertSame(1, $firewall->expire());
        self::assertSame(['198.51.100.10'], $this->server->fw['deny']);
        self::assertCount(1, $this->entries()->all(Entries::DENY));
    }

    public function test_countries_are_blocked_and_the_administrators_own_cannot_be(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/security/firewall/countries', ['codes' => ['CN', 'RU']]);
        self::assertSame(['CN', 'RU'], $this->server->fw['countries']);
        self::assertSame(['CN', 'RU'], $this->entries()->countries());
        $page = $admin->get('/admin/security/firewall')->body();
        self::assertMatchesRegularExpression('/value="CN"[^>]* checked/', $page);
        self::assertDoesNotMatchRegularExpression('/value="FR"[^>]* checked/', $page);

        $admin->post('/admin/security/firewall/countries', ['codes' => ['CN']]);
        self::assertSame(['CN'], $this->server->fw['countries']);

        // The fake CN list holds 203.0.113.0/24, which is where this request comes from.
        $firewall = $this->firewall();
        [$ok, $why] = $firewall->setCountries($this->root(), $this->requestFrom('203.0.113.7'), ['CN', 'RU']);
        self::assertFalse($ok);
        self::assertStringContainsString('shut you out', (string) $why);
        self::assertSame(['CN'], $this->entries()->countries(), 'unchanged');

        [$ok, $why] = $firewall->setCountries($this->root(), null, ['XX']);
        self::assertFalse($ok);
        self::assertSame('Security.fw_error_country', $why);

        // A country whose list cannot be fetched is refused, and nothing changes.
        unset($this->server->fw['nets']['RU']);
        [$ok, $why] = $firewall->setCountries($this->root(), null, ['CN', 'RU']);
        self::assertFalse($ok);
        self::assertStringContainsString('could not be fetched', (string) $why);
        self::assertSame(['CN'], $this->server->fw['countries']);

        self::assertTrue($firewall->setCountries($this->root(), null, [])[0]);
        self::assertSame([], $this->server->fw['countries']);
    }

    public function test_the_api_does_all_of_it(): void
    {
        $token = $this->rootToken();

        $state = $this->json($this->api('GET', '/admin/api/v1/firewall', $token))['data'];
        self::assertTrue($state['firewall']['running']);
        self::assertSame([], $state['allow']);

        $made = $this->api('POST', '/admin/api/v1/firewall/entries', $token, ['list' => 'deny', 'address' => '198.51.100.9', 'note' => 'probe', 'duration' => '7d']);
        self::assertSame(201, $made->status());
        $id = $this->json($made)['data']['id'];
        self::assertSame(['198.51.100.9'], $this->server->fw['deny']);
        self::assertSame(409, $this->api('POST', '/admin/api/v1/firewall/entries', $token, ['list' => 'deny', 'address' => '198.51.100.9'])->status(), 'twice');
        self::assertSame(422, $this->api('POST', '/admin/api/v1/firewall/entries', $token, ['list' => 'deny', 'address' => 'nope'])->status());
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/firewall/entries/' . $id, $token)->status());
        self::assertSame(404, $this->api('DELETE', '/admin/api/v1/firewall/entries/' . $id, $token)->status());

        self::assertSame(200, $this->api('POST', '/admin/api/v1/firewall/ports', $token, ['port' => '3306', 'proto' => 'tcp', 'sources' => ['203.0.113.7']])->status());
        self::assertContains(['port' => '3306', 'proto' => 'tcp', 'sources' => ['203.0.113.7']], $this->server->fw['ports']);
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/firewall/ports/tcp/3306', $token)->status());
        self::assertSame(409, $this->api('DELETE', '/admin/api/v1/firewall/ports/tcp/22', $token)->status(), 'SSH stays open');
        self::assertSame(422, $this->api('POST', '/admin/api/v1/firewall/ports', $token, ['port' => '80', 'proto' => 'tcp', 'sources' => ['junk']])->status());

        $countries = $this->api('PUT', '/admin/api/v1/firewall/countries', $token, ['codes' => ['ru']]);
        self::assertSame(200, $countries->status());
        self::assertSame(['RU'], $this->json($countries)['data']['countries']);

        // No token, no firewall; an administrator's token is not root's.
        self::assertSame(401, $this->api('GET', '/admin/api/v1/firewall', 'nope')->status());

        $this->server->down = true;
        self::assertSame(503, $this->api('GET', '/admin/api/v1/firewall', $token)->status());
    }

    public function test_the_command_line_does_all_of_it(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(FirewallCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('open', '8443', 'tcp', '203.0.113.7,198.51.100.0/24');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('allow', '203.0.113.7', 'me');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('deny', '198.51.100.9', 'probe', '1d');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('countries', 'RU');
        self::assertSame(0, $code, $text);
        self::assertSame(['RU'], $this->server->fw['countries']);

        [$code, $text] = $run('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('8443/tcp', $text);
        self::assertStringContainsString('203.0.113.7, 198.51.100.0/24', $text);
        self::assertStringContainsString('198.51.100.9', $text);
        self::assertStringContainsString('Countries blocked: RU', $text);

        [$code, $text] = $run('close', '22');
        self::assertSame(1, $code);
        self::assertStringContainsString('panel and SSH', $text);
        [$code, $text] = $run('deny', 'rubbish');
        self::assertSame(1, $code);
        self::assertStringContainsString('not an IPv4 or IPv6 address', $text);

        [$code, $text] = $run('remove', '198.51.100.9');
        self::assertSame(0, $code, $text);
        self::assertSame([], $this->server->fw['deny']);
        [$code] = $run('remove', '198.51.100.9');
        self::assertSame(1, $code);

        // The server forgets everything; sync brings the panel's lists back.
        $this->server->fw['allow'] = [];
        $this->server->fw['countries'] = [];
        [$code, $text] = $run('sync');
        self::assertSame(0, $code, $text);
        self::assertSame(['203.0.113.7'], $this->server->fw['allow']);
        self::assertSame(['RU'], $this->server->fw['countries']);

        [$code, $text] = $run('expire');
        self::assertSame(0, $code, $text);
    }

    public function test_addresses_are_written_one_way(): void
    {
        foreach ([
            '203.0.113.7' => '203.0.113.7', '203.0.113.7/32' => '203.0.113.7', ' 203.0.113.77/24 ' => '203.0.113.0/24', '10.1.2.3/8' => '10.0.0.0/8',
            '2001:DB8::1' => '2001:db8::1', '2001:db8:0:0::/32' => '2001:db8::/32', '2001:db8::1/128' => '2001:db8::1',
        ] as $in => $want) {
            self::assertSame([$want, null], Cidr::normalize($in), $in);
        }

        self::assertSame('Security.fw_error_wide', Cidr::normalize('10.0.0.0/7')[1]);
        self::assertSame('Security.fw_error_wide', Cidr::normalize('2001::/15')[1]);
        self::assertSame(['a', 'b', 'c'], Cidr::split("a, b\nc;"));
        self::assertSame([['CN', 'RU'], ['XX']], Countries::parse('ru, cn,xx ru'));
        self::assertTrue(Countries::valid('BD'));
    }

    // -------------------------------------------------------------------------

    private function firewall(): Firewall
    {
        return $this->app->container()->get(Firewall::class);
    }

    private function entries(): Entries
    {
        return $this->app->container()->get(Entries::class);
    }

    private function root(): \App\Engine\Auth\Identity
    {
        return $this->app->container()->get(PanelUsers::class)->root()->identity;
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('POST', '/admin/security/firewall', ['server' => ['REMOTE_ADDR' => $ip]]);
    }

    /** An administrator who is not root. */
    private function staffMember(): Browser
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));

        return $this->signedIn('admin', 'staff', 'staff-password-123');
    }

    private function rootToken(): string
    {
        return $this->app->container()->get(ApiTokens::class)->issue((string) $this->root()->id, 'ops')['token'];
    }

    /** @return list<string> "action:outcome" of the audit entries of an action, newest last */
    private function audited(string $action): array
    {
        $out = [];

        foreach ($this->auditRows() as $row) {
            if (($row['action'] ?? '') === $action) {
                $out[] = $row['action'] . ':' . $row['outcome'];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function outcomes(string $action): array
    {
        return \array_map(static fn(string $s): string => \explode(':', $s)[1], $this->audited($action));
    }
}
