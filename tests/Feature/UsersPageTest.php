<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;
use App\Tests\Support\Browser;

/**
 * The user screens, and UserAdmin's rules behind them: root's row is the
 * server's, only root changes administrators, nobody changes themselves here.
 */
final class UsersPageTest extends ApplicationTestCase
{
    private const PASSWORD = 'a perfectly fine password';

    /** @param list<string> $roles */
    private function user(string $login, array $roles): User
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));

        return $this->service(UserStore::class)->create($login, \ucfirst($login), $login . '@example.test', $hash, $roles);
    }

    private function as(string $login, string $ip = '203.0.113.10'): Browser
    {
        $browser = $this->browser($ip);
        $password = $login === 'root' ? self::ROOT_PASSWORD : self::PASSWORD;
        self::assertSame(303, $browser->post('/login', ['login' => $login, 'password' => $password])->status());

        return $browser;
    }

    private function fresh(string $login): User
    {
        return $this->service(UserStore::class)->byLogin($login) ?? throw new \LogicException($login);
    }

    public function test_a_guest_is_sent_to_sign_in_and_a_customer_is_refused(): void
    {
        $this->user('carol', ['customer']);

        $guest = $this->browser()->get('/users');
        self::assertSame(303, $guest->status());
        self::assertSame('/login', $guest->header('Location'));
        self::assertSame(401, $this->browser()->get('/api/v1/users', ['Accept' => 'application/json'])->status(), 'an API keeps its 401');

        self::assertSame(403, $this->as('carol')->get('/users')->status());
    }

    public function test_an_admin_lists_searches_and_creates_customers(): void
    {
        $this->user('alice', ['admin']);
        $this->user('carol', ['customer']);
        $admin = $this->as('alice');

        $list = $admin->get('/users');
        self::assertSame(200, $list->status());
        self::assertStringContainsString('Carol', $list->body());
        self::assertStringNotContainsString('carol@example.test', $admin->get('/users?q=alice')->body());

        $created = $admin->post('/users', [
            'name' => 'Dan Customer', 'login' => 'Dan', 'email' => '', 'roles' => ['customer'],
            'password' => 'customer password', 'password_confirmation' => 'customer password',
        ]);
        self::assertSame(303, $created->status());
        $dan = $this->fresh('dan');
        self::assertSame(['customer'], $dan->effectiveRoles());
        self::assertNull($dan->email);
        self::assertStringContainsString('/users/' . $dan->uid, (string) $created->header('Location'));

        $bad = $admin->post('/users', ['name' => '', 'login' => 'x', 'roles' => [], 'password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(422, $bad->status());
        self::assertStringContainsString('is-invalid', $bad->body());
    }

    public function test_only_root_creates_or_changes_administrators(): void
    {
        $this->user('alice', ['admin']);
        $bob = $this->user('bob', ['admin']);
        $carol = $this->user('carol', ['customer']);
        $admin = $this->as('alice');

        $forced = $admin->post('/users', [
            'name' => 'Eve', 'login' => 'eve', 'roles' => ['admin'],
            'password' => 'escalation attempt', 'password_confirmation' => 'escalation attempt',
        ]);
        self::assertSame(403, $forced->status());
        self::assertNull($this->service(UserStore::class)->byLogin('eve'));

        $admin->post('/users/' . $carol->uid, ['name' => 'Carol', 'email' => 'carol@example.test', 'roles' => ['customer', 'admin']]);
        self::assertSame(['customer'], $this->fresh('carol')->effectiveRoles(), 'an admin cannot promote');

        $admin->post('/users/' . $bob->uid . '/status', ['status' => 'suspended']);
        self::assertSame('active', $this->fresh('bob')->status, 'an admin cannot suspend another admin');

        $root = $this->as('root', '203.0.113.20');
        $root->post('/users/' . $carol->uid, ['name' => 'Carol', 'email' => 'carol@example.test', 'roles' => ['admin']]);
        self::assertSame(['admin'], $this->fresh('carol')->effectiveRoles());

        $denied = $this->service(ConnectionManager::class)->connection()->table('audit_events')->where('outcome', 'denied')->where('action', 'like', 'user.%')->count();
        self::assertSame(3, $denied, 'every refusal is audited');
    }

    public function test_root_and_yourself_cannot_be_changed_here(): void
    {
        $alice = $this->user('alice', ['admin']);
        $root = $this->as('root');
        $rootRow = $this->fresh('root');

        $root->post('/users/' . $rootRow->uid . '/status', ['status' => 'suspended']);
        self::assertSame('active', $this->fresh('root')->status);

        $admin = $this->as('alice', '203.0.113.30');
        $admin->post('/users/' . $alice->uid, ['name' => 'Alice', 'email' => '', 'roles' => ['customer']]);
        self::assertSame(['admin'], $this->fresh('alice')->effectiveRoles(), 'no changing your own roles');
        self::assertStringContainsString('Root is the server', $admin->get('/users/' . $rootRow->uid)->body());
    }

    public function test_suspending_or_setting_a_password_ends_their_sessions(): void
    {
        $this->user('alice', ['admin']);
        $carol = $this->user('carol', ['customer']);
        $admin = $this->as('alice');

        $session = $this->as('carol', '203.0.113.40');
        $admin->post('/users/' . $carol->uid . '/password', ['password' => 'set by the admin', 'password_confirmation' => 'set by the admin']);
        self::assertFalse($session->data('/api/v1/session')['authenticated'], 'a new password ends the old session');

        $again = $this->browser('203.0.113.41');
        self::assertSame(303, $again->post('/login', ['login' => 'carol', 'password' => 'set by the admin'])->status());
        $admin->post('/users/' . $carol->uid . '/status', ['status' => 'suspended']);
        self::assertFalse($again->data('/api/v1/session')['authenticated'], 'a suspension ends it too');
        self::assertSame(422, $this->browser('203.0.113.42')->post('/login', ['login' => 'carol', 'password' => 'set by the admin'])->status());

        $admin->post('/users/' . $carol->uid . '/status', ['status' => 'active']);
        self::assertSame(303, $this->browser('203.0.113.43')->post('/login', ['login' => 'carol', 'password' => 'set by the admin'])->status());
    }

    public function test_sign_out_everywhere_also_revokes_tokens(): void
    {
        $this->user('alice', ['admin']);
        $carol = $this->user('carol', ['customer']);
        $admin = $this->as('alice');
        $session = $this->as('carol', '203.0.113.50');

        $page = $session->post('/account/tokens', ['name' => 'laptop', 'days' => '30'])->body();
        if (\preg_match('/id="lp-new-token">(lpat_[A-Za-z0-9_-]+)</', $page, $m) !== 1) {
            self::fail('The new token is not shown.');
        }

        $admin->post('/users/' . $carol->uid . '/sessions/end');
        self::assertFalse($session->data('/api/v1/session')['authenticated']);
        self::assertSame(401, $this->browser()->bearer('GET', '/api/token/v1/session', $m[1])->status());
    }

    /** The store's own guard, under the policy: root's row never gets a panel password or profile change. */
    public function test_the_store_never_writes_a_panel_password_to_root(): void
    {
        $this->as('root');
        $users = $this->service(UserStore::class);
        $root = $this->fresh('root');

        $users->setPasswordHash($root->uid, 'a-hash-that-must-not-land');
        $users->updateProfile($root->uid, 'Renamed', 'x@example.test');

        $after = $this->fresh('root');
        self::assertNull($after->passwordHash);
        self::assertSame('root', $after->name);
    }

    public function test_an_admin_can_send_a_reset_link(): void
    {
        $this->user('alice', ['admin']);
        $carol = $this->user('carol', ['customer']);

        $this->as('alice')->post('/users/' . $carol->uid . '/reset-link');

        self::assertNotNull($this->mailTo('carol@example.test'));
    }

    public function test_changing_your_own_password_keeps_this_session_and_ends_the_others(): void
    {
        $this->user('carol', ['customer']);
        $here = $this->as('carol');
        $there = $this->as('carol', '203.0.113.60');

        self::assertSame(422, $here->post('/account/security/password', ['current_password' => 'wrong', 'password' => 'my new password!', 'password_confirmation' => 'my new password!'])->status());
        self::assertSame(303, $here->post('/account/security/password', ['current_password' => self::PASSWORD, 'password' => 'my new password!', 'password_confirmation' => 'my new password!'])->status());

        self::assertTrue($here->data('/api/v1/session')['authenticated'], 'this session carries on');
        self::assertFalse($there->data('/api/v1/session')['authenticated'], 'the other one has ended');
    }
}
