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
use App\Modules\Security\BruteForce\BruteForce;
use App\Modules\Security\BruteForce\Listeners;
use App\Modules\Security\Commands\BruteForceCommands;
use App\Modules\Security\Firewall\Cidr;
use App\Modules\Security\Firewall\Entries;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M9b: brute-force blocking: failures counted by address (the panel's own and
 * what the agent reads in the services' logs), blocks through the firewall,
 * repeat offenders, what is never blocked, the page, the API and the commands.
 */
final class BruteForceTest extends TestCase
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

    public function test_an_address_that_keeps_failing_is_blocked_for_a_while(): void
    {
        $this->server->authFailures = [
            ['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 6],
            ['ip' => '198.51.100.9', 'service' => 'mail', 'count' => 4],
            ['ip' => '203.0.113.20', 'service' => 'ssh', 'count' => 9],
        ];

        $result = $this->bruteForce()->scan();

        self::assertSame(['198.51.100.9'], $result['blocked'], 'ten in all, on two services; nine is not enough');
        self::assertSame(['198.51.100.9'], $this->server->fw['deny']);

        $row = $this->entries()->findAddress(Entries::DENY, '198.51.100.9');
        self::assertNotNull($row);
        self::assertSame('bruteforce', $row['source']);
        self::assertStringContainsString('10 failed sign-ins on mail, ssh', (string) $row['note']);
        self::assertGreaterThan(\gmdate('Y-m-d H:i:s', \time() + 3000), (string) $row['expires_at']);
        self::assertLessThan(\gmdate('Y-m-d H:i:s', \time() + 4000), (string) $row['expires_at'], 'an hour');
        self::assertContains('firewall.deny:ok', $this->audited('firewall.deny'));

        // The second address crosses the line with one more failure; the first is not asked again.
        $this->server->authFailures = [['ip' => '203.0.113.20', 'service' => 'ftp', 'count' => 1]];
        self::assertSame(['203.0.113.20'], $this->bruteForce()->scan()['blocked']);
        self::assertSame([], $this->bruteForce()->block(), 'blocked addresses are not blocked twice');
    }

    public function test_the_log_cursors_are_kept_and_sent_back(): void
    {
        $this->bruteForce()->scan();
        $this->bruteForce()->scan();

        self::assertSame([], (array) $this->server->authScans[0]['cursors']);
        self::assertSame(['offset' => 100, 'head' => 'h1'], $this->server->authScans[1]['cursors']['ssh']);
        self::assertSame(['ftp', 'mail', 'smtp'], $this->bruteForce()->overview()['missing']);
    }

    public function test_the_panels_own_failed_sign_ins_count_too(): void
    {
        $listeners = $this->app->container()->get(Listeners::class);
        $from = $this->requestFrom('203.0.113.77');

        for ($i = 0; $i < 10; $i++) {
            $listeners->signInFailed('root', $from);
        }

        self::assertSame(['203.0.113.77'], $this->bruteForce()->block());

        // From the server itself, nothing is counted; and a real failed sign-in reaches the listener.
        $listeners->signInFailed('root', $this->requestFrom('127.0.0.1'));
        $this->browser($this->app)->signIn('admin', 'root', 'not-the-password');
        self::assertSame(['203.0.113.77'], $this->server->fw['deny']);
    }

    public function test_an_address_that_is_always_let_in_is_never_blocked(): void
    {
        $this->firewall()->addEntry(null, null, Entries::ALLOW, '198.51.100.0/24', 'the office');
        $this->server->authFailures = [['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 50], ['ip' => '127.0.0.1', 'service' => 'ssh', 'count' => 50]];

        self::assertSame([], $this->bruteForce()->scan()['blocked']);
        self::assertSame([], $this->server->fw['deny']);
    }

    public function test_an_ipv6_attacker_is_blocked_as_its_network(): void
    {
        $this->server->authFailures = [['ip' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd', 'service' => 'ssh', 'count' => 12]];
        self::assertSame(['2001:db8:1:2::/64'], $this->bruteForce()->scan()['blocked']);

        self::assertTrue(Cidr::contains('2001:db8:1:2::/64', '2001:db8:1:2::9'));
        self::assertFalse(Cidr::contains('2001:db8:1:2::/64', '2001:db8:1:3::9'));
        self::assertTrue(Cidr::contains('198.51.100.0/24', '198.51.100.77'));
        self::assertFalse(Cidr::contains('198.51.100.0/24', '198.51.101.1'));
        self::assertFalse(Cidr::contains('198.51.100.0/25', '198.51.100.0/24'), 'a wider network is not inside a narrower one');
        self::assertFalse(Cidr::contains('198.51.100.0/24', '2001:db8::1'), 'families do not mix');
    }

    public function test_a_repeat_offender_is_blocked_for_longer_each_time(): void
    {
        $durations = [];

        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->server->authFailures = [['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 10]];
            $this->bruteForce()->scan();
            $row = $this->entries()->findAddress(Entries::DENY, '198.51.100.9');
            self::assertNotNull($row, 'round ' . $n);
            $durations[] = $this->lastBanDuration();

            // The block ends (and the failures with it) and the address comes back.
            $this->entries()->remove((int) $row['id']);
            $this->app->container()->get(\App\Engine\Database\ConnectionManager::class)->connection()->table('bruteforce_failures')->deleteAll();
        }

        self::assertSame(['1h', '1d', '7d', '30d', '30d'], $durations);
    }

    public function test_blocking_can_be_turned_off_and_the_limit_changed(): void
    {
        $admin = $this->admin();
        $page = $admin->get('/admin/security/bruteforce');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Brute-force blocking', $page->body());
        self::assertStringContainsString('href="/admin/security/bruteforce"', $admin->get('/admin')->body(), 'it is in the menu');

        $admin->post('/admin/security/bruteforce', ['threshold' => '3', 'window' => '5', 'duration' => '1d']);
        self::assertSame(['enabled' => false, 'threshold' => 3, 'window' => 5, 'duration' => '1d'], $this->bruteForce()->settings(), 'an unticked box is off');

        $admin->post('/admin/security/bruteforce', ['enabled' => '1', 'threshold' => '3', 'window' => '5', 'duration' => '1d']);
        $this->server->authFailures = [['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 3]];
        self::assertSame(['198.51.100.9'], $this->bruteForce()->scan()['blocked']);
        $day = $this->entries()->findAddress(Entries::DENY, '198.51.100.9');
        self::assertNotNull($day);
        self::assertGreaterThan(\gmdate('Y-m-d H:i:s', \time() + 80000), (string) $day['expires_at']);

        $page = $admin->get('/admin/security/bruteforce')->body();
        self::assertStringContainsString('198.51.100.9', $page, 'the latest blocks');
        self::assertStringContainsString('not found', $page, 'logs that are missing are said');

        // Off: nothing is read, nothing is blocked.
        $admin->post('/admin/security/bruteforce', ['threshold' => '3', 'window' => '5', 'duration' => '1h']);
        $scans = \count($this->server->authScans);
        $this->server->authFailures = [['ip' => '203.0.113.5', 'service' => 'ssh', 'count' => 90]];
        self::assertSame([], $this->bruteForce()->scan()['blocked']);
        self::assertCount($scans, $this->server->authScans);

        foreach ([['threshold' => '2'], ['threshold' => 'many'], ['window' => '0'], ['window' => '5000'], ['duration' => 'never']] as $bad) {
            self::assertNotNull($this->bruteForce()->configure(null, null, $bad), (string) \json_encode($bad));
        }

        self::assertCount(3, \array_filter($this->audited('bruteforce.settings'), static fn(string $a): bool => $a === 'bruteforce.settings:ok'));
    }

    public function test_only_root_sees_the_page_and_the_api_does_the_same(): void
    {
        self::assertSame(403, $this->staffMember()->get('/admin/security/bruteforce')->status());

        $token = $this->rootToken();
        $api = fn(string $method, string $uri, array $body = []) => $this->api($method, $uri, $token, $body);

        $show = $api('GET', '/admin/api/v1/bruteforce');
        self::assertSame(200, $show->status());
        self::assertSame(10, $this->json($show)['data']['settings']['threshold']);

        $put = $api('PUT', '/admin/api/v1/bruteforce', ['threshold' => 20, 'duration' => '7d', 'enabled' => false]);
        self::assertSame(200, $put->status(), $put->body());
        self::assertSame(['enabled' => false, 'threshold' => 20, 'window' => 10, 'duration' => '7d'], $this->bruteForce()->settings());
        self::assertSame(422, $api('PUT', '/admin/api/v1/bruteforce', ['threshold' => 1])->status());
    }

    public function test_the_command_line_shows_and_changes_it(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(BruteForceCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('set', false, false, '5', '2', '1d');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('set', true, true);
        self::assertSame(1, $code);
        [$code] = $run('set');
        self::assertSame(1, $code);
        [$code, $text] = $run('set', false, false, '1');
        self::assertSame(1, $code);
        self::assertStringContainsString('3 to 1000', $text);

        $this->server->authFailures = [['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 5]];
        [$code, $text] = $run('scan');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('198.51.100.9', $text);

        [$code, $text] = $run('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('5 failed sign-ins within 2 minutes', $text);
        self::assertStringContainsString('LATEST BLOCKS', $text);

        $this->server->down = true;
        [$code, $text] = $run('scan');
        self::assertSame(1, $code);
        self::assertStringContainsString('not answering', $text);
    }

    public function test_old_failures_are_forgotten(): void
    {
        $db = $this->app->container()->get(\App\Engine\Database\ConnectionManager::class)->connection();
        $db->table('bruteforce_failures')->insert(['ip' => '198.51.100.9', 'service' => 'ssh', 'count' => 50, 'seen_at' => \gmdate('Y-m-d H:i:s', \time() - 7200)]);

        self::assertSame([], $this->bruteForce()->block(), 'outside the window');
        $this->bruteForce()->tidy();
        self::assertSame(1, $db->table('bruteforce_failures')->count(), 'two hours old is kept for a day');
        $db->table('bruteforce_failures')->updateAll(['seen_at' => \gmdate('Y-m-d H:i:s', \time() - 90000)]);
        $this->bruteForce()->tidy();
        self::assertSame(0, $db->table('bruteforce_failures')->count());
    }

    // -------------------------------------------------------------------------

    private function bruteForce(): BruteForce
    {
        return $this->app->container()->get(BruteForce::class);
    }

    private function firewall(): \App\Modules\Security\Firewall\Firewall
    {
        return $this->app->container()->get(\App\Modules\Security\Firewall\Firewall::class);
    }

    private function entries(): Entries
    {
        return $this->app->container()->get(Entries::class);
    }

    private function lastBanDuration(): string
    {
        $row = $this->app->container()->get(\App\Engine\Database\ConnectionManager::class)->connection()->table('bruteforce_bans')->orderByDesc('id')->first();

        return (string) ($row['duration'] ?? '');
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    private function requestFrom(string $ip): Request
    {
        return Request::create('POST', '/admin/login', ['server' => ['REMOTE_ADDR' => $ip]]);
    }

    private function staffMember(): Browser
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));

        return $this->signedIn('admin', 'staff', 'staff-password-123');
    }

    private function rootToken(): string
    {
        return $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];
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
}
