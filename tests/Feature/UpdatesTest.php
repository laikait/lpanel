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
use App\Modules\Operations\Commands\UpdateCommands;
use App\Modules\Operations\Updates\Updates;
use App\Modules\Operations\Updates\UpdateSettings;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M10c: updates of the operating system, PHP and LPanel itself; reboots; what
 * is installed every night by itself.
 */
final class UpdatesTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private string $versionFile;

    /** @var list<string> what ops.update_event announced, as "kind: detail" */
    private array $events = [];

    protected function setUp(): void
    {
        // The release running: 0.1.0 (a development copy has none).
        $this->versionFile = (string) \tempnam(\sys_get_temp_dir(), 'lpv');
        \file_put_contents($this->versionFile, "0.1.0\n");
        $this->app = $this->app(['operations' => ['version_file' => $this->versionFile]]);
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->app->container()->get(HookEngine::class)->add('ops.update_event', function (string $kind, string $detail): void {
            $this->events[] = $kind . ': ' . $detail;
        }, 10, 'test', 2);
    }

    protected function tearDown(): void
    {
        @\unlink($this->versionFile);
    }

    public function test_a_check_finds_updates_and_the_page_and_the_bell_show_them(): void
    {
        $admin = $this->admin();
        $page = $admin->get('/admin/updates');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Not checked yet', $page->body());
        self::assertStringContainsString('href="/admin/updates"', $admin->get('/admin')->body(), 'it is in the menu');

        $admin->post('/admin/updates/check');
        self::assertSame('running', $this->updates()->busy()['state'] ?? null, 'the agent checks in the background');
        $body = $admin->get('/admin/updates')->body(); // the page settles it
        self::assertNull($this->updates()->busy());

        foreach (['openssl-libs', '1:3.0.7-27.el9', 'Important', 'php83-php-common', 'PHP 8.3', '8.3.15', '0.2.0 is out', 'Upgrade to 0.2.0', 'Install security updates'] as $shown) {
            self::assertStringContainsString($shown, $body);
        }

        $dash = $admin->get('/admin')->body();
        self::assertStringContainsString('Security updates are waiting', $dash);
        self::assertStringContainsString('A new release of LPanel is out', $dash);
        self::assertSame(['available: 1 security updates are waiting', 'available: LPanel 0.2.0 is out'], $this->events);

        // The same findings again are not announced again.
        $this->updates()->check(null, null);
        $this->updates()->settle();
        self::assertCount(2, $this->events);
    }

    public function test_updates_are_installed_by_scope_with_the_exclusions_and_one_at_a_time(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/updates/settings', ['os' => 'security', 'php' => '1', 'hour' => '4', 'exclude' => 'kernel* mariadb-server', 'panel' => 'notify', 'reboot' => '0']);
        self::assertSame(['kernel*', 'mariadb-server'], $this->settings()->get()['exclude']);

        $this->server->updatesHold = true;
        $admin->post('/admin/updates/apply', ['scope' => 'security']);
        self::assertSame([['scope' => 'security', 'exclude' => ['kernel*', 'mariadb-server']]], $this->server->updatesApplied);

        $admin->post('/admin/updates/apply', ['scope' => 'all']);
        self::assertCount(1, $this->server->updatesApplied, 'one at a time');
        self::assertStringContainsString('Something is being checked or installed already', $admin->get('/admin/updates')->body());

        $this->server->updatesHold = false;
        $this->updates()->settle();
        $run = $this->updates()->runs(1)[0];
        self::assertSame('done', $run['state']);
        self::assertSame('openssl-libs', $run['result']['upgraded'][0]['name']);
        self::assertSame(['updates.apply:ok', 'updates.applied:ok'], $this->audited('updates.appl'));
        self::assertSame(['applied: 1 packages updated (security)'], $this->events);
        self::assertStringContainsString('1 packages updated', $admin->get('/admin/updates')->body());

        $admin->post('/admin/updates/apply', ['scope' => 'packages', 'packages' => ['php83-php-common']]);
        self::assertSame(['php83-php-common'], $this->applied()[1]['packages']);
        $this->updates()->settle();

        // What is not a package name never reaches the agent.
        foreach ([['scope' => 'packages', 'packages' => ['--setopt=gpgcheck=0']], ['scope' => 'packages'], ['scope' => 'everything']] as $bad) {
            $admin->post('/admin/updates/apply', $bad);
        }

        self::assertCount(2, $this->server->updatesApplied);

        // An update the server refuses is a failure, on the bell.
        $this->server->failNext = ['op' => 'updates.apply', 'code' => 'updates_dnf', 'message' => 'dnf upgrade failed: Error: Transaction test error'];
        $admin->post('/admin/updates/apply', ['scope' => 'all']);
        $this->updates()->settle();
        self::assertSame('failed', $this->updates()->runs(1)[0]['state']);
        self::assertStringContainsString('An update failed', $admin->get('/admin')->body());
        self::assertStringContainsString('Transaction test error', $admin->get('/admin/updates')->body());
    }

    public function test_a_panel_upgrade_waits_for_its_script_and_a_rollback_is_a_failure(): void
    {
        $admin = $this->admin();
        $this->checked();

        $admin->post('/admin/updates/panel', ['version' => '0.2.0']);
        self::assertSame([['version' => '0.2.0']], $this->server->upgrades);
        $this->updates()->settle();
        self::assertSame('installing', $this->updates()->busy()['state'] ?? null, 'the release is checked and its script started');
        self::assertStringContainsString('installing since', $admin->get('/admin/updates')->body());

        // The script is still going (and the agent may be restarting): still installing.
        $this->server->down = true;
        $this->updates()->settle();
        $this->server->down = false;
        $this->updates()->settle();
        self::assertSame('installing', $this->updates()->busy()['state'] ?? null);

        $this->server->upgradeStatus = ['state' => 'done', 'target' => '0.2.0', 'from' => '0.1.0', 'running' => false, 'installed' => '0.2.0', 'log' => ['==> 0.2.0 is installed']];
        $this->updates()->settle();
        self::assertNull($this->updates()->busy());
        self::assertSame('done', $this->updates()->runs(1)[0]['state']);
        self::assertContains('upgraded: LPanel 0.2.0 is installed', $this->events);
        self::assertContains('updates.upgraded:ok', $this->audited('updates.upgraded'));

        // Once more, and this time install.sh fails and the old release is put back.
        $admin->post('/admin/updates/panel', ['version' => '0.2.0']);
        $this->updates()->settle();
        $this->server->upgradeStatus = ['state' => 'rolled_back', 'target' => '0.2.0', 'from' => '0.1.0', 'running' => false, 'installed' => '0.1.0', 'error' => 'install.sh of 0.2.0 exited with 1; 0.1.0 was put back', 'log' => ['==> install.sh failed (1); putting 0.1.0 back', '==> 0.1.0 is back']];
        $this->updates()->settle();
        $run = $this->updates()->runs(1)[0];
        self::assertSame('failed', $run['state']);
        self::assertStringContainsString('0.1.0 was put back', (string) $run['error']);
        $page = $admin->get('/admin/updates')->body();
        self::assertStringContainsString('0.1.0 was put back', $page);
        self::assertStringContainsString('0.1.0 is back', $page, 'the end of the log');
        self::assertStringContainsString('An update failed', $admin->get('/admin')->body());

        // Not newer than what runs, or not a release: refused before the agent.
        $admin->post('/admin/updates/panel', ['version' => '0.1.0']);
        $admin->post('/admin/updates/panel', ['version' => '../0.3']);
        self::assertCount(2, $this->server->upgrades);

        // The channel moved on meanwhile: the agent refuses, and says what it offers now.
        [, $why] = $this->updates()->upgrade(null, null, '0.2.1');
        self::assertNull($why);
        $this->updates()->settle();
        self::assertStringContainsString('now offers 0.2.0', (string) $this->updates()->runs(1)[0]['error']);
    }

    public function test_every_night_what_the_settings_allow_is_installed_by_itself(): void
    {
        $today = (new \DateTimeImmutable('today'));
        $day = $today->setTime(14, 0);
        $night = static fn(int $minute): \DateTimeImmutable => $today->setTime(3, $minute);

        self::assertSame('check', $this->updates()->due($day), 'a check whenever none was made in the last day');
        self::assertNull($this->updates()->due($day), 'one at a time');
        $this->updates()->settle();
        self::assertNull($this->updates()->due($day), 'nothing is installed outside the night\'s hour');

        self::assertSame('security', $this->updates()->due($night(5)));
        $this->updates()->settle();
        self::assertSame('check', $this->updates()->due($night(6)), 'after an update, a check');
        $this->updates()->settle();
        self::assertSame('php', $this->updates()->due($night(7)), 'PHP has no errata: its updates are installed on their own');
        $this->updates()->settle();
        self::assertSame('check', $this->updates()->due($night(8)));
        $this->updates()->settle();
        self::assertNull($this->updates()->due($night(9)), 'LPanel only notifies, and no reboot is needed');
        self::assertSame(['security', 'php'], \array_column($this->server->updatesApplied, 'scope'));

        // Install LPanel's releases too, and reboot when needed.
        $this->settings()->save(['os' => 'security', 'php' => true, 'hour' => 3, 'exclude' => [], 'panel' => 'install', 'reboot' => true], null, null);
        self::assertSame('panel', $this->updates()->due($night(10)));
        $this->server->upgradeStatus = ['state' => 'done', 'target' => '0.2.0', 'from' => '0.1.0', 'running' => false, 'installed' => '0.2.0', 'log' => []];
        $this->updates()->settle();
        $this->updates()->settle();
        $this->server->updatesFound['reboot'] = ['known' => true, 'needed' => true, 'why' => ['kernel'], 'scheduled' => false];
        self::assertSame('check', $this->updates()->due($night(11)), 'after the upgrade, a check');
        $this->updates()->settle();
        self::assertSame('reboot', $this->updates()->due($night(12)));
        self::assertSame([false], $this->server->reboots);
        self::assertNull($this->updates()->due($night(13)), 'once a night');

        // Nothing at all when the settings say off.
        $this->settings()->save(['os' => 'off', 'php' => false, 'hour' => 3, 'exclude' => [], 'panel' => 'notify', 'reboot' => false], null, null);
        $tomorrow = $night(5)->modify('+1 day');
        $this->assertNothingInstalledAt($tomorrow);
    }

    public function test_a_failed_check_is_tried_again_after_an_hour_not_every_minute(): void
    {
        $this->server->failNext = ['op' => 'updates.check', 'code' => 'updates_dnf', 'message' => 'dnf could not read the repositories'];
        self::assertSame('check', $this->updates()->due());
        $this->updates()->settle();
        self::assertSame('failed', $this->updates()->runs(1)[0]['state']);
        self::assertNull($this->updates()->due());
        self::assertCount(1, $this->updates()->runs());
    }

    public function test_rebooting_needs_the_word_and_can_be_called_off(): void
    {
        $admin = $this->admin();
        $admin->post('/admin/updates/reboot', ['confirm' => 'yes']);
        self::assertSame([], $this->server->reboots);

        $admin->post('/admin/updates/reboot', ['confirm' => 'reboot']);
        self::assertSame([false], $this->server->reboots);
        self::assertContains('reboot: the server reboots in a minute', $this->events);
        $admin->post('/admin/updates/reboot', ['cancel' => '1']);
        self::assertSame([false, true], $this->server->reboots);
        self::assertSame(['updates.reboot:ok', 'updates.reboot_cancel:ok'], $this->audited('updates.reboot'));
    }

    public function test_only_root_looks_after_updates(): void
    {
        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));
        $staff = $this->signedIn('admin', 'staff', 'staff-password-123');
        self::assertSame(403, $staff->get('/admin/updates')->status());
        self::assertSame(403, $staff->post('/admin/updates/reboot', ['confirm' => 'reboot'])->status());
        self::assertStringNotContainsString('href="/admin/updates"', $staff->get('/admin')->body());
        self::assertSame([], $this->server->reboots);
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];

        $check = $this->api('POST', '/admin/api/v1/updates/check', $token);
        self::assertSame(202, $check->status(), $check->body());
        $id = (int) $this->json($check)['data']['id'];

        $info = $this->api('GET', '/admin/api/v1/updates', $token);
        self::assertSame(200, $info->status());
        $data = $this->json($info)['data'];
        self::assertSame('0.1.0', $data['installed']);
        self::assertSame(1, $data['latest']['security']);
        self::assertTrue($data['latest']['panel']['newer']);
        self::assertSame('done', $this->json($this->api('GET', '/admin/api/v1/updates/runs/' . $id, $token))['data']['state']);

        $this->server->updatesHold = true;
        self::assertSame(202, $this->api('POST', '/admin/api/v1/updates/apply', $token, ['scope' => 'php'])->status());
        self::assertSame(409, $this->api('POST', '/admin/api/v1/updates/apply', $token, ['scope' => 'all'])->status());
        $this->server->updatesHold = false;
        $this->updates()->settle();
        self::assertSame(422, $this->api('POST', '/admin/api/v1/updates/apply', $token, ['scope' => 'everything'])->status());
        self::assertSame(422, $this->api('POST', '/admin/api/v1/updates/panel', $token, ['version' => '0.0.9'])->status());

        $set = $this->api('PUT', '/admin/api/v1/updates/settings', $token, ['os' => 'all', 'hour' => 5]);
        self::assertSame(200, $set->status(), $set->body());
        self::assertSame('all', $this->json($set)['data']['os']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/updates/settings', $token, ['hour' => 25])->status());
        self::assertSame(5, $this->settings()->get()['hour']);

        self::assertSame(200, $this->api('POST', '/admin/api/v1/updates/reboot', $token, ['cancel' => true])->status());
        self::assertSame(404, $this->api('GET', '/admin/api/v1/updates/runs/9999', $token)->status());

        $this->server->down = true;
        self::assertSame(503, $this->api('POST', '/admin/api/v1/updates/check', $token)->status());
    }

    public function test_the_command_line_does_all_of_it(): void
    {
        [$code, $text] = $this->cli('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('never (updates:check)', $text);

        [$code, $text] = $this->cli('check', true);
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('2 packages can be updated, 1 with a security erratum', $text);
        self::assertStringContainsString('LPanel 0.2.0 is out', $text);

        [$code, $text] = $this->cli('apply', true, false, null, false);
        self::assertSame(0, $code, $text);
        $this->updates()->settle();
        self::assertSame('security', $this->server->updatesApplied[0]['scope']);

        [$code, $text] = $this->cli('apply', false, false, 'nope;rm');
        self::assertSame(1, $code);
        self::assertStringContainsString('Name between 1 and 200', $text);

        [$code, $text] = $this->cli('setSettings', 'all', 'no', '2', 'kernel*,glibc', 'install', 'yes');
        self::assertSame(0, $code, $text);
        self::assertSame(['os' => 'all', 'php' => false, 'hour' => 2, 'exclude' => ['kernel*', 'glibc'], 'panel' => 'install', 'reboot' => true], $this->settings()->get());
        [$code] = $this->cli('setSettings', 'sometimes');
        self::assertSame(1, $code);

        [$code, $text] = $this->cli('reboot');
        self::assertSame(1, $code, 'not without --yes');
        self::assertSame([], $this->server->reboots);
        [$code] = $this->cli('reboot', true);
        self::assertSame(0, $code);

        [$code, $text] = $this->cli('panel');
        self::assertSame(0, $code, $text);
        self::assertSame([['version' => '0.2.0']], $this->server->upgrades);

        [$code, $text] = $this->cli('status');
        self::assertStringContainsString('upgrade to 0.2.0', $text);
        self::assertStringContainsString('update (security)', $text);
    }

    // -------------------------------------------------------------------------

    private function assertNothingInstalledAt(\DateTimeImmutable $when): void
    {
        $before = \count($this->server->updatesApplied);

        for ($i = 0; $i < 4; $i++) {
            $started = $this->updates()->due($when);
            $this->updates()->settle();
            self::assertContains($started, [null, 'check']);
        }

        self::assertCount($before, $this->server->updatesApplied);
    }

    /** @return list<array<string, mixed>> */
    private function applied(): array
    {
        return $this->server->updatesApplied;
    }

    private function checked(): void
    {
        $this->updates()->check(null, null);
        $this->updates()->settle();
    }

    /** @return array{0: int, 1: string} */
    private function cli(string $method, mixed ...$args): array
    {
        $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $code = $this->app->container()->get(UpdateCommands::class)->{$method}(new Output($out, $err), ...$args);
        \rewind($out);
        \rewind($err);

        return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
    }

    private function updates(): Updates
    {
        return $this->app->container()->get(Updates::class);
    }

    private function settings(): UpdateSettings
    {
        return $this->app->container()->get(UpdateSettings::class);
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    /** @return list<string> "action:outcome" of the audit entries whose action starts so, oldest first */
    private function audited(string $prefix): array
    {
        $out = [];

        foreach ($this->auditRows() as $row) {
            if (\str_starts_with((string) ($row['action'] ?? ''), $prefix)) {
                $out[] = $row['action'] . ':' . $row['outcome'];
            }
        }

        return $out;
    }
}
