<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Hook\HookEngine;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Commands\ServiceCommands;
use App\Modules\Operations\Services\Services;
use App\Modules\Operations\Services\Watchdog;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M10a: the server's services (how each is doing, start, stop, restart) and the
 * watchdog that restarts what falls over.
 */
final class OperationsTest extends TestCase
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

    public function test_the_page_shows_every_service_by_group_and_the_dashboard_has_a_card(): void
    {
        $admin = $this->admin();
        $page = $admin->get('/admin/services');
        self::assertSame(200, $page->status());
        $body = $page->body();

        foreach (['Apache', 'Exim', 'MariaDB', 'LPanel agent', 'Web servers', 'Mail', 'The panel'] as $shown) {
            self::assertStringContainsString($shown, $body);
        }

        self::assertStringContainsString('Kept running by the panel itself', $body, 'the agent has no buttons, and says why');
        self::assertStringContainsString('href="/admin/services"', $admin->get('/admin')->body(), 'it is in the menu, and the dashboard links to it');
        self::assertStringContainsString('All services', $admin->get('/admin')->body());
    }

    public function test_an_administrator_with_the_right_may_see_it_and_an_owner_may_not(): void
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));
        self::assertSame(200, $this->signedIn('admin', 'staff', 'staff-password-123')->get('/admin/services')->status());

        $this->makeAccount('shop', $this->packageFor(null, 'Starter', 1024, ['domains']), null);
        self::assertContains($this->signedIn('user', 'shop', 'shop-owner-password')->get('/admin/services')->status(), [302, 303, 403, 404]);
    }

    public function test_services_are_started_stopped_and_restarted_and_what_the_agent_refuses_is_said(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/services/pure-ftpd.service/start');
        self::assertSame('active', $this->server->svc['pure-ftpd.service']['active']);

        $admin->post('/admin/services/exim.service/stop');
        self::assertSame('inactive', $this->server->svc['exim.service']['active']);
        self::assertTrue($this->held('exim.service'), 'stopped on purpose');
        self::assertStringContainsString('Stopped on purpose', $admin->get('/admin/services')->body());

        $admin->post('/admin/services/exim.service/start');
        self::assertFalse($this->held('exim.service'));

        $admin->post('/admin/services/mariadb.service/stop');
        self::assertSame('active', $this->server->svc['mariadb.service']['active']);
        self::assertStringContainsString('cannot be', $admin->get('/admin/services')->body());
        $admin->post('/admin/services/lpa.service/restart');
        self::assertContains('service.restart:failed', $this->audited('service.restart'));
        self::assertContains('service.stop:ok', $this->audited('service.stop'));

        // Not an action, not a service.
        self::assertSame(404, $admin->post('/admin/services/exim.service/mask')->status());
        $admin->post('/admin/services/nothing.service/restart');
        self::assertContains('service.restart:failed', $this->audited('service.restart'));
    }

    public function test_the_watchdog_restarts_what_fell_over_and_leaves_alone_what_should_be_down(): void
    {
        $seen = [];
        $this->app->container()->get(HookEngine::class)->add('ops.service_event', static function (string $unit, string $kind, string $detail) use (&$seen): void {
            $seen[] = $unit . ':' . $kind;
        }, 10, 'Test');

        $this->server->service('httpd.service', 'failed');
        $this->server->service('exim.service', 'inactive');
        // pure-ftpd is stopped and does not start at boot: nobody expects it.
        $this->services()->setWatch(null, null, 'exim.service', false);

        $result = $this->watchdog()->run(0);
        self::assertSame(['httpd.service'], $result['restarted'], 'exim is not watched, pure-ftpd is not expected');
        self::assertSame('active', $this->server->svc['httpd.service']['active']);
        self::assertSame('inactive', $this->server->svc['exim.service']['active']);
        self::assertSame('inactive', $this->server->svc['pure-ftpd.service']['active']);
        self::assertSame(['httpd.service:down', 'httpd.service:restarted'], $seen);
        self::assertContains('service.watchdog:ok', $this->audited('service.watchdog'));

        // Next minute: it is up, so the watchdog notes that it has recovered.
        $result = $this->watchdog()->run(0);
        self::assertSame(['httpd.service'], $result['recovered']);
        self::assertSame([], $this->services()->down());

        // Stopped on purpose: left alone.
        $this->server->service('httpd.service', 'inactive');
        $this->services()->setRow('httpd.service', ['hold' => 1]);
        self::assertSame([], $this->watchdog()->run(0)['restarted']);
        self::assertSame('inactive', $this->server->svc['httpd.service']['active']);
    }

    public function test_the_watchdog_waits_for_a_service_that_is_only_taking_a_moment(): void
    {
        // Found down for the first time: noted, not restarted yet.
        $this->server->service('httpd.service', 'failed');
        $r = $this->watchdog()->run();
        self::assertSame(['httpd.service'], $r['down']);
        self::assertSame([], $r['restarted']);
        self::assertSame('failed', $this->server->svc['httpd.service']['active']);

        // Down for only a few seconds: still not.
        self::assertSame([], $this->watchdog()->run()['restarted']);

        // Down for longer than the grace: restarted.
        $this->services()->setRow('httpd.service', ['down_since' => \gmdate('Y-m-d H:i:s', \time() - 60)]);
        self::assertSame(['httpd.service'], $this->watchdog()->run()['restarted']);

        // Reloading or starting is not down.
        $this->server->service('httpd.service', 'reloading');
        $this->services()->setRow('httpd.service', ['down_since' => \gmdate('Y-m-d H:i:s', \time() - 600)]);
        $r = $this->watchdog()->run(0);
        self::assertSame([], $r['down']);
        self::assertSame([], $r['restarted']);
        $this->server->service('httpd.service', 'activating');
        self::assertSame([], $this->watchdog()->run(0)['restarted']);
    }

    public function test_a_service_that_cannot_stay_up_is_given_up_on_and_the_bell_says_so(): void
    {
        $this->server->service('httpd.service', 'failed');
        $this->server->svcFailRestart = ['httpd.service'];

        for ($i = 0; $i < 3; $i++) {
            $r = $this->watchdog()->run(0);
            self::assertSame(['httpd.service'], $r['failed'], 'try ' . ($i + 1));
        }

        $r = $this->watchdog()->run(0);
        self::assertSame(['httpd.service'], $r['gave_up']);
        self::assertSame([], $r['failed']);
        $acts = \count($this->server->serviceActs);
        $r = $this->watchdog()->run(0);
        self::assertSame([], $r['gave_up'], 'said once');
        self::assertCount($acts, $this->server->serviceActs, 'and not tried again this hour');

        $admin = $this->admin();
        self::assertStringContainsString('A service is down', $admin->get('/admin')->body());
        $page = $admin->get('/admin/services')->body();
        self::assertStringContainsString('Given up on', $page);
        self::assertStringContainsString('Could not restart', $page);
        self::assertStringContainsString('Down since', $page);

        // Fixed by hand: no longer down.
        $this->server->svcFailRestart = [];
        $admin->post('/admin/services/httpd.service/restart');
        $this->watchdog()->run(0);
        self::assertSame([], $this->services()->down());
        self::assertStringNotContainsString('A service is down', $admin->get('/admin')->body());
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];

        $list = $this->api('GET', '/admin/api/v1/services', $token);
        self::assertSame(200, $list->status());
        $units = \array_column($this->json($list)['data']['services'], 'unit');
        self::assertContains('httpd.service', $units);

        $act = $this->api('POST', '/admin/api/v1/services/exim.service/restart', $token);
        self::assertSame(200, $act->status(), $act->body());
        self::assertSame('active', $this->json($act)['data']['active']);
        self::assertSame(409, $this->api('POST', '/admin/api/v1/services/mariadb.service/stop', $token)->status());
        self::assertSame(422, $this->api('POST', '/admin/api/v1/services/nothing.service/restart', $token)->status());
        self::assertSame(404, $this->api('POST', '/admin/api/v1/services/exim.service/mask', $token)->status());

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/services/exim.service/watch', $token, ['watch' => false])->status());
        $ev = $this->api('GET', '/admin/api/v1/services/events', $token);
        self::assertSame(200, $ev->status());
        self::assertSame('admin', $this->json($ev)['data']['events'][0]['kind']);

        $this->server->down = true;
        self::assertSame(503, $this->api('GET', '/admin/api/v1/services', $token)->status());
    }

    public function test_the_command_line_does_all_of_it(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(ServiceCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('httpd.service', $text);
        self::assertStringContainsString('pure-ftpd.service', $text);

        [$code, $text] = $run('restart', 'httpd');
        self::assertSame(0, $code, $text);
        self::assertContains('httpd.service restart', $this->server->serviceActs);
        [$code, $text] = $run('stop', 'exim');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('status');
        self::assertStringContainsString('stopped on purpose', $text);
        [$code, $text] = $run('stop', 'mariadb');
        self::assertSame(1, $code);
        self::assertStringContainsString('cannot be', $text);
        [$code, $text] = $run('watch', 'httpd', true);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('no longer watched', $text);

        $this->server->service('httpd.service', 'failed');
        [$code, $text] = $run('watchdog', '0');
        self::assertSame(0, $code, $text);
        self::assertSame('failed', $this->server->svc['httpd.service']['active'], 'httpd is not watched now');
        $run('watch', 'httpd');
        [, $text] = $run('watchdog', '0');
        self::assertStringContainsString('Restarted: httpd.service', $text);

        $this->server->down = true;
        [$code, $text] = $run('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('not answering', $text);
    }


    public function test_the_task_viewer_shows_what_the_agent_did_and_the_api_and_commands_agree(): void
    {
        $this->server->tasks = [
            ['id' => 'a1', 'op' => 'backup.run', 'state' => 'running', 'started' => '2026-11-24T10:00:00Z'],
            ['id' => 'a2', 'op' => 'php.install', 'state' => 'failed', 'started' => '2026-11-24T09:00:00Z', 'finished' => '2026-11-24T09:01:00Z', 'error_code' => 'failed', 'error_message' => 'dnf could not reach the repository'],
            ['id' => 'a3', 'op' => 'db.apply', 'state' => 'done', 'started' => '2026-11-24T08:00:00Z'],
            ['id' => 'a4', 'op' => 'web.apply', 'state' => 'interrupted', 'started' => '2026-11-24T07:00:00Z', 'error_message' => 'the agent stopped while this ran'],
        ];
        $admin = $this->admin();
        $page = $admin->get('/admin/tasks');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('backup.run', $page->body());
        self::assertStringContainsString('dnf could not reach the repository', $page->body());
        self::assertStringContainsString('may or may not have finished', $page->body());
        self::assertStringContainsString('href="/admin/tasks"', $admin->get('/admin')->body(), 'it is in the menu');

        $failed = $admin->get('/admin/tasks?state=failed')->body();
        self::assertStringContainsString('php.install', $failed);
        self::assertStringNotContainsString('db.apply', $failed);

        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];
        $api = $this->api('GET', '/admin/api/v1/tasks?state=done', $token);
        self::assertSame(200, $api->status());
        self::assertSame(['db.apply'], \array_column($this->json($api)['data']['tasks'], 'op'));

        $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $code = $this->app->container()->get(\App\Modules\Operations\Commands\TaskCommands::class)->list(new Output($out, $err), 'running');
        \rewind($out);
        self::assertSame(0, $code);
        self::assertStringContainsString('backup.run', (string) \stream_get_contents($out));

        $this->server->down = true;
        self::assertSame(503, $this->api('GET', '/admin/api/v1/tasks', $token)->status());
    }

    // -------------------------------------------------------------------------

    private function services(): Services
    {
        return $this->app->container()->get(Services::class);
    }

    private function watchdog(): Watchdog
    {
        return $this->app->container()->get(Watchdog::class);
    }

    private function held(string $unit): bool
    {
        foreach ($this->services()->list() ?? [] as $s) {
            if ($s['unit'] === $unit) {
                return $s['hold'];
            }
        }

        return false;
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
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
