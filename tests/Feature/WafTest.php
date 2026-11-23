<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\ApiTokens;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Web\Domains;
use App\Modules\Web\SiteFeatures;
use App\Modules\Web\Waf\Waf;
use App\Modules\Web\Waf\WafCommands;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * M9c: the web application firewall (ModSecurity with the Core Rule Set):
 * each site's mode and the rules it does not want, the server's default mode
 * and strictness, and the log of what the rules disliked.
 */
final class WafTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private FakeServer $server;

    private Browser $owner;

    /** @var array<string, mixed> */
    private array $main;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-123');
        $this->server = new FakeServer();
        $this->agent($this->app, $this->server->agent());
        $this->makeAccount('shop', $this->packageFor(null, 'Starter', 1024, ['domains']), null);
        $this->owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $row = $this->app->container()->get(Domains::class)->findByName((int) $this->account()['id'], 'shop.example.com');
        self::assertNotNull($row);
        $this->main = $row;
    }

    public function test_a_site_that_has_not_chosen_is_in_log_only_mode(): void
    {
        $this->owner->get('/panel/domains/' . $this->main['id']);
        $this->owner->post($this->base(), ['docroot' => 'public_html', 'php' => '83']);

        self::assertSame(['mode' => 'detect', 'exclude' => []], $this->server->sites['shop']['sites'][0]['waf'], 'nobody chose, so the safe default, not blocking');
    }

    public function test_the_owner_chooses_a_mode_and_the_rules_the_site_does_not_want(): void
    {
        $page = $this->owner->get($this->base())->body();
        self::assertStringContainsString('Server default (log only)', $page);

        self::assertSame(303, $this->owner->post($this->base() . '/waf', ['mode' => 'block', 'exclude' => '942100, 941160 942100'])->status());
        self::assertSame(['mode' => 'block', 'exclude' => [941160, 942100]], $this->server->sites['shop']['sites'][0]['waf']);

        $page = $this->owner->get($this->base())->body();
        self::assertStringContainsString('941160, 942100', $page);
        self::assertContains('domain.waf:ok', $this->audited('domain.waf'));

        // Back to following the server.
        $this->owner->post($this->base() . '/waf', ['mode' => 'default', 'exclude' => '']);
        self::assertSame(['mode' => 'detect', 'exclude' => []], $this->server->sites['shop']['sites'][0]['waf']);
        $row = $this->app->container()->get(Domains::class)->find((int) $this->account()['id'], (int) $this->main['id']);
        self::assertNull($row['waf'] ?? null, 'following the default is kept as nothing');
    }

    public function test_bad_choices_are_shown_and_never_reach_the_server(): void
    {
        $this->owner->post($this->base() . '/waf', ['mode' => 'block', 'exclude' => '942100']);
        $before = $this->server->sites['shop'];

        foreach ([['mode' => 'paranoid', 'exclude' => ''], ['mode' => 'block', 'exclude' => '9x42'], ['mode' => 'block', 'exclude' => '0'], ['mode' => 'block', 'exclude' => '-5'], ['mode' => 'block', 'exclude' => '100000000']] as $bad) {
            $response = $this->owner->post($this->base() . '/waf', $bad);
            self::assertSame(422, $response->status(), (string) \json_encode($bad));
        }

        $many = \implode(',', \range(900001, 900051));
        self::assertSame(422, $this->owner->post($this->base() . '/waf', ['mode' => 'block', 'exclude' => $many])->status());
        self::assertSame($before, $this->server->sites['shop'], 'nothing was sent');
        self::assertSame([[null, ['mode' => 'Web.error_waf_mode']]], [SiteFeatures::validateWaf('nope', '')], 'the rules are the same in the code');
    }

    public function test_the_server_default_reaches_every_site_that_has_not_chosen(): void
    {
        $admin = $this->admin();
        self::assertSame(200, $admin->get('/admin/waf')->status());
        self::assertStringContainsString('href="/admin/waf"', $admin->get('/admin')->body(), 'it is in the menu');

        $before = \count($this->server->wafScans);
        $admin->post('/admin/waf', ['mode' => 'block', 'paranoia' => '2']);
        self::assertSame(['default' => 'block', 'paranoia' => 2], $this->waf()->settings());
        self::assertSame(2, $this->server->waf['paranoia']);
        self::assertSame(['mode' => 'block', 'exclude' => []], $this->server->sites['shop']['sites'][0]['waf'], 'the default reaches the site now');
        self::assertContains('waf.default:ok', $this->audited('waf.default'));
        self::assertContains('waf.paranoia:ok', $this->audited('waf.paranoia'));

        // A site that chose for itself keeps its choice.
        $this->owner->post($this->base() . '/waf', ['mode' => 'off', 'exclude' => '']);
        $admin->post('/admin/waf', ['mode' => 'detect', 'paranoia' => '2']);
        self::assertSame('off', $this->server->sites['shop']['sites'][0]['waf']['mode']);
        self::assertSame($before, \count($this->server->wafScans));
    }

    public function test_the_firewall_cannot_be_set_on_a_server_that_has_none(): void
    {
        $this->server->waf['server'] = 'nginx';
        $this->server->waf['available'] = false;
        $admin = $this->admin();

        self::assertStringContainsString('no web application firewall', $admin->get('/admin/waf')->body());
        $admin->post('/admin/waf', ['mode' => 'block', 'paranoia' => '3']);
        self::assertSame(1, $this->server->waf['paranoia'], 'nothing changed on the server');
        self::assertSame(1, $this->waf()->settings()['paranoia']);
        self::assertContains('waf.paranoia:failed', $this->audited('waf.paranoia'));
    }

    public function test_what_the_rules_said_is_read_kept_and_shown(): void
    {
        $this->server->wafEvents = [
            ['time' => \gmdate('Y-m-d\TH:i:s\Z'), 'account' => 'shop', 'host' => 'shop.example.com', 'ip' => '198.51.100.9', 'uri' => '/search?q=<script>', 'blocked' => true, 'score' => 15, 'rules' => [['id' => 941100, 'msg' => 'XSS Attack Detected via libinjection'], ['id' => 941110, 'msg' => 'XSS Filter']]],
            ['time' => \gmdate('Y-m-d\TH:i:s\Z'), 'account' => 'other', 'host' => 'other.example.net', 'ip' => '203.0.113.5', 'uri' => '/', 'blocked' => false, 'score' => 5, 'rules' => [['id' => 920350, 'msg' => 'Host header is a numeric IP']]],
        ];

        $result = $this->waf()->scan();
        self::assertSame(['events' => 2, 'error' => null], $result);
        self::assertSame(['events' => 0, 'error' => null], $this->waf()->scan(), 'read once');
        self::assertSame(['offset' => 10, 'head' => 'w1'], $this->server->wafScans[1]['cursors']['shop']);

        $page = $this->owner->get($this->base())->body();
        self::assertStringContainsString('198.51.100.9', $page);
        self::assertStringContainsString('Rule 941100', $page);
        self::assertStringContainsString('Refused', $page);
        self::assertStringNotContainsString('203.0.113.5', $page, 'an owner sees their own sites only');

        $admin = $this->admin()->get('/admin/waf')->body();
        self::assertStringContainsString('203.0.113.5', $admin);
        self::assertStringContainsString('198.51.100.9', $admin);
        self::assertSame(['day' => 2, 'blocked' => 1], $this->waf()->counts());
    }

    public function test_only_administrators_see_the_server_page_and_the_api_does_the_same(): void
    {
        self::assertContains($this->owner->get('/admin/waf')->status(), [302, 303, 403, 404]);

        $this->app->container()->get(PanelUsers::class)->create(Kind::Admin, 'staff', 'Staff Person', null, new Secret('staff-password-123'));
        self::assertSame(200, $this->signedIn('admin', 'staff', 'staff-password-123')->get('/admin/waf')->status(), 'an administrator who is not root may');

        $token = $this->app->container()->get(ApiTokens::class)->issue((string) $this->app->container()->get(PanelUsers::class)->root()->identity->id, 'ops')['token'];
        $show = $this->api('GET', '/admin/api/v1/waf', $token);
        self::assertSame(200, $show->status());
        self::assertSame('3.3.5', $this->json($show)['data']['waf']['rules']);

        self::assertSame(200, $this->api('PUT', '/admin/api/v1/waf', $token, ['mode' => 'block', 'paranoia' => 3])->status());
        self::assertSame(['default' => 'block', 'paranoia' => 3], $this->waf()->settings());
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/waf', $token, ['mode' => 'maybe'])->status());
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/waf', $token, ['paranoia' => 9])->status());

        $site = $this->api('PUT', '/admin/api/v1/accounts/shop/domains/shop.example.com/waf', $token, ['mode' => 'block', 'exclude' => [942100, 920350]]);
        self::assertSame(200, $site->status(), $site->body());
        self::assertSame(['mode' => 'block', 'exclude' => [920350, 942100]], $this->server->sites['shop']['sites'][0]['waf']);
        self::assertSame(422, $this->api('PUT', '/admin/api/v1/accounts/shop/domains/shop.example.com/waf', $token, ['mode' => 'block', 'exclude' => ['abc']])->status());
    }

    public function test_the_command_line_shows_and_changes_it(): void
    {
        $run = function (string $method, mixed ...$args): array {
            $out = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $err = \fopen('php://memory', 'w+') ?: throw new \RuntimeException();
            $code = $this->app->container()->get(WafCommands::class)->{$method}(new Output($out, $err), ...$args);
            \rewind($out);
            \rewind($err);

            return [$code, (string) \stream_get_contents($out) . (string) \stream_get_contents($err)];
        };

        [$code, $text] = $run('set', 'block', '2');
        self::assertSame(0, $code, $text);
        [$code, $text] = $run('set', 'sometimes');
        self::assertSame(1, $code);
        self::assertStringContainsString('Choose off', $text);
        [$code] = $run('set');
        self::assertSame(1, $code);
        [$code, $text] = $run('set', null, 'x');
        self::assertSame(1, $code);
        self::assertStringContainsString('1 to 4', $text);

        $this->server->wafEvents = [['time' => \gmdate('Y-m-d\TH:i:s\Z'), 'account' => 'shop', 'host' => 'shop.example.com', 'ip' => '198.51.100.9', 'uri' => '/', 'blocked' => true, 'score' => 20, 'rules' => [['id' => 942100, 'msg' => 'SQLi']]]];
        [$code, $text] = $run('scan');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('1 request', $text);

        [$code, $text] = $run('status');
        self::assertSame(0, $code, $text);
        self::assertStringContainsString('Core Rule Set 3.3.5', $text);
        self::assertStringContainsString('"block" mode. Paranoia level 2', $text);
        self::assertStringContainsString('REFUSED 942100', $text);

        $this->server->waf['available'] = false;
        [, $text] = $run('status');
        self::assertStringContainsString('ModSecurity is not loaded', $text);

        $this->server->down = true;
        [$code, $text] = $run('scan');
        self::assertSame(1, $code);
        self::assertStringContainsString('not answering', $text);
    }

    // -------------------------------------------------------------------------

    private function waf(): Waf
    {
        return $this->app->container()->get(Waf::class);
    }

    private function admin(): Browser
    {
        return $this->signedIn('admin', 'root', 'root-password-123');
    }

    /** @return array<string, mixed> */
    private function account(string $name = 'shop'): array
    {
        return $this->app->container()->get(\App\Modules\Accounts\Accounts::class)->findByUsernameUnscoped($name) ?? throw new \RuntimeException('no account');
    }

    private function base(): string
    {
        return '/panel/domains/' . $this->main['id'];
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
