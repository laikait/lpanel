<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Identity;
use App\Engine\Core\Application;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\MCP\McpServer;
use App\Engine\MCP\McpSession;
use App\Engine\Security\Signer;
use App\Engine\System\Audit\AuditOutcome;
use App\Engine\System\Audit\AuditRecord;
use App\Engine\System\Audit\SystemAudit;
use App\Engine\System\Cron\CronManager;
use App\Engine\System\Cron\ScheduleRunJob;
use App\Engine\System\Security\SystemAuthorizationException;
use App\Engine\System\Service\ServiceException;
use App\Tests\Fixtures\Modules\System\Plugins\Server\Services\ServerService;
use App\Tests\Fixtures\System\MemoryCronTable;
use App\Tests\Support\TestCase;

/**
 * The demonstration server module, through HTTP and through its service.
 */
final class SystemServerModuleTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        $app = $this->shippedApplication([
            'modules' => ['paths' => [
                // The showcase's shared module, for the accounts the checks below log in as.
                'tests/Fixtures/Showcase/Shared',
                'tests/Fixtures/Modules/System/Plugins',
            ]],
            'security' => ['key' => Signer::generate()],
            'system' => ['execution' => ['max_concurrent' => null], 'services' => ['nginx' => ['restart']]],
            ...$config,
        ])->boot();

        $cron = new CronManager(new MemoryCronTable(), 'demo');
        $cron->install(ScheduleRunJob::forApplication('/srv/app', '/usr/bin/php'));
        $app->container()->instance(CronManager::class, $cron);

        return $app;
    }

    /** @param array<string, string> $headers */
    private function get(Application $app, string $path, array $headers = []): Response
    {
        return $app->handle(Request::create('GET', $path, ['headers' => $headers, 'server' => ['SCRIPT_NAME' => '/index.php']]));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = \json_decode($response->body(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_nobody_sees_the_server_until_a_role_says_so(): void
    {
        $app = $this->app();

        self::assertSame(401, $this->get($app, '/server/info')->status(), 'a guest');

        // An administrator of the application is not thereby an administrator of
        // the server: system capabilities are granted one by one.
        self::assertSame(403, $this->get($app, '/server/info', ['Authorization' => 'Bearer ada-token-do-not-use'])->status());
    }

    public function test_a_viewer_reads_info_and_the_applications_cron_jobs(): void
    {
        $app = $this->app(['auth' => ['guest_roles' => ['server-viewer']]]);

        $info = $this->get($app, '/server/info');
        self::assertSame(200, $info->status());
        $data = $this->json($info)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(\PHP_VERSION, $data['php'] ?? null);

        $cron = $this->json($this->get($app, '/server/cron'));
        self::assertStringContainsString(ScheduleRunJob::ID, (string) \json_encode($cron));
    }

    public function test_a_name_that_is_not_a_service_is_a_bad_request(): void
    {
        $app = $this->app(['auth' => ['guest_roles' => ['server-viewer']]]);

        self::assertSame(400, $this->get($app, '/server/services/poweroff.target')->status());
    }

    public function test_a_viewer_cannot_restart_and_an_operator_is_still_bound_by_the_policy(): void
    {
        $server = $this->app()->container()->get(ServerService::class);

        try {
            $server->restartService(new Identity('3', 'vic', ['server-viewer']), 'nginx');
            self::fail('a viewer restarted a service');
        } catch (SystemAuthorizationException $e) {
            self::assertSame('nginx.service', $e->operation->target);
        }

        // Holding server.service.restart, and asking for a service the application
        // never allowed restarting.
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Nothing permits "restart ssh.service"');

        $server->restartService(new Identity('4', 'olga', ['server-operator']), 'ssh');
    }

    // ---- over MCP (SYSTEM-19) ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(Application $app, Identity $who, string $method, array $params = []): array
    {
        $session = new McpSession($who, 'stdio');
        $session->initialize('2025-06-18', 'test', '1');

        $answer = $app->container()->get(McpServer::class)->handle(
            (string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]),
            $session,
        );
        self::assertIsString($answer);
        $decoded = \json_decode($answer, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** Authenticate and authorize: each role sees its operations, and nobody sees a way to run a command. */
    public function test_over_mcp_each_role_sees_exactly_its_operations(): void
    {
        $app = $this->app();
        $names = fn(Identity $who): array => \array_column($this->mcp($app, $who, 'tools/list')['result']['tools'] ?? [], 'name');

        self::assertSame([], $names(new Identity('10', 'ada', ['administrator'])), 'no system capability, no server tool');
        self::assertSame(['server.info', 'server.service.status', 'server.cron.list'], $names(new Identity('3', 'vic', ['server-viewer'])));
        self::assertSame(
            ['server.info', 'server.service.status', 'server.service.restart', 'server.cron.list'],
            $names(new Identity('4', 'olga', ['server-operator'])),
        );
    }

    /** Execute through System and return normalized results. */
    public function test_over_mcp_a_viewer_reads_info_and_the_applications_cron_jobs(): void
    {
        $app = $this->app();
        $viewer = new Identity('3', 'vic', ['server-viewer']);

        $info = $this->mcp($app, $viewer, 'tools/call', ['name' => 'server.info'])['result']['structuredContent'] ?? [];
        self::assertSame(\PHP_VERSION, $info['php'] ?? null);

        $jobs = $this->mcp($app, $viewer, 'tools/call', ['name' => 'server.cron.list'])['result']['structuredContent']['jobs'] ?? [];
        self::assertSame([ScheduleRunJob::ID], \array_column($jobs, 'id'));
    }

    /** Validate input: restarting needs a service name of the right shape and an explicit confirm. */
    public function test_over_mcp_restart_needs_a_service_name_and_confirm_true(): void
    {
        $app = $this->app();
        $operator = new Identity('4', 'olga', ['server-operator']);

        foreach ([['service' => 'nginx'], ['service' => 'nginx', 'confirm' => false], ['service' => 'nginx; reboot', 'confirm' => true]] as $arguments) {
            $answer = $this->mcp($app, $operator, 'tools/call', ['name' => 'server.service.restart', 'arguments' => $arguments]);

            self::assertSame(-32602, $answer['error']['code'] ?? null, (string) \json_encode($arguments));
        }
    }

    /** Apply System policy, and audit: the permission is not enough when the application never allowed the service. */
    public function test_over_mcp_the_service_policy_still_decides_and_the_refusal_is_audited(): void
    {
        $app = $this->app();
        $audited = [];
        $app->container()->get(HookEngine::class)->add(SystemAudit::HOOK, static function (AuditRecord $record) use (&$audited): void {
            $audited[] = $record->event . ' ' . $record->outcome->value . ' ' . $record->target;
        });

        $answer = $this->mcp($app, new Identity('4', 'olga', ['server-operator']), 'tools/call', [
            'name' => 'server.service.restart',
            'arguments' => ['service' => 'ssh', 'confirm' => true],
        ]);

        self::assertTrue($answer['result']['isError'] ?? false);
        self::assertStringContainsString('Nothing permits "restart ssh.service"', (string) ($answer['result']['content'][0]['text'] ?? ''));
        // The capability was granted for that exact unit; the policy then said no.
        self::assertSame([
            'system.authorization.granted ' . AuditOutcome::Succeeded->value . ' ssh.service',
            'system.service.refused ' . AuditOutcome::Refused->value . ' ssh.service',
        ], $audited);
    }

    /** The demo's surface is its four operations; nothing in it runs what it is given. */
    public function test_the_demo_offers_no_way_to_run_a_command(): void
    {
        $methods = \array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(ServerService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame(['__construct', 'info', 'serviceStatus', 'restartService', 'cronJobs'], $methods);

        $module = (string) \file_get_contents($this->basePath('tests/Fixtures/Modules/System/Plugins/Server/module.php'));
        self::assertStringNotContainsString('CommandExecutor', $module);
        self::assertStringNotContainsString('ShellCommand', $module);
    }
}
