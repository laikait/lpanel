<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Cli\Output;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Websites\Commands\WebsiteCommands;
use App\Modules\Websites\Sites\Website;
use App\Modules\Websites\Sites\WebsiteStore;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\OperationsTestCase;

/**
 * Websites (ADR-0014): created, changed, suspended and deleted through the
 * agent, settled by its results, hand edits never overwritten without an
 * administrator, and seen only by their owners (THREAT_MODEL.md test 1).
 */
final class WebsitesTest extends OperationsTestCase
{
    private const PASSWORD = 'a perfectly fine password';

    /** @param list<string> $roles */
    private function as(string $login, array $roles): Browser
    {
        $this->user($login, $roles);
        $browser = $this->browser();
        $browser->post('/login', ['login' => $login, 'password' => self::PASSWORD]);

        return $browser;
    }

    /** @param list<string> $roles */
    private function user(string $login, array $roles): User
    {
        $users = $this->service(UserStore::class);

        return $users->byLogin($login) ?? $users->create($login, \ucfirst($login), null, $this->service(Password::class)->hash(new Secret(self::PASSWORD)), $roles);
    }

    private function websites(): WebsiteStore
    {
        return $this->service(WebsiteStore::class);
    }

    private function website(string $uid): Website
    {
        return $this->websites()->byUid($uid) ?? self::fail('The website is gone.');
    }

    /** carol's account, set up by the agent. */
    private function account(FakeAgent $agent, Browser $admin, string $owner = 'carol'): Account
    {
        $response = $admin->postJson('/api/v1/accounts', ['server' => $this->server()->uid, 'owner' => $this->user($owner, ['customer'])->uid, 'label' => \ucfirst($owner) . ' Ltd']);
        self::assertSame(202, $response->status(), $response->body());
        $account = $this->service(AccountStore::class)->byUid((string) \json_decode($response->body(), true)['data']['id']) ?? self::fail('no account');
        $op = $this->claimed($agent->claim())[0]['op'];
        $result = self::example('account.create');
        $result->system_user = $account->systemUser;
        $agent->result((string) $op['operation_id'], 1, 'succeeded', $result);

        return $this->service(AccountStore::class)->byUid($account->uid) ?? self::fail('no account');
    }

    /** @param array<string, mixed> $settings */
    private function newWebsite(Browser $admin, Account $account, array $settings = []): Website
    {
        $response = $admin->postJson('/api/v1/websites', ['account' => $account->uid] + $settings + ['domains' => ['example.com', 'www.example.com'], 'php' => true]);
        self::assertSame(202, $response->status(), $response->body());

        return $this->website((string) \json_decode($response->body(), true)['data']['id']);
    }

    /**
     * The agent takes the website's operation and answers it.
     *
     * @return array<string, mixed> the operation as the agent received it
     */
    private function answer(FakeAgent $agent, Website $website, Account $account, string $status = 'succeeded', ?string $code = null, string $message = 'pretend failure'): array
    {
        $claimed = $this->claimed($agent->claim());
        self::assertCount(1, $claimed);
        $op = $claimed[0]['op'];
        $result = self::example((string) $op['op']);
        $result->website_id = $website->uid;
        $result->system_user = $account->systemUser;
        self::assertSame(200, $agent->result((string) $op['operation_id'], 1, $status, $status === 'succeeded' ? $result : null, $code, $code === null ? '' : $message)->status());

        return $op;
    }

    private function denials(): int
    {
        return $this->service(ConnectionManager::class)->connection()->table('audit_events')->where('outcome', 'denied')->count();
    }

    public function test_an_admin_creates_a_website_and_the_agent_serves_it(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);

        $page = $admin->post('/websites', ['account' => $account->uid, 'domains' => "Example.COM\nwww.example.com", 'document_root' => '', 'php' => '1']);
        self::assertSame(303, $page->status(), $page->body());
        $website = $this->websites()->page()[0];
        self::assertSame([Website::PROVISIONING, 'example.com', ['example.com', 'www.example.com'], 'example.com'], [$website->status, $website->domain, $website->domains(), $website->settings['document_root']]);
        self::assertStringContainsString('data-lp-watch', $admin->get('/websites/' . $website->uid)->body());

