<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Commands\LogCommands;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M10e: logs and statistics. What an Admin can read, that an owner reads only
 * their own account's, and that nothing outside the agent's list is asked for.
 */
final class LogsTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->server->logs = [
            'journal:httpd' => ['Nov 24 started', 'Nov 24 ERROR <script>alert(1)</script> boom', 'Nov 24 reloaded'],
            'file:maillog' => ['mail one', 'mail two'],
            'web.access' => ['203.0.113.5 GET /index.php 200', '203.0.113.6 GET /missing 404'],
            'web.error' => ['[error] client denied'],
            'php.error' => ['PHP Warning: undefined variable'],
        ];
        $this->agent($this->app, $this->server->agent());
    }

    public function test_an_admin_reads_the_servers_logs_and_what_a_line_says_cannot_run(): void
    {
        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $page = $admin->get('/admin/logs?source=journal:httpd&lines=500&filter=error');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('href="/admin/logs"', $admin->get('/admin')->body(), 'it is in the menu');
        self::assertStringContainsString('ERROR &lt;script&gt;alert(1)&lt;/script&gt; boom', $page->body(), 'a log line is text, never markup');
        self::assertStringNotContainsString('<script>alert(1)', $page->body());
        self::assertStringNotContainsString('started', $page->body(), 'only the lines with the text');
        self::assertSame(['logs.read', 'journal:httpd', 500, 'error'], [$this->asked()[0]['op'], $this->asked()[0]['source'], $this->asked()[0]['lines'], $this->asked()[0]['filter']]);

        // The first log is shown when none is chosen; one that is not listed is not asked for.
        $this->server->logAsked = [];
        self::assertSame(200, $admin->get('/admin/logs')->status());
        $admin->get('/admin/logs?source=file:../../etc/shadow');
        foreach ($this->asked() as $asked) {
            self::assertContains($asked['source'], ['journal:httpd', 'file:maillog']);
        }

        // Another number of lines than the choices becomes 200.
        $this->server->logAsked = [];
        $admin->get('/admin/logs?source=file:maillog&lines=99999');
        self::assertSame(200, $this->asked()[0]['lines']);
    }

    public function test_an_admin_can_look_at_an_accounts_logs_but_only_an_account_that_exists(): void
    {
        $this->makeAccount('ana', $this->packageFor(null, 'basic'), null);
        $admin = $this->signedIn('admin', 'root', 'root-password-123');

        $this->server->logAsked = [];
        $page = $admin->get('/admin/logs?account=ana&source=web.access');
        self::assertStringContainsString('GET /missing 404', $page->body());
        self::assertSame('ana', $this->asked()[0]['user']);

        $this->server->logAsked = [];
        $admin->get('/admin/logs?account=../../etc&source=web.access');
        foreach ($this->asked() as $asked) {
            self::assertArrayNotHasKey('user', $asked, 'a name that is not an account is the server');
        }
    }

    public function test_an_owner_reads_only_their_own_accounts_logs(): void
    {
        $this->makeAccount('ana', $this->packageFor(null, 'basic'), null);
        $this->makeAccount('bob', $this->packageFor(null, 'second'), null);
        $owner = $this->signedIn('user', 'ana', 'ana-owner-password');

        $page = $owner->get('/panel/logs?source=web.error');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('client denied', $page->body());
        self::assertStringContainsString('href="/panel/logs"', $owner->get('/panel')->body(), 'it is in the menu');

        // Nothing in the request can point it at another account or at the system's logs.
        $this->server->logAsked = [];
        $owner->get('/panel/logs?source=journal:httpd&account=bob');
        $owner->get('/panel/logs?source=file:maillog');
        $owner->get('/panel/statistics?account=bob');
        self::assertNotSame([], $this->asked());

        foreach ($this->asked() as $asked) {
            self::assertSame('ana', $asked['user'], \json_encode($asked) ?: '');

            if ($asked['op'] === 'logs.read') {
                self::assertContains($asked['source'], ['web.access', 'web.error', 'php.error']);
            }
        }

        // The Admin pages and the API are not theirs.
        self::assertContains($owner->get('/admin/logs')->status(), [302, 303, 401, 403, 404]);
    }

    public function test_the_statistics_show_what_goaccess_counted_and_ask_again_on_request(): void
    {
        $this->server->logReport = [
            'made' => \gmdate('Y-m-d\TH:i:s\Z'), 'truncated' => false, 'requests' => 1234, 'valid' => 1230, 'failed' => 4, 'visitors' => 321, 'bandwidth' => 5_000_000,
            'daily' => [['date' => '2026-11-23', 'hits' => 400, 'visitors' => 100, 'bytes' => 1], ['date' => '2026-11-24', 'hits' => 834, 'visitors' => 221, 'bytes' => 2]],
            'pages' => [['name' => '/shop/checkout', 'hits' => 77, 'visitors' => 30, 'bytes' => 0]],
            'not_found' => [['name' => '/wp-login.php', 'hits' => 12, 'visitors' => 2, 'bytes' => 0]],
            'referrers' => [], 'hosts' => [['name' => '203.0.113.9', 'hits' => 55, 'visitors' => 1, 'bytes' => 0]],
            'browsers' => [['name' => 'Firefox', 'hits' => 600, 'visitors' => 200, 'bytes' => 0]], 'systems' => [], 'status' => [['name' => '2xx Success', 'hits' => 1200, 'visitors' => 300, 'bytes' => 0]], 'sites' => [['name' => 'shop.example.com', 'hits' => 1000, 'visitors' => 300, 'bytes' => 0]],
        ];
        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $page = $admin->get('/admin/statistics?days=7');
        self::assertSame(200, $page->status());

        foreach (['1,234', '321', '/shop/checkout', '/wp-login.php', 'Firefox', '2026-11-23', '2026-11-24', '203.0.113.9', 'shop.example.com', '4.8 MB'] as $shown) {
            self::assertStringContainsString($shown, $page->body());
        }

        self::assertSame(['logs.report', 7], [$this->asked()[0]['op'], $this->asked()[0]['days']]);
        self::assertArrayNotHasKey('refresh', $this->asked()[0]);

        $this->server->logAsked = [];
        $admin->post('/admin/statistics/refresh?days=90');
        self::assertTrue($this->asked()[0]['refresh']);
        self::assertSame(90, $this->asked()[0]['days']);

        // Days other than the choices become 30.
        $this->server->logAsked = [];
        $admin->get('/admin/statistics?days=100000');
        self::assertSame(30, $this->asked()[0]['days']);
    }

    public function test_without_goaccess_or_the_agent_the_pages_say_so(): void
    {
        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $page = $admin->get('/admin/statistics');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('GoAccess is not installed', $page->body());

        $this->server->down = true;
        self::assertStringContainsString('agent is not answering', $admin->get('/admin/logs')->body());
        self::assertStringContainsString('agent is not answering', $admin->get('/admin/statistics')->body());
    }

    public function test_the_api_and_the_cli(): void
    {
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];

        $sources = $this->json($this->api('GET', '/admin/api/v1/logs/sources', $token))['data']['sources'];
        self::assertSame(['journal:httpd', 'file:maillog'], \array_column($sources, 'name'));

        $read = $this->api('GET', '/admin/api/v1/logs?source=file:maillog&lines=100&filter=two', $token);
        self::assertSame(200, $read->status(), $read->body());
        self::assertSame(['mail two'], $this->json($read)['data']['lines']);
        self::assertSame(422, $this->api('GET', '/admin/api/v1/logs', $token)->status(), 'a log must be named');
        self::assertSame(422, $this->api('GET', '/admin/api/v1/logs?source=file:nope', $token)->status());
        self::assertSame(502, $this->api('GET', '/admin/api/v1/statistics', $token)->status(), 'GoAccess is not installed');

        $this->server->logReport = ['made' => \gmdate('Y-m-d\TH:i:s\Z'), 'requests' => 10, 'visitors' => 3, 'failed' => 0, 'bandwidth' => 2048, 'daily' => [], 'pages' => [['name' => '/', 'hits' => 9, 'visitors' => 3, 'bytes' => 0]]];
        self::assertSame(10, $this->json($this->api('GET', '/admin/api/v1/statistics?days=7', $token))['data']['requests']);

        [$code, $out] = $this->cli('tail', 'journal:httpd', null, null, 'reloaded');
        self::assertSame([0, true], [$code, \str_contains($out, 'Nov 24 reloaded') && !\str_contains($out, 'started')]);
        [$code] = $this->cli('tail', 'file:nope');
        self::assertSame(1, $code);
        [$code, $out] = $this->cli('stats');
        self::assertSame(0, $code);
        self::assertStringContainsString('requests 10, visitors 3', $out);
        [$code, $out] = $this->cli('sources');
        self::assertStringContainsString('file:maillog', $out);

        $this->server->down = true;
        self::assertSame(503, $this->api('GET', '/admin/api/v1/logs/sources', $token)->status());
    }

    public function test_only_those_allowed_read_logs(): void
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Reseller, 'reseller', 'Reseller Person', null, new Secret('reseller-password-123'));
        $reseller = $this->signedIn('admin', 'reseller', 'reseller-password-123');

        self::assertSame(403, $reseller->get('/admin/logs')->status());
        self::assertSame(403, $reseller->get('/admin/statistics')->status());
        self::assertStringNotContainsString('href="/admin/logs"', $reseller->get('/admin')->body());
    }

    /** @return list<array<string, mixed>> what the agent was asked to read */
    private function asked(): array
    {
        return $this->server->logAsked;
    }

    /** @return array{0: int, 1: string} */
    private function cli(string $method, mixed ...$args): array
    {
        $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $code = $this->app->container()->get(LogCommands::class)->{$method}(new Output($out, $err), ...$args);
        \rewind($out);
        \rewind($err);

        return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
    }
}
