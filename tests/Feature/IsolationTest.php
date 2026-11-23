<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Security\Commands\IsolationCommands;
use App\Modules\Security\Isolation\Isolation;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M9d: account isolation: /proc hiding other people's processes and the
 * kernel's protection of links and files in /tmp, through the Admin panel,
 * the API and the command line.
 */
final class IsolationTest extends TestCase
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

    public function test_both_are_on_unless_somebody_turned_them_off(): void
    {
        self::assertSame(['hidepid' => true, 'links' => true], $this->isolation()->settings());

        $admin = $this->admin();
        $page = $admin->get('/admin/security/isolation');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Account isolation', $page->body());
        self::assertStringContainsString('does not have what was chosen', $page->body(), 'the server has neither yet: said, not hidden');
        self::assertStringContainsString('href="/admin/security/isolation"', $admin->get('/admin')->body(), 'it is in the menu');
    }

    public function test_saving_sends_both_settings_to_the_server_and_keeps_them(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/security/isolation', ['hidepid' => '1', 'links' => '1']);

        self::assertTrue($this->server->isolation['hidepid'] && $this->server->isolation['links']);
        self::assertSame([['hidepid' => true, 'links' => true]], $this->server->isolationApplied);
        self::assertStringNotContainsString('does not have what was chosen', $admin->get('/admin/security/isolation')->body());
        self::assertContains('isolation.settings:ok', $this->audited('isolation.settings'));

        // An unticked box is off.
        $admin->post('/admin/security/isolation', ['links' => '1']);
        self::assertSame(['hidepid' => false, 'links' => true], $this->isolation()->settings());
        self::assertFalse($this->server->isolation['hidepid']);
        self::assertTrue($this->server->isolation['links']);
    }

    public function test_a_server_that_refuses_changes_nothing_in_the_panel(): void
    {
        $this->server->isolation['refuse'] = true;
        $admin = $this->admin();
        $admin->post('/admin/security/isolation', ['links' => '1']);

        self::assertSame(['hidepid' => true, 'links' => true], $this->isolation()->settings(), 'what was chosen is not recorded when the server did not do it');
        self::assertStringContainsString('permission denied', $admin->get('/admin/security/isolation')->body());
        self::assertContains('isolation.settings:failed', $this->audited('isolation.settings'));
    }

    public function test_only_root_may_change_it_and_the_api_does_the_same(): void
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));
        self::assertSame(403, $this->signedIn('admin', 'staff', 'staff-password-123')->get('/admin/security/isolation')->status());

        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];
        $show = $this->api('GET', '/admin/api/v1/isolation', $token);
        self::assertSame(200, $show->status());
        self::assertTrue($this->json($show)['data']['settings']['hidepid']);

        $put = $this->api('PUT', '/admin/api/v1/isolation', $token, ['hidepid' => false]);
        self::assertSame(200, $put->status(), $put->body());
        self::assertSame(['hidepid' => false, 'links' => true], $this->isolation()->settings());
        self::assertFalse($this->server->isolation['hidepid']);

        $this->server->isolation['refuse'] = true;
        self::assertSame(502, $this->api('PUT', '/admin/api/v1/isolation', $token, ['hidepid' => true])->status());
        $this->server->down = true;
        self::assertSame(503, $this->api('PUT', '/admin/api/v1/isolation', $token, ['links' => false])->status());
    }

    public function test_the_command_line_shows_changes_and_sends_again(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(IsolationCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('sync');
        self::assertSame(0, $code, $text);
        self::assertTrue($this->server->isolation['hidepid'] && $this->server->isolation['links'], 'sync brings the server to what the panel has');

        [$code, $text] = $run('set', 'off', 'on');
        self::assertSame(0, $code, $text);
        self::assertFalse($this->server->isolation['hidepid']);
        [$code, $text] = $run('set', 'maybe');
        self::assertSame(1, $code);
        self::assertStringContainsString('"on" or "off"', $text);
        [$code] = $run('set');
        self::assertSame(1, $code);

        [$code, $text] = $run('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('chosen off, server off', $text);
        self::assertStringContainsString('Link protections: chosen on, server on', $text);
        self::assertStringContainsString('fs.protected_fifos', $text);

        $this->server->down = true;
        [$code, $text] = $run('status');
        self::assertSame(1, $code);
        self::assertStringContainsString('not answering', $text);
    }

    // -------------------------------------------------------------------------

    private function isolation(): Isolation
    {
        return $this->app->container()->get(Isolation::class);
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
