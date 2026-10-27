<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\ServersTestCase;

/** The server screens, from adding one to revoking its agent. */
final class ServersPageTest extends ServersTestCase
{
    private const PASSWORD = 'a perfectly fine password';

    /** @param list<string> $roles */
    private function as(string $login, array $roles): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create($login, \ucfirst($login), null, $hash, $roles);
        $browser = $this->browser();
        $browser->post('/login', ['login' => $login, 'password' => self::PASSWORD]);

        return $browser;
    }

    /** @return array{0: string, 1: string} the server's page and the token in the command */
    private function addServer(Browser $admin, string $name = 'web-01'): array
    {
        $page = $admin->post('/servers', ['name' => $name]);
        self::assertSame(201, $page->status(), $page->body());
        $body = \html_entity_decode($page->body());

        if (\preg_match('#sudo lpa enroll --panel https://panel\.test:8443 --pin (sha256/\S+) --token ([A-Za-z0-9_-]{43})#', $body, $m) !== 1
            || \preg_match('#href="(/servers/[0-9A-Z]{26})"#', $body, $link) !== 1) {
            self::fail('The command is not on the page.');
        }

        self::assertSame(self::PANEL_PIN, $m[1]);

        return [$link[1], $m[2]];
    }

    public function test_an_admin_adds_a_server_and_the_command_pairs_it(): void
    {
        $this->panel();
        $admin = $this->as('alice', ['admin']);

        self::assertSame(200, $admin->get('/servers')->status());
        self::assertStringContainsString('No servers yet', $admin->get('/servers')->body());
        self::assertStringNotContainsString('Finish setting up the panel', $admin->get('/servers/new')->body());

        [$page, $token] = $this->addServer($admin);
        self::assertStringContainsString('Waiting for the agent', $admin->get($page)->body());

        $agent = $this->agent();
        self::assertSame(200, $agent->enroll($token)->status());
        self::assertSame(200, $agent->heartbeat()->status());

        $detail = $admin->get($page)->body();
        self::assertStringContainsString('Online', $detail);
        self::assertStringContainsString('Ubuntu 24.04', $detail);
        self::assertStringContainsString('system.metrics', $detail);
        self::assertStringContainsString($agent->kid(), $detail);
        self::assertStringNotContainsString($token, $detail, 'the token is never shown again');

        self::assertStringContainsString('web-01', $admin->get('/servers')->body());
        self::assertStringContainsString('lp-server-tally', $admin->get('/')->body());
        $api = $admin->data('/api/v1/servers');
        self::assertSame('online', $api[0]['status'] ?? null);
    }

    public function test_revoking_cuts_the_agent_off_and_pairing_again_brings_it_back(): void
    {
        $this->panel();
        $admin = $this->as('alice', ['admin']);
        [$page, $token] = $this->addServer($admin);
        $agent = $this->agent();
        $agent->enroll($token);

        self::assertSame(303, $admin->post($page . '/revoke')->status());
        self::assertSame('agent_revoked', FakeAgent::code($agent->heartbeat()));
        self::assertStringContainsString('revoked', $admin->get($page)->body());

        $again = $admin->post($page . '/enroll');
        self::assertSame(201, $again->status());
        if (\preg_match('#--token ([A-Za-z0-9_-]{43})#', $again->body(), $m) !== 1) {
            self::fail('No new command.');
        }

        $fresh = $this->agent('agent-b');
        self::assertSame(200, $fresh->enroll($m[1])->status());
        self::assertSame(200, $fresh->heartbeat()->status());
    }

    public function test_a_rotation_request_reaches_the_agent(): void
    {
        $this->panel();
        $admin = $this->as('alice', ['admin']);
        [$page, $token] = $this->addServer($admin);
        $agent = $this->agent();
        $agent->enroll($token);

        $admin->post($page . '/rotate');
        $data = \json_decode($agent->heartbeat()->body(), true);

        self::assertTrue(\is_array($data) && $data['rotate_requested'] === true);
        self::assertStringContainsString('Key rotation requested', $admin->get($page)->body());
    }

    public function test_a_customer_cannot_see_servers(): void
    {
        $this->panel();
        $customer = $this->as('carol', ['customer']);

        self::assertSame(403, $customer->get('/servers')->status());
        self::assertSame(403, $customer->post('/servers', ['name' => 'mine'])->status());
        self::assertStringNotContainsString('>Servers</span>', $customer->get('/')->body());
    }

    public function test_an_unconfigured_panel_says_what_is_missing_and_refuses(): void
    {
        $this->app(['Servers' => ['agent_url' => '', 'tls_certificate' => '', 'operation_key' => \sys_get_temp_dir() . '/no-such-lpanel-key']]);
        $admin = $this->as('alice', ['admin']);

        $form = $admin->get('/servers/new')->body();
        self::assertStringContainsString('Finish setting up the panel', $form);
        self::assertStringContainsString('LPANEL_AGENT_URL', $form);
        self::assertStringContainsString('servers:keygen', $form);
        self::assertSame(409, $admin->post('/servers', ['name' => 'web-01'])->status());
    }

    public function test_a_bad_name_is_refused(): void
    {
        $this->panel();
        $admin = $this->as('alice', ['admin']);

        self::assertSame(422, $admin->post('/servers', ['name' => '<script>'])->status());
        self::assertSame(422, $admin->post('/servers', ['name' => ''])->status());
    }
}