        // What the agent is handed: the website by id, its account, and checked settings only.
        $op = $this->answer($agent, $website, $account);
        self::assertSame('website.create', $op['op']);
        self::assertSame(['server_id' => $this->server()->uid, 'website_id' => $website->uid], $op['target']);
        self::assertSame([
            'account_id' => $account->uid,
            'system_user' => $account->systemUser,
            'domains' => ['example.com', 'www.example.com'],
            'document_root' => 'example.com',
            'php' => true,
            'index' => [],
            'redirects' => [],
            'headers' => [],
        ], $op['payload']);

        $website = $this->website($website->uid);
        self::assertSame([Website::ACTIVE, Website::CONFIG_OK, null], [$website->status, $website->configState, $website->lastError]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $website->revision);

        $shown = $admin->get('/websites/' . $website->uid)->body();
        self::assertStringContainsString('www.example.com', $shown);
        self::assertStringContainsString('/home/' . $account->systemUser . '/web/example.com', $shown);
        self::assertStringContainsString('Apply settings', $shown);
        self::assertStringNotContainsString('data-lp-watch', $shown);
        self::assertStringContainsString('example.com', $admin->get('/websites')->body());
        self::assertStringContainsString('example.com', $admin->get('/accounts/' . $account->uid)->body(), 'the account page lists its websites');
        self::assertStringContainsString('Check now', $admin->get('/servers/' . $this->server()->uid)->body());
        self::assertSame(200, $admin->get('/websites/new?account=' . $account->uid)->status());
    }

    public function test_new_settings_are_served_only_once_the_agent_says_so(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $api = '/api/v1/websites/' . $website->uid;
        $before = $this->website($website->uid)->settings;

        $changed = [
            'domains' => ['example.com', 'shop.example.com'],
            'redirects' => [['from' => '/old', 'to' => 'https://example.org/new?a=1', 'code' => 308]],
            'headers' => [['name' => 'Content-Security-Policy', 'value' => "default-src 'self'; img-src *"]],
            'index' => ['home.html'],
            'php' => false,
        ];
        self::assertSame(202, $admin->sendJson('PUT', $api . '/settings', $changed)->status());
        self::assertSame('updating', $admin->data($api)['status']);
        self::assertSame($before, $this->website($website->uid)->settings, 'not served yet');
        self::assertSame(409, $admin->sendJson('PUT', $api . '/settings', $changed)->status(), 'one thing at a time');
        self::assertSame(['shop.example.com'], $this->websites()->taken($this->server()->id, ['shop.example.com'], null), 'the new domain is reserved meanwhile');

        // The agent could not make Nginx take it: the old settings stay, the new domain is free again.
        $op = $this->answer($agent, $website, $account, 'failed', 'rolled_back', 'Nginx did not take the new configuration; LPanel put the previous one back, and it is serving again');
        self::assertFalse($op['payload']['replace_edits']);
        self::assertSame([['from' => '/old', 'to' => 'https://example.org/new?a=1', 'code' => 308]], $op['payload']['redirects']);
        $website = $this->website($website->uid);
        self::assertSame([Website::ACTIVE, 'rolled_back', $before], [$website->status, $website->lastErrorCode, $website->settings]);
        self::assertSame([], $this->websites()->taken($this->server()->id, ['shop.example.com'], null));
        self::assertStringContainsString('did not take', $admin->get('/websites/' . $website->uid)->body());

        // Again, and served.
        self::assertSame(202, $admin->sendJson('PUT', $api . '/settings', $changed)->status());
        $this->answer($agent, $website, $account);
        $website = $this->website($website->uid);
        self::assertSame([Website::ACTIVE, ['example.com', 'shop.example.com'], false, ['home.html']], [$website->status, $website->domains(), $website->settings['php'], $website->settings['index']]);
        self::assertSame([], $this->websites()->taken($this->server()->id, ['www.example.com'], null), 'a domain it no longer has is released');
    }

    public function test_settings_outside_the_grammar_are_refused_before_anything_is_queued(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $api = '/api/v1/websites/' . $website->uid . '/settings';
        $queued = $this->store()->counts(new \DateTimeImmutable('-1 hour'))['total'] ?? 0;

        foreach ([
            ['domains' => ['a.com; include /etc/shadow']],
            ['domains' => ['10.0.0.1']],
            ['domains' => ['localhost']],
            ['domains' => []],
            ['document_root' => '/etc/lpa'],
            ['document_root' => '../../etc'],
            ['document_root' => '.hidden'],
            ['headers' => [['name' => 'X-A', 'value' => '$request_uri']]],
            ['headers' => [['name' => 'X-A', 'value' => 'a" always; include /etc/x; #']]],
            ['headers' => [['name' => 'X-A', 'value' => "a\nreturn 200"]]],
            ['headers' => [['name' => 'Server', 'value' => 'x']]],
            ['headers' => [['name' => 'X-LPanel-Config', 'value' => 'x']]],
            ['redirects' => [['from' => '~ .*', 'to' => '/x', 'code' => 301]]],
            ['redirects' => [['from' => '/a%20b', 'to' => '/x', 'code' => 301]]],
            ['redirects' => [['from' => '/a/../b', 'to' => '/x', 'code' => 301]]],
            ['redirects' => [['from' => '/.well-known/lpanel-probe', 'to' => '/x', 'code' => 301]]],
            ['redirects' => [['from' => '/a', 'to' => '/$host', 'code' => 301]]],
            ['redirects' => [['from' => '/a', 'to' => 'file:///etc/passwd', 'code' => 301]]],
            ['redirects' => [['from' => '/a', 'to' => '/x', 'code' => 200]]],
            ['index' => ['../../etc/passwd']],
        ] as $bad) {
            $response = $admin->sendJson('PUT', $api, $bad);
            self::assertSame(422, $response->status(), \json_encode($bad) . ' ' . $response->body());
            self::assertSame('settings_invalid', \json_decode($response->body(), true)['error']['code'], (string) \json_encode($bad));
        }
        self::assertSame(422, $admin->sendJson('PUT', $api, ['raw' => 'server {}'])->status(), 'no field but the settings');

        // The form says which field, and keeps what was typed.
        $form = $admin->post('/websites/' . $website->uid . '/settings', ['domains' => 'example.com', 'document_root' => 'example.com', 'redirects' => [['from' => 'no-slash', 'to' => '/x', 'code' => '301']]]);
        self::assertSame(422, $form->status());
        self::assertStringContainsString('Redirect from a path', $form->body());
        self::assertStringContainsString('value="no-slash"', $form->body());

        self::assertSame($queued, $this->store()->counts(new \DateTimeImmutable('-1 hour'))['total'] ?? 0, 'nothing was queued');
        self::assertSame(Website::ACTIVE, $this->website($website->uid)->status);
    }

    public function test_a_domain_belongs_to_one_website_per_server(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);

        $response = $admin->postJson('/api/v1/websites', ['account' => $account->uid, 'domains' => ['blog.example.com', 'WWW.example.com']]);
        self::assertSame(409, $response->status());
        self::assertSame('domain_taken', \json_decode($response->body(), true)['error']['code']);

        $admin->sendJson('DELETE', '/api/v1/websites/' . $website->uid, ['confirm' => 'example.com']);
        $this->answer($agent, $website, $account);
        self::assertSame(202, $admin->postJson('/api/v1/websites', ['account' => $account->uid, 'domains' => ['www.example.com']])->status(), 'free once its website is deleted');
    }

    public function test_a_hand_edit_is_never_overwritten_without_an_administrator(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $carol = $this->as('carol', ['customer']);
        $db = $this->service(ConnectionManager::class)->connection();

        // The hourly check finds the edit.
        self::assertSame(303, $admin->post('/websites/check', ['server' => $this->server()->uid])->status());
        $claimed = $this->claimed($agent->claim())[0]['op'];
        self::assertSame(['websites.check', ['server_id' => $this->server()->uid]], [$claimed['op'], $claimed['target']]);
        $result = self::example('websites.check');
        $result->checked = 1;
        $result->edited = [$website->uid];
        $result->nginx_test = 'passed';
        $result->reloaded = true;
        $agent->result((string) $claimed['operation_id'], 1, 'succeeded', $result);

        $website = $this->website($website->uid);
        self::assertSame(Website::CONFIG_EDITED, $website->configState);
        self::assertSame(1, $db->table('audit_events')->where('action', 'website.drift')->where('target_id', $website->uid)->count());
        self::assertStringContainsString('edited outside LPanel', $admin->get('/websites/' . $website->uid)->body());
        self::assertStringContainsString('changed outside LPanel', $admin->get('/websites')->body());
        self::assertStringContainsString('Edited outside LPanel', $admin->get('/servers/' . $this->server()->uid)->body());

        // Nobody's settings go through until it is restored; the customer cannot restore.
        self::assertSame('config_edited', \json_decode($carol->sendJson('PUT', '/api/v1/websites/' . $website->uid . '/settings', ['index' => ['a.html']])->body(), true)['error']['code']);
        self::assertSame('config_edited', \json_decode($admin->sendJson('PUT', '/api/v1/websites/' . $website->uid . '/settings', ['index' => ['a.html']])->body(), true)['error']['code']);
        self::assertSame('config_edited', \json_decode($admin->postJson('/api/v1/websites/' . $website->uid . '/suspend', [])->body(), true)['error']['code'], 'suspending rewrites the file too');
        self::assertSame(Website::ACTIVE, $this->website($website->uid)->status);
        $suspend = $admin->postJson('/api/v1/accounts/' . $account->uid . '/suspend', []);
        self::assertSame([409, 'account_in_use'], [$suspend->status(), \json_decode($suspend->body(), true)['error']['code']], 'nor its account: that rewrites it as well');
        $before = $this->denials();
        self::assertSame(403, $carol->postJson('/api/v1/websites/' . $website->uid . '/restore', [])->status());
        self::assertSame($before + 1, $this->denials());
        self::assertStringNotContainsString('Restore LPanel', $carol->get('/websites/' . $website->uid)->body());

        // The administrator restores LPanel's version: replace_edits, then all is ours again.
        self::assertSame(202, $admin->postJson('/api/v1/websites/' . $website->uid . '/restore', [])->status());
        $op = $this->answer($agent, $website, $account);
        self::assertSame(['website.update', true], [$op['op'], $op['payload']['replace_edits']]);
        self::assertSame(Website::CONFIG_OK, $this->website($website->uid)->configState);
        self::assertSame(1, $db->table('audit_events')->where('action', 'website.restore')->where('outcome', 'allowed')->where('target_id', $website->uid)->count());

        // The agent's own refusal marks it edited as well.
        self::assertSame(202, $admin->sendJson('PUT', '/api/v1/websites/' . $website->uid . '/settings', ['index' => ['a.html']])->status());
        $this->answer($agent, $website, $account, 'failed', 'config_edited');
        self::assertSame([Website::ACTIVE, Website::CONFIG_EDITED], [$this->website($website->uid)->status, $this->website($website->uid)->configState]);
    }

    public function test_suspend_unsuspend_delete_and_an_account_deleted_only_without_websites(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $api = '/api/v1/websites/' . $website->uid;

        self::assertSame(202, $admin->postJson($api . '/suspend', [])->status());
        $op = $this->answer($agent, $website, $account);
        self::assertSame(['account_id' => $account->uid, 'system_user' => $account->systemUser], $op['payload']);
        self::assertSame('suspended', $admin->data($api)['status']);
        self::assertSame(202, $admin->postJson($api . '/unsuspend', [])->status());
        $this->answer($agent, $website, $account);
        self::assertSame('active', $admin->data($api)['status']);

        // The account goes only after its websites.
        $refused = $admin->sendJson('DELETE', '/api/v1/accounts/' . $account->uid, ['confirm' => $account->systemUser]);
        self::assertSame(409, $refused->status());
        self::assertSame('account_in_use', \json_decode($refused->body(), true)['error']['code']);

        foreach ([[], ['confirm' => 'www.example.com'], ['confirm' => 'EXAMPLE.COM']] as $body) {
            self::assertSame('confirmation_required', \json_decode($admin->sendJson('DELETE', $api, $body)->body(), true)['error']['code']);
        }
        self::assertSame(202, $admin->sendJson('DELETE', $api, ['confirm' => 'example.com'])->status());
        $this->answer($agent, $website, $account);
        self::assertSame('deleted', $admin->data($api)['status']);
        self::assertStringContainsString('Its files stayed', $admin->get('/websites/' . $website->uid)->body());

        self::assertSame(202, $admin->sendJson('DELETE', '/api/v1/accounts/' . $account->uid, ['confirm' => $account->systemUser])->status());
    }

    public function test_a_success_about_another_website_settles_nothing(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $claimed = $this->claimed($agent->claim())[0]['op'];
        $result = self::example('website.create');
        $result->website_id = '01JB2W5Z6X7Y8Z9A0B1C2D3E4Z';
        $result->system_user = $account->systemUser;
        $agent->result((string) $claimed['operation_id'], 1, 'succeeded', $result);

        $website = $this->website($website->uid);
        self::assertSame([Website::FAILED, 'invalid_result', null], [$website->status, $website->lastErrorCode, $website->revision]);
    }

    public function test_a_customer_changes_its_own_settings_but_not_its_domains(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $carol = $this->as('carol', ['customer']);
        $id = $website->uid;

        // Through the form: redirects, headers, index, PHP. The domains stay as they are.
        $form = $carol->post('/websites/' . $id . '/settings', [
            'domains' => 'evil.example.net',
            'php' => '1',
            'index' => 'index.php',
            'redirects' => [['from' => '/shop', 'to' => 'https://shop.example.org/', 'code' => '302'], ['from' => '', 'to' => '', 'code' => '301']],
            'headers' => [['name' => 'X-Frame-Options', 'value' => 'DENY']],
        ]);
        self::assertSame(303, $form->status(), $form->body());
        $op = $this->answer($agent, $website, $account);
        self::assertSame(['example.com', 'www.example.com'], $op['payload']['domains'], 'a customer\'s form never sends domains');
        self::assertSame([['from' => '/shop', 'to' => 'https://shop.example.org/', 'code' => 302]], $this->website($id)->settings['redirects']);

        // Through the API: changing the domains or the folder is an administrator's, audited.
        foreach ([['domains' => ['evil.example.net']], ['document_root' => 'elsewhere']] as $body) {
            $before = $this->denials();
            self::assertSame(403, $carol->sendJson('PUT', '/api/v1/websites/' . $id . '/settings', $body)->status());
            self::assertSame($before + 1, $this->denials());
        }
        self::assertSame(202, $carol->sendJson('PUT', '/api/v1/websites/' . $id . '/settings', ['headers' => []])->status());
        $this->answer($agent, $website, $account);

        // Looking is fine; acting is an administrator's: 403 and an audit record each.
        foreach (['/websites/' . $id . '/suspend', '/websites/' . $id . '/delete', '/websites/' . $id . '/restore', '/api/v1/websites/' . $id . '/suspend', '/api/v1/websites/' . $id . '/retry'] as $uri) {
            $before = $this->denials();
            $response = \str_starts_with($uri, '/api/') ? $carol->postJson($uri, []) : $carol->post($uri, ['confirm' => 'example.com']);
            self::assertSame(403, $response->status(), $uri);
            self::assertSame($before + 1, $this->denials(), $uri);
        }
        $before = $this->denials();
        self::assertSame(403, $carol->postJson('/api/v1/websites', ['account' => $account->uid, 'domains' => ['mine.example.com']])->status());
        self::assertSame($before + 1, $this->denials());
        self::assertSame(403, $carol->get('/websites/new')->status());

        $shown = $carol->get('/websites/' . $id)->body();
        self::assertStringContainsString('Apply settings', $shown);
        self::assertStringNotContainsString('name="domains"', $shown);
        self::assertStringNotContainsString('Delete website', $shown);
    }

    /** THREAT_MODEL.md test 1: every id in every customer route, as another customer. */
    public function test_a_customer_reaches_no_website_but_its_own_and_every_try_is_audited(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $website = $this->newWebsite($admin, $account);
        $this->answer($agent, $website, $account);
        $carol = $this->as('carol', ['customer']);
        $dave = $this->as('dave', ['customer']);
        $id = $website->uid;
        $unknown = '01JB2W5Z6X7Y8Z9A0B1C2D3E4F';

        self::assertSame(200, $carol->get('/websites/' . $id)->status());
        self::assertSame($id, $carol->data('/api/v1/websites/' . $id)['id']);
        self::assertCount(1, $carol->data('/api/v1/websites'));

        foreach ([$id, $unknown] as $target) {
            foreach ([
                ['GET', '/websites/' . $target],
                ['GET', '/api/v1/websites/' . $target],
                ['POST', '/websites/' . $target . '/settings'],
                ['POST', '/websites/' . $target . '/suspend'],
                ['POST', '/websites/' . $target . '/unsuspend'],
                ['POST', '/websites/' . $target . '/retry'],
                ['POST', '/websites/' . $target . '/restore'],
                ['POST', '/websites/' . $target . '/delete'],
                ['PUT', '/api/v1/websites/' . $target . '/settings'],
                ['POST', '/api/v1/websites/' . $target . '/suspend'],
                ['POST', '/api/v1/websites/' . $target . '/unsuspend'],
                ['POST', '/api/v1/websites/' . $target . '/retry'],
                ['POST', '/api/v1/websites/' . $target . '/restore'],
                ['DELETE', '/api/v1/websites/' . $target],
            ] as [$method, $uri]) {
                $before = $this->denials();
                $response = match (true) {
                    $method === 'GET' => $dave->get($uri),
                    \str_starts_with($uri, '/api/') => $dave->sendJson($method, $uri, $method === 'DELETE' ? ['confirm' => 'example.com'] : []),
                    default => $dave->post($uri, ['confirm' => 'example.com', 'index' => 'x.html']),
                };
                self::assertSame(404, $response->status(), $method . ' ' . $uri);
                self::assertSame($before + 1, $this->denials(), 'no audit record for ' . $method . ' ' . $uri);
            }
        }
        self::assertSame([], $dave->data('/api/v1/websites'));
        self::assertStringNotContainsString('example.com', $dave->get('/websites')->body());
        self::assertStringNotContainsString('example.com', $dave->get('/accounts')->body());
        self::assertSame(Website::ACTIVE, $this->website($id)->status, 'nothing another customer did changed it');

        // Deleted, it is gone for its owner too.
        $admin->sendJson('DELETE', '/api/v1/websites/' . $id, ['confirm' => 'example.com']);
        $this->answer($agent, $website, $account);
        self::assertSame(404, $carol->get('/websites/' . $id)->status());
        self::assertSame([], $carol->data('/api/v1/websites'));
    }

    public function test_websites_wait_for_an_account_that_is_ready(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $admin->postJson('/api/v1/accounts/' . $account->uid . '/suspend', []);

        $response = $admin->postJson('/api/v1/websites', ['account' => $account->uid, 'domains' => ['example.com']]);
        self::assertSame(409, $response->status());
        self::assertSame('account_not_ready', \json_decode($response->body(), true)['error']['code']);
        self::assertSame([], $this->websites()->page());
    }

    public function test_the_console_manages_websites_and_the_schedule_checks_them(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->account($agent, $admin);
        $commands = $this->service(WebsiteCommands::class);
        $stream = \fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $out = new Output($stream);

        self::assertSame(0, $commands->create($out, $account->systemUser, 'example.com,www.example.com', null, true));
        $website = $this->websites()->page()[0];
        self::assertFalse($website->settings['php']);
        $this->answer($agent, $website, $account);

        self::assertSame(0, $commands->check($out));
        $claimed = $this->claimed($agent->claim());
        self::assertSame('websites.check', $claimed[0]['op']['op']);
        self::assertSame(0, $commands->suspend($out, 'www.example.com'));
        $this->answer($agent, $website, $account);
        self::assertSame(1, $commands->delete($out, 'example.com', 'nope'));
        self::assertSame(0, $commands->delete($out, $website->uid, 'example.com'));
        $this->answer($agent, $website, $account);
        self::assertSame(Website::DELETED, $this->website($website->uid)->status);
        self::assertSame(1, $commands->create($out, 'lpnobodyxx', 'x.example.com'));
    }
}
