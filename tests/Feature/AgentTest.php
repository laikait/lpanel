<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentKey;
use App\Modules\Agent\Commands\AgentCommands;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\TestCase;

final class AgentTest extends TestCase
{
    private const FACTS = [
        'hostname' => 'web-01.example.test',
        'os' => ['id' => 'almalinux', 'version_id' => '9.6', 'pretty_name' => 'AlmaLinux 9.6 (Sage Margay)'],
        'kernel' => '5.14.0-570.el9.x86_64',
        'arch' => 'amd64',
        'cpus' => 4,
        'load' => ['one' => 0.42, 'five' => 0.3, 'fifteen' => 0.25],
        'memory' => ['total_bytes' => 8589934592, 'available_bytes' => 2147483648, 'swap_total_bytes' => 0, 'swap_free_bytes' => 0],
        'uptime_seconds' => 273600,
        'disks' => [
            ['mount' => '/', 'fs_type' => 'xfs', 'total_bytes' => 107374182400, 'available_bytes' => 53687091200, 'used_bytes' => 53687091200, 'total_inodes' => 1, 'free_inodes' => 1],
            ['mount' => '/home', 'fs_type' => 'xfs', 'total_bytes' => 536870912000, 'available_bytes' => 53687091200, 'used_bytes' => 483183820800, 'total_inodes' => 1, 'free_inodes' => 1],
        ],
        'agent' => ['version' => '0.1.0', 'started' => '2026-10-29T00:00:00Z'],
    ];

    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-here');
        $users = $this->app->container()->get(PanelUsers::class);
        $users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
        $users->create(Kind::Reseller, 'rex', 'Rex', null, new Secret('rex-password-1234'));
    }

    public function test_requests_are_signed_and_a_retry_keeps_its_id(): void
    {
        $agent = new FakeAgent(['system.ping' => ['agent' => '0.1.0']]);
        $client = new AgentClient(static fn(): FakeAgent => $agent, static fn(): AgentKey => $agent->key);

        $first = $client->call('system.ping');
        $retry = $client->call('system.ping', id: 'retry-of-the-same-work-01');
        $again = $client->call('system.ping', id: 'retry-of-the-same-work-01');

        self::assertTrue($first->done());
        self::assertSame('0.1.0', $first->result['agent']);
        self::assertSame($retry->id, $again->id);
        self::assertNotSame($agent->requests[1]['nonce'], $agent->requests[2]['nonce']);
        self::assertSame([], $agent->requests[0]['args']);
    }

    public function test_a_request_signed_with_another_key_is_refused(): void
    {
        $agent = new FakeAgent(['system.ping' => ['agent' => '0.1.0']]);
        $stranger = AgentKey::fromSeed(\str_repeat("\x09", \SODIUM_CRYPTO_SIGN_SEEDBYTES));

        $reply = (new AgentClient(static fn(): FakeAgent => $agent, static fn(): AgentKey => $stranger))->call('system.ping');

        self::assertSame('refused', $reply->status);
        self::assertSame('bad_signature', $reply->errorCode);
    }

    public function test_the_admin_dashboard_shows_the_server_live(): void
    {
        $this->agent($this->app, new FakeAgent(['system.facts' => self::FACTS]));
        $root = $this->browser($this->app);
        $root->signIn('admin', 'root', 'root-password-here');

        $page = $root->get('/admin')->body();

        self::assertStringContainsString('web-01.example.test', $page);
        self::assertStringContainsString('AlmaLinux 9.6 (Sage Margay)', $page);
        self::assertStringContainsString('75%', $page);         // memory: 6 of 8 GB
        self::assertStringContainsString('6.0 GB of 8.0 GB', $page);
        self::assertStringContainsString('3d 4h', $page);       // uptime
        self::assertStringContainsString('/home', $page);       // second disk listed
        self::assertStringContainsString('Agent 0.1.0', $page);
    }

    public function test_administrators_see_the_server_and_resellers_do_not(): void
    {
        $this->agent($this->app, new FakeAgent(['system.facts' => self::FACTS]));
        $alice = $this->browser($this->app);
        $alice->signIn('admin', 'alice', 'alice-password-123');
        $rex = $this->browser($this->app);
        $rex->signIn('admin', 'rex', 'rex-password-1234');

        self::assertStringContainsString('web-01.example.test', $alice->get('/admin')->body());
        self::assertStringNotContainsString('web-01.example.test', $rex->get('/admin')->body());
    }

    public function test_an_unreachable_agent_is_a_warning_not_an_error_page(): void
    {
        $this->agent($this->app, new FakeAgent(['system.facts' => FakeAgent::unavailable()]));
        $root = $this->browser($this->app);
        $root->signIn('admin', 'root', 'root-password-here');

        $response = $root->get('/admin');

        self::assertSame(200, $response->status());
        self::assertStringContainsString('The panel cannot reach the LPanel agent.', $response->body());
        self::assertStringContainsString('/run/lpa/lpa.sock', $response->body());
    }

    public function test_keygen_writes_a_pair_the_client_can_use_and_never_overwrites_by_accident(): void
    {
        $dir = \sys_get_temp_dir() . '/lpanel-keygen-' . \bin2hex(\random_bytes(4));
        $commands = $this->app->container()->get(AgentCommands::class);
        $output = new Output(\fopen('php://memory', 'w+') ?: throw new \RuntimeException());

        $made = $commands->keygen($output, $dir . '/agent.key', $dir . '/panel.pub');
        $again = $commands->keygen($output, $dir . '/agent.key', $dir . '/panel.pub');
        $key = AgentKey::fromFile($dir . '/agent.key');

        self::assertSame(0, $made);
        self::assertSame(1, $again);
        self::assertSame(\trim((string) \file_get_contents($dir . '/panel.pub')), $key->publicKey());

        foreach (['agent.key', 'panel.pub'] as $file) {
            \unlink($dir . '/' . $file);
        }
        \rmdir($dir);
    }
}
