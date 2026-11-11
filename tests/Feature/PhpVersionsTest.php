<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\PhpVersions;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class PhpVersionsTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private Browser $alice;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $users = $this->app->container()->get(PanelUsers::class);
        $users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
        $users->create(Kind::Reseller, 'rex', 'Rex', null, new Secret('rex-password-1234'));
        $this->alice = $this->signedIn('admin', 'alice', 'alice-password-123');
    }

    public function test_an_administrator_installs_a_version_and_it_finishes_in_the_background(): void
    {
        $page = $this->alice->get('/admin/php');
        self::assertSame(200, $page->status());
        foreach (['PHP 8.3', 'PHP 8.2', 'Runs the panel', 'Available to install', 'PHP 8.4', 'End of life'] as $shown) {
            self::assertStringContainsString($shown, $page->body());
        }

        self::assertSame(303, $this->alice->post('/admin/php/84/install')->status());
        $job = $this->php()->running();
        self::assertNotNull($job, 'the job is recorded before the agent finishes');
        self::assertSame(['php.install', '84'], [$job['op'], $job['version']]);
        // While the agent cannot answer, the job shows as in progress and the page looks again by itself.
        $this->server->down = true;
        $page = $this->alice->get('/admin/php')->body();
        self::assertStringContainsString('Installing PHP 8.4.', $page);
        self::assertStringContainsString('data-reload', $page);
        self::assertNotNull($this->php()->running());

        // Then it answers: the page asks again and sees it finished.
        $this->server->down = false;
        $page = $this->alice->get('/admin/php')->body();
        self::assertNull($this->php()->running());
        self::assertSame('done', $this->php()->job((int) $job['id'])['state'] ?? null);
        self::assertContains('84', $this->server->php);
        self::assertSame(1, $this->server->ran('php.install'), 'asking again never installs twice');
        self::assertStringContainsString('/admin/php/84/extensions', $page, '8.4 is listed as installed');
        self::assertStringContainsString('Install PHP 8.4', $page);

        $audit = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'php.install'));
        self::assertCount(2, $audit, 'started, then finished');
        self::assertSame('alice', $audit[0]['actor_name']);
        self::assertStringContainsString('"state":"done"', (string) $audit[1]['details']);
    }

    public function test_one_job_at_a_time(): void
    {
        // The agent takes the job, then cannot be asked about it for a while.
        $this->alice->post('/admin/php/84/install');
        $this->server->down = true;

        $this->alice->post('/admin/php/85/install');
        self::assertSame(1, $this->db()->table('php_jobs')->count(), 'the second waits for the first');
        self::assertStringContainsString('Another PHP change is still in progress', $this->alice->get('/admin/php')->body());
    }

    public function test_the_panels_version_versions_in_use_and_the_default_stay(): void
    {
        $package = $this->packageFor(null, 'Starter', 1024, ['domains']);
        $this->makeAccount('shop', $package, null);
        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        self::assertSame(200, $this->api('PUT', '/admin/api/v1/accounts/shop/domains/shop.example.com', $token, ['php' => '8.2'])->status());

        $this->alice->post('/admin/php/83/remove');
        $this->alice->post('/admin/php/82/remove');
        self::assertSame(0, $this->server->ran('php.remove'), 'refused before asking the agent');
        self::assertSame(409, $this->api('DELETE', '/admin/api/v1/php/8.3', $token)->status());
        self::assertSame('in_use', $this->json($this->api('DELETE', '/admin/api/v1/php/8.2', $token))['error']);

        // A new default is what new sites get, and cannot be removed while it is.
        $this->alice->post('/admin/php/84/install');
        self::assertSame(303, $this->alice->post('/admin/php/default', ['version' => '84'])->status());
        $this->makeAccount('blog', $package, null);
        self::assertSame('84', $this->server->sites['blog']['sites'][0]['php']);
        $this->api('PUT', '/admin/api/v1/accounts/blog/domains/blog.example.com', $token, ['php' => '8.3']);
        self::assertSame('is_default', $this->json($this->api('DELETE', '/admin/api/v1/php/8.4', $token))['error']);
        self::assertSame(409, $this->api('PUT', '/admin/api/v1/php/default', $token, ['version' => '8.5'])->status());

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/php/default', $token, ['version' => '8.3'])->status());
        $removed = $this->api('DELETE', '/admin/api/v1/php/8.4', $token);
        self::assertSame(202, $removed->status());
        $job = $this->json($this->api('GET', '/admin/api/v1/php/jobs/' . $this->json($removed)['data']['id'], $token))['data'];
        self::assertSame('done', $job['state']);
        self::assertNotContains('84', $this->server->php);
        self::assertContains('php.default', \array_column($this->auditRows(), 'action'));
    }

    public function test_extensions_are_the_whole_set_and_the_panels_own_stay(): void
    {
        $this->alice->post('/admin/php/82/extensions', ['extensions' => ['gd', 'zip', 'redis']]);
        $job = $this->db()->table('php_jobs')->orderBy('id', 'desc')->first() ?? self::fail('no job');
        self::assertSame(['version' => '82', 'install' => ['redis'], 'remove' => ['mbstring']], \json_decode((string) $job['op_args'], true));
        $this->php()->settleAll();
        self::assertSame(['gd', 'redis', 'zip'], $this->server->extensions['82']);

        // The panel's own are disabled boxes, so the form never sends them; they stay.
        $before = $this->server->ran('php.extensions');
        $this->alice->post('/admin/php/83/extensions', []);
        self::assertSame($before, $this->server->ran('php.extensions'));

        $root = $this->app->container()->get(PanelUsers::class)->root()->identity;
        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $root->id, 'ops')['token'];
        self::assertSame('locked', $this->json($this->api('PUT', '/admin/api/v1/php/8.3/extensions', $token, ['extensions' => ['intl']]))['error']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/php/8.2/extensions', $token, ['extensions' => ['gd', 'php82-php-evil']])->status());
        self::assertSame(409, $this->api('POST', '/admin/api/v1/php/8.2', $token)->status(), 'installed already');
        self::assertSame(422, $this->api('POST', '/admin/api/v1/php/5.6', $token)->status());

        $list = $this->json($this->api('GET', '/admin/api/v1/php', $token))['data'];
        self::assertSame('8.3', $list['default']);
        $v82 = \array_values(\array_filter($list['versions'], static fn(array $v): bool => $v['version'] === '8.2'))[0];
        self::assertSame(['gd', 'redis', 'zip'], $v82['extensions']);
    }

    public function test_resellers_and_owners_cannot_change_the_servers_php(): void
    {
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        self::assertSame(403, $rex->get('/admin/php')->status());
        self::assertSame(403, $rex->post('/admin/php/84/install')->status());
        self::assertSame(0, $this->server->ran('php.install'));
        self::assertStringNotContainsString('/admin/php', $rex->get('/admin')->body(), 'not in a reseller\'s menu');
        self::assertStringContainsString('/admin/php', $this->alice->get('/admin')->body());

        $rexId = (string) ($this->app->container()->get(PanelUsers::class)->find('rex')['id'] ?? '');
        $token = $this->app->container()->get(ApiTokens::class)->issue($rexId, 'rex')['token'];
        self::assertSame(403, $this->api('GET', '/admin/api/v1/php', $token)->status());
    }

    public function test_an_agent_that_cannot_be_reached_is_said_plainly(): void
    {
        $this->server->down = true;
        $page = $this->alice->get('/admin/php');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('cannot be reached', $page->body());
        $this->alice->post('/admin/php/84/install');
        self::assertSame(0, $this->db()->table('php_jobs')->count());
    }

    private function php(): PhpVersions
    {
        return $this->app->container()->get(PhpVersions::class);
    }
}
