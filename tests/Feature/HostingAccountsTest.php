<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Cli\Output;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Hosting\Accounts\Account;
use App\Modules\Hosting\Accounts\AccountStore;
use App\Modules\Hosting\Commands\AccountCommands;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Servers\Store\ServerStore;
use App\Tests\Support\Browser;
use App\Tests\Support\FakeAgent;
use App\Tests\Support\OperationsTestCase;

/**
 * Hosting accounts (ADR-0013): created, suspended, unsuspended and deleted
 * through the agent, settled by its results, and seen only by their owners
 * (THREAT_MODEL.md test 1).
 */
final class HostingAccountsTest extends OperationsTestCase
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

    private function accounts(): AccountStore
    {
        return $this->service(AccountStore::class);
    }

    private function account(string $uid): Account
    {
        return $this->accounts()->byUid($uid) ?? self::fail('The account is gone.');
    }

    /** An admin's new account for carol, on the online agent's server. */
    private function newAccount(Browser $admin, string $label = 'Acme Ltd'): Account
    {
        $response = $admin->postJson('/api/v1/accounts', ['server' => $this->server()->uid, 'owner' => $this->user('carol', ['customer'])->uid, 'label' => $label]);
        self::assertSame(202, $response->status(), $response->body());

        return $this->account((string) \json_decode($response->body(), true)['data']['id']);
    }

    /**
     * The agent takes the account's operation and answers it: $status, with a
     * result made from the catalogue's real one for this user.
     *
     * @return array<string, mixed> the operation as the agent received it
     */
    private function answer(FakeAgent $agent, Account $account, string $status = 'succeeded', ?string $code = null): array
    {
        $claimed = $this->claimed($agent->claim());
        self::assertCount(1, $claimed);
        $op = $claimed[0]['op'];
        $result = self::example((string) $op['op']);
        $result->system_user = $account->systemUser;
        self::assertSame(200, $agent->result((string) $op['operation_id'], 1, $status, $status === 'succeeded' ? $result : null, $code, $code === null ? '' : 'pretend failure')->status());

        return $op;
    }

    public function test_an_admin_creates_an_account_and_the_agent_sets_it_up(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $carol = $this->user('carol', ['customer']);

        $page = $admin->post('/accounts', ['label' => 'Acme Ltd', 'server' => $this->server()->uid, 'owner' => $carol->uid]);
        self::assertSame(303, $page->status(), $page->body());
        $all = $this->accounts()->page();
        self::assertCount(1, $all);
        $account = $all[0];
        self::assertSame([Account::PROVISIONING, 'create', $carol->uid], [$account->status, $account->lastAction, $account->ownerUid]);
        self::assertMatchesRegularExpression('/^lp[a-z2-7]{8}$/', $account->systemUser);
        self::assertStringContainsString('data-lp-watch', $admin->get('/accounts/' . $account->uid)->body());

        // What the agent is handed: the account by id, its user name, a key for retries.
        $op = $this->answer($agent, $account);
        self::assertSame('account.create', $op['op']);
        self::assertSame(['server_id' => $this->server()->uid, 'account_id' => $account->uid], $op['target']);
        self::assertSame(['system_user' => $account->systemUser], $op['payload']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $op['idempotency_key']);

        $account = $this->account($account->uid);
        self::assertSame([Account::ACTIVE, 20000, 20000, '8.2', null], [$account->status, $account->systemUid, $account->systemGid, $account->phpVersion, $account->lastError]);

        $shown = $admin->get('/accounts/' . $account->uid)->body();
        self::assertStringContainsString('Acme Ltd', $shown);
        self::assertStringContainsString('/home/' . $account->systemUser, $shown);
        self::assertStringNotContainsString('data-lp-watch', $shown);
        self::assertStringContainsString('Acme Ltd', $admin->get('/accounts')->body());

        $actions = \array_column($this->service(ConnectionManager::class)->connection()->table('audit_events')->where('target_id', $account->uid)->orderBy('id')->get(), 'outcome', 'action');
        self::assertSame(['account.create' => 'succeeded'], \array_intersect_key($actions, ['account.create' => 1]));
    }

    public function test_suspend_unsuspend_and_delete_through_the_api(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);
        $this->answer($agent, $account);
        $api = '/api/v1/accounts/' . $account->uid;

        self::assertSame(202, $admin->postJson($api . '/suspend', [])->status());
        self::assertSame('suspending', $admin->data($api)['status']);
        self::assertSame(409, $admin->postJson($api . '/suspend', [])->status(), 'one thing at a time');
        $this->answer($agent, $account);
        self::assertSame('suspended', $admin->data($api)['status']);
        self::assertNotNull($this->account($account->uid)->suspendedAt);

        self::assertSame(202, $admin->postJson($api . '/unsuspend', [])->status());
        $this->answer($agent, $account);
        self::assertSame('active', $admin->data($api)['status']);

        // Deleting takes the user name, typed.
        foreach ([[], ['confirm' => 'yes'], ['confirm' => \strtoupper($account->systemUser)]] as $body) {
            $refused = $admin->sendJson('DELETE', $api, $body);
            self::assertSame(422, $refused->status());
            self::assertSame('confirmation_required', \json_decode($refused->body(), true)['error']['code']);
        }
        self::assertSame(202, $admin->sendJson('DELETE', $api, ['confirm' => $account->systemUser])->status());
        $op = $this->answer($agent, $account);
        self::assertSame('account.delete', $op['op']);
        self::assertSame('deleted', $admin->data($api)['status']);
        self::assertSame([], $admin->data('/api/v1/accounts'));
        self::assertCount(1, $admin->data('/api/v1/accounts?status=deleted'));
        self::assertSame(409, $admin->postJson($api . '/unsuspend', [])->status());
    }

    public function test_a_failure_puts_the_account_back_and_says_why(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);

        // Setting up fails: the account is "failed", and can be set up again.
        $this->answer($agent, $account, 'failed', 'config_rejected');
        $account = $this->account($account->uid);
        self::assertSame([Account::FAILED, 'config_rejected'], [$account->status, $account->lastErrorCode]);
        self::assertStringContainsString('Try again', $admin->get('/accounts/' . $account->uid)->body());
        self::assertSame(303, $admin->post('/accounts/' . $account->uid . '/retry')->status());
        self::assertSame(Account::PROVISIONING, $this->account($account->uid)->status);
        $this->answer($agent, $account);
        self::assertSame(Account::ACTIVE, $this->account($account->uid)->status);

        // A suspension that fails leaves it active, with the reason.
        $admin->postJson('/api/v1/accounts/' . $account->uid . '/suspend', []);
        $this->answer($agent, $account, 'failed', 'precondition_failed');
        $account = $this->account($account->uid);
        self::assertSame([Account::ACTIVE, 'precondition_failed', 'suspend'], [$account->status, $account->lastErrorCode, $account->lastAction]);
        self::assertStringContainsString('The last suspension did not finish', $admin->get('/accounts/' . $account->uid)->body());
    }

    public function test_a_success_about_another_user_settles_nothing(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);
        $claimed = $this->claimed($agent->claim())[0]['op'];
        $result = self::example('account.create');
        $result->system_user = 'lpzzzzzzzz';
        $agent->result((string) $claimed['operation_id'], 1, 'succeeded', $result);

        $account = $this->account($account->uid);
        self::assertSame([Account::FAILED, 'invalid_result', null], [$account->status, $account->lastErrorCode, $account->systemUid]);
    }

    public function test_a_refused_operation_keeps_no_account(): void
    {
        $this->onlineAgent('agent-a', ['system.info' => [1]]);
        $admin = $this->as('alice', ['admin']);
        $response = $admin->postJson('/api/v1/accounts', ['server' => $this->server()->uid, 'owner' => $this->user('carol', ['customer'])->uid, 'label' => 'Acme']);

        self::assertSame(409, $response->status());
        self::assertSame('unknown_operation', \json_decode($response->body(), true)['error']['code']);
        self::assertSame([], $this->accounts()->page(null, 'current'));
        self::assertSame(0, $this->store()->counts(new \DateTimeImmutable('-1 hour'))['total'] ?? 0);
    }

    public function test_only_customers_own_accounts(): void
    {
        $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $server = $this->server()->uid;

        foreach (['root' => $this->service(UserStore::class)->ensureRoot(), 'admin' => $this->user('bob', ['admin']), 'no role' => $this->user('eve', [])] as $who => $owner) {
            $response = $admin->postJson('/api/v1/accounts', ['server' => $server, 'owner' => $owner->uid, 'label' => 'X']);
            self::assertSame(422, $response->status(), $who);
            self::assertSame('owner_invalid', \json_decode($response->body(), true)['error']['code'], $who);
        }

        foreach (['', ' ', '<b>x</b>', \str_repeat('a', 101), "a\nb"] as $label) {
            self::assertSame(422, $admin->postJson('/api/v1/accounts', ['server' => $server, 'owner' => $this->user('carol', ['customer'])->uid, 'label' => $label])->status(), $label);
        }
        self::assertSame(422, $admin->postJson('/api/v1/accounts', ['server' => $server, 'owner' => $this->user('carol', ['customer'])->uid, 'label' => 'X', 'role' => 'admin'])->status(), 'mass assignment');
    }

    /** THREAT_MODEL.md test 1: every id in every customer route, as another customer. */
    public function test_a_customer_reaches_no_account_but_its_own_and_every_try_is_audited(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);
        $this->answer($agent, $account);
        $carol = $this->as('carol', ['customer']);
        $dave = $this->as('dave', ['customer']);
        $id = $account->uid;
        $unknown = '01JB2W5Z6X7Y8Z9A0B1C2D3E4F';

        // Its owner sees it, read-only.
        self::assertSame(200, $carol->get('/accounts/' . $id)->status());
        self::assertSame($id, $carol->data('/api/v1/accounts/' . $id)['id']);
        self::assertCount(1, $carol->data('/api/v1/accounts'));
        self::assertStringNotContainsString('Delete account', $carol->get('/accounts/' . $id)->body());

        $db = $this->service(ConnectionManager::class)->connection();
        $denials = static fn(): int => $db->table('audit_events')->where('outcome', 'denied')->count();

        // Another customer: every route that takes an id answers 404, as for an id that does not exist.
        foreach ([$id, $unknown] as $target) {
            foreach ([
                ['GET', '/accounts/' . $target],
                ['GET', '/api/v1/accounts/' . $target],
                ['POST', '/accounts/' . $target . '/suspend'],
                ['POST', '/accounts/' . $target . '/unsuspend'],
                ['POST', '/accounts/' . $target . '/retry'],
                ['POST', '/accounts/' . $target . '/delete'],
                ['POST', '/api/v1/accounts/' . $target . '/suspend'],
                ['POST', '/api/v1/accounts/' . $target . '/unsuspend'],
                ['POST', '/api/v1/accounts/' . $target . '/retry'],
                ['DELETE', '/api/v1/accounts/' . $target],
            ] as [$method, $uri]) {
                $before = $denials();
                $response = match (true) {
                    $method === 'GET' => $dave->get($uri),
                    \str_starts_with($uri, '/api/') => $dave->sendJson($method, $uri, $method === 'DELETE' ? ['confirm' => $account->systemUser] : []),
                    default => $dave->post($uri, ['confirm' => $account->systemUser]),
                };
                self::assertSame(404, $response->status(), $method . ' ' . $uri);
                self::assertSame($before + 1, $denials(), 'no audit record for ' . $method . ' ' . $uri);
            }
        }
        self::assertSame([], $dave->data('/api/v1/accounts'));
        self::assertStringNotContainsString('Acme Ltd', $dave->get('/accounts')->body());

        // Its owner may look but not act: 403, audited.
        foreach (['/accounts/' . $id . '/suspend', '/accounts/' . $id . '/delete', '/api/v1/accounts/' . $id . '/suspend'] as $uri) {
            $before = $denials();
            $response = \str_starts_with($uri, '/api/') ? $carol->postJson($uri, []) : $carol->post($uri, ['confirm' => $account->systemUser]);
            self::assertSame(403, $response->status(), $uri);
            self::assertSame($before + 1, $denials(), $uri);
        }
        $before = $denials();
        self::assertSame(403, $carol->postJson('/api/v1/accounts', ['server' => $this->server()->uid, 'owner' => $this->user('carol', ['customer'])->uid, 'label' => 'Mine'])->status());
        self::assertSame($before + 1, $denials());
        self::assertSame(Account::ACTIVE, $this->account($id)->status, 'nothing a customer did changed the account');
        self::assertSame(403, $carol->get('/accounts/new')->status());
        self::assertSame(403, $carol->get('/operations')->status());

        // Once deleted, it is gone for its owner too.
        $admin->sendJson('DELETE', '/api/v1/accounts/' . $id, ['confirm' => $account->systemUser]);
        $this->answer($agent, $account);
        self::assertSame(404, $carol->get('/accounts/' . $id)->status());
        self::assertSame([], $carol->data('/api/v1/accounts'));
    }

    public function test_revoking_the_agent_settles_work_under_way(): void
    {
        $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);

        $this->service(ServerStore::class)->revoke($this->server());

        $account = $this->account($account->uid);
        self::assertSame([Account::FAILED, 'agent_revoked'], [$account->status, $account->lastErrorCode]);
        self::assertStringContainsString('revoked', (string) $account->lastError);
    }

    public function test_a_cancelled_suspension_leaves_the_account_active(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $account = $this->newAccount($admin);
        $this->answer($agent, $account);

        $admin->postJson('/api/v1/accounts/' . $account->uid . '/suspend', []);
        $op = (string) $this->account($account->uid)->operationUid;
        self::assertSame(202, $admin->postJson('/api/v1/operations/' . $op . '/cancel', [])->status());

        $account = $this->account($account->uid);
        self::assertSame([Account::ACTIVE, 'cancelled'], [$account->status, $account->lastErrorCode]);
    }

    public function test_the_console_manages_accounts(): void
    {
        $agent = $this->onlineAgent();
        $this->user('carol', ['customer']);
        $commands = $this->service(AccountCommands::class);
        $stream = \fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $out = new Output($stream);

        self::assertSame(0, $commands->create($out, 'web-01', 'carol', 'Acme Ltd'));
        $account = $this->accounts()->page()[0];
        $this->answer($agent, $account);
        self::assertSame(0, $commands->suspend($out, $account->systemUser));
        $this->answer($agent, $account);
        self::assertSame(1, $commands->delete($out, $account->systemUser, 'nope'));
        self::assertSame(0, $commands->delete($out, $account->uid, $account->systemUser));
        $this->answer($agent, $account);
        self::assertSame(Account::DELETED, $this->account($account->uid)->status);
        self::assertSame(1, $commands->create($out, 'web-01', 'nobody', 'X'));
    }
}
