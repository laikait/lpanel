<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Commands\CronCommands;
use App\Modules\Operations\Cron\CronJobs;
use App\Modules\Operations\Cron\CronRules;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M10b: an account's scheduled jobs: through the User panel, the API and the
 * command line; what the server is sent, and when the account or its package
 * means it should run nothing.
 */
final class CronTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private Browser $owner;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->makeAccount('shop', $this->packageFor(null, 'Starter', 1024, ['domains', 'cron']), null);
        $this->owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $this->server->cronApplied = [];
    }

    public function test_the_owner_schedules_and_removes_jobs_and_the_server_gets_the_whole_list(): void
    {
        $page = $this->owner->get('/panel/cron');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('No jobs yet', $page->body());
        self::assertStringContainsString('href="/panel/cron"', $this->owner->get('/panel')->body(), 'it is in the menu');

        self::assertSame(303, $this->owner->post('/panel/cron', ['schedule' => '  */15   * * * *', 'command' => 'php /home/shop/public_html/cron.php'])->status());
        $this->owner->post('/panel/cron', ['schedule' => '@daily', 'command' => '/home/shop/bin/backup']);

        self::assertSame([
            ['schedule' => '*/15 * * * *', 'command' => 'php /home/shop/public_html/cron.php'],
            ['schedule' => '@daily', 'command' => '/home/shop/bin/backup'],
        ], $this->server->cron['shop'], 'the server has both, the schedule tidied');
        self::assertFalse($this->server->cronApplied[1]['suspended']);
        self::assertStringContainsString('/home/shop/bin/backup', $this->owner->get('/panel/cron')->body());

        $id = $this->jobs()->forAccount($this->accountId())[0]['id'];
        $this->owner->post('/panel/cron/' . $id . '/delete');
        self::assertSame([['schedule' => '@daily', 'command' => '/home/shop/bin/backup']], $this->server->cron['shop']);

        $actions = \array_column($this->auditRows(), 'action');
        self::assertContains('cron.add', $actions);
        self::assertContains('cron.remove', $actions);
    }

    public function test_bad_jobs_are_shown_on_the_page_and_never_reach_the_server(): void
    {
        foreach ([
            ['* * * * *', 'true', 'every minute'],
            ['*/2 * * * *', 'true', 'every two minutes'],
            ['0,3 * * * *', 'true', 'close together'],
            ['0 * * *', 'true', 'four fields'],
            ['0 25 * * *', 'true', 'an hour of 25'],
            ['0 * * * *', '', 'no command'],
            ['0 * * * *', ' true', 'spaces round'],
            ['0 * * * *', "true\n* * * * * root id", 'a second line'],
        ] as [$schedule, $command, $why]) {
            $response = $this->owner->post('/panel/cron', ['schedule' => $schedule, 'command' => $command]);
            self::assertSame(422, $response->status(), $why);
        }

        self::assertSame([], $this->server->cronApplied);
        $body = $this->owner->post('/panel/cron', ['schedule' => '* * * * *', 'command' => 'true'])->body();
        self::assertStringContainsString('at most once every 5 minutes', $body);
        self::assertStringContainsString('value="* * * * *"', $body, 'what was typed is kept');
        self::assertSame([], $this->jobs()->forAccount($this->accountId()));
    }

    public function test_an_account_has_at_most_twenty_jobs(): void
    {
        for ($i = 0; $i < CronRules::MAX_JOBS; $i++) {
            self::assertSame(303, $this->owner->post('/panel/cron', ['schedule' => '0 * * * *', 'command' => 'echo ' . $i])->status());
        }

        $over = $this->owner->post('/panel/cron', ['schedule' => '0 * * * *', 'command' => 'echo more']);
        self::assertSame(422, $over->status());
        self::assertStringContainsString('at most 20 jobs', $over->body());
    }

    public function test_a_server_that_refuses_leaves_no_job_behind(): void
    {
        $this->server->cronRefuse = 'job 1: a job may run at most every 5 minutes';
        $response = $this->owner->post('/panel/cron', ['schedule' => '0 * * * *', 'command' => 'true']);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('may run at most every 5 minutes', $response->body());
        self::assertSame([], $this->jobs()->forAccount($this->accountId()), 'what the server did not take is not kept');
        self::assertContains('cron.add:failed', $this->audited('cron.add'));

        // And a removal the server refuses stays.
        $this->server->cronRefuse = null;
        $this->owner->post('/panel/cron', ['schedule' => '0 * * * *', 'command' => 'true']);
        $id = $this->jobs()->forAccount($this->accountId())[0]['id'];
        $this->server->cronRefuse = 'broken';
        $this->owner->post('/panel/cron/' . $id . '/delete');
        self::assertCount(1, $this->jobs()->forAccount($this->accountId()));
    }

    public function test_a_suspended_account_or_a_package_without_cron_runs_nothing_but_keeps_its_jobs(): void
    {
        $this->owner->post('/panel/cron', ['schedule' => '0 * * * *', 'command' => 'true']);
        self::assertArrayHasKey('shop', $this->server->cron);

        $admin = $this->signedIn('admin', 'root', 'root-password-123');
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];

        self::assertSame(200, $this->api('POST', '/admin/api/v1/accounts/shop/suspend', $token)->status());
        self::assertArrayNotHasKey('shop', $this->server->cron, 'suspended: the file goes');
        self::assertCount(1, $this->jobs()->forAccount($this->accountId()), 'the panel keeps the list');

        self::assertSame(200, $this->api('POST', '/admin/api/v1/accounts/shop/unsuspend', $token)->status());
        self::assertArrayHasKey('shop', $this->server->cron, 'and it comes back');
        unset($admin);
    }

    public function test_the_api_does_the_same(): void
    {
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];

        $made = $this->api('POST', '/admin/api/v1/accounts/shop/cron', $token, ['schedule' => '30 3 * * 1-5', 'command' => 'php artisan schedule:run']);
        self::assertSame(201, $made->status(), $made->body());
        $id = $this->json($made)['data']['id'];
        self::assertSame('30 3 * * 1-5', $this->server->cron['shop'][0]['schedule']);

        $list = $this->api('GET', '/admin/api/v1/accounts/shop/cron', $token);
        self::assertSame(200, $list->status());
        self::assertSame('php artisan schedule:run', $this->json($list)['data']['jobs'][0]['command']);

        self::assertSame(422, $this->api('POST', '/admin/api/v1/accounts/shop/cron', $token, ['schedule' => '* * * * *', 'command' => 'true'])->status());
        self::assertSame(404, $this->api('POST', '/admin/api/v1/accounts/nobody/cron', $token, ['schedule' => '0 * * * *', 'command' => 'true'])->status());
        self::assertSame(404, $this->api('DELETE', '/admin/api/v1/accounts/shop/cron/99999', $token)->status());
        self::assertSame(204, $this->api('DELETE', '/admin/api/v1/accounts/shop/cron/' . $id, $token)->status());

        $this->server->cronRefuse = 'no';
        self::assertSame(502, $this->api('POST', '/admin/api/v1/accounts/shop/cron', $token, ['schedule' => '0 * * * *', 'command' => 'true'])->status());
    }

    public function test_the_command_line_does_all_of_it(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(CronCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('list', 'shop');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('no scheduled jobs', $text);

        [$code, $text] = $run('add', 'shop', '*/10 * * * *', 'php /home/shop/x.php');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('add', 'shop', '* * * * *', 'true');
        self::assertSame(1, $code);
        self::assertStringContainsString('at most once every 5 minutes', $text);
        [$code, $text] = $run('add', 'nobody', '0 * * * *', 'true');
        self::assertSame(1, $code);

        [$code, $text] = $run('list', 'shop');
        self::assertStringContainsString('*/10 * * * *', $text);
        $id = (string) $this->jobs()->forAccount($this->accountId())[0]['id'];
        [$code, $text] = $run('remove', 'shop', $id);
        self::assertSame(0, $code, $text);
        [$code] = $run('remove', 'shop', $id);
        self::assertSame(1, $code);
        self::assertArrayNotHasKey('shop', $this->server->cron);
    }

    public function test_the_rules_are_the_same_in_php_as_in_the_agent(): void
    {
        foreach (['*/5 * * * *', '0 * * * *', '0,30 * * * *', '5-55/10 * * * *', '0 3 * * 7', '0,2 9 * * *', '@hourly', '@weekly', '15 4 1,15 * *'] as $ok) {
            self::assertSame([], CronRules::check($ok, 'true'), $ok);
        }

        foreach (['* * * * *', '*/2 * * * *', '0,3 * * * *', '58,1 * * * *', '0 * * *', '0 * * * * *', '0 * * * mon', '0 24 * * *', '0 0 1 0 *', '10-5 * * * *', '@reboot', '*/0 * * * *', '-1 * * * *'] as $bad) {
            self::assertNotSame([], CronRules::check($bad, 'true'), $bad);
        }
    }

    // -------------------------------------------------------------------------

    private function jobs(): CronJobs
    {
        return $this->app->container()->get(CronJobs::class);
    }

    private function accountId(): int
    {
        return (int) ($this->app->container()->get(\App\Modules\Accounts\Accounts::class)->findByUsernameUnscoped('shop')['id'] ?? 0);
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
