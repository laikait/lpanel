<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Totp;
use App\Engine\Core\Application;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Identity\Root\RootCheck;
use App\Modules\Identity\Root\RootPasswordVerifier;
use App\Tests\Support\TestCase;

final class SignInTest extends TestCase
{
    private const ROOT_PASSWORD = 'the-servers-root-password';

    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $users = $this->app->container()->get(PanelUsers::class);
        $users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
        $reseller = $users->create(Kind::Reseller, 'rex', 'Rex', null, new Secret('rex-password-1234'));
        $owner = $users->create(Kind::User, 'bob', 'Bob', null, new Secret('bob-password-1234'), (int) $reseller);
        $users->create(Kind::Subuser, 'bob.dev', '', null, new Secret('dev-password-1234'), (int) $owner);

        // A server's authd, without a server: root's password is ROOT_PASSWORD.
        $this->app->container()->instance(RootPasswordVerifier::class, new class (self::ROOT_PASSWORD) implements RootPasswordVerifier {
            public function __construct(private readonly string $password) {}

            public function check(Secret $password, ?string $ip): RootCheck
            {
                return $password->reveal() === $this->password ? RootCheck::allowed() : RootCheck::denied();
            }
        });
    }

    public function test_a_guest_is_sent_to_the_right_sign_in_page(): void
    {
        $browser = $this->browser($this->app);

        $admin = $browser->get('/admin/audit');
        $user = $browser->get('/panel');

        self::assertSame(302, $admin->status());
        self::assertSame('/admin/login?next=%2Fadmin%2Faudit', $admin->header('Location'));
        self::assertSame('/panel/login?next=%2Fpanel', $user->header('Location'));
    }

    public function test_an_api_client_gets_a_plain_401(): void
    {
        $response = $this->browser($this->app)->get('/admin', ['Accept' => 'application/json']);

        self::assertSame(401, $response->status());
    }

    public function test_an_administrator_signs_in_and_out(): void
    {
        $browser = $this->browser($this->app);

        $signedIn = $browser->signIn('admin', 'alice', 'alice-password-123');
        $dashboard = $browser->get('/admin');
        $out = $browser->post('/admin/logout');
        $after = $browser->get('/admin');

        self::assertSame(303, $signedIn->status());
        self::assertSame('/admin', $signedIn->header('Location'));
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('Welcome, Alice', $dashboard->body());
        self::assertSame('/admin/login', $out->header('Location'));
        self::assertSame(302, $after->status());
        self::assertSame(['auth.login', 'auth.logout'], $this->actions());
    }

    public function test_a_wrong_password_says_only_that_and_is_audited(): void
    {
        $response = $this->browser($this->app)->signIn('admin', 'alice', 'not-her-password');

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Those details are not right.', $response->body());
        self::assertSame(['auth.failed'], $this->actions());
    }

    public function test_each_login_uses_only_its_own_sign_in_page(): void
    {
        $wrongDoor = $this->browser($this->app)->signIn('admin', 'bob', 'bob-password-1234');
        $rootOnUserPanel = $this->browser($this->app)->signIn('user', 'root', self::ROOT_PASSWORD);
        $owner = $this->browser($this->app)->signIn('user', 'bob', 'bob-password-1234');
        $subuser = $this->browser($this->app)->signIn('user', 'bob.dev', 'dev-password-1234');

        self::assertSame(422, $wrongDoor->status());
        self::assertStringContainsString('Those details are not right.', $wrongDoor->body());
        self::assertSame(422, $rootOnUserPanel->status());
        self::assertSame('/panel', $owner->header('Location'));
        self::assertSame('/panel', $subuser->header('Location'));
        self::assertContains('auth.wrong_area', $this->actions());
    }

    public function test_root_signs_in_with_the_servers_password(): void
    {
        $browser = $this->browser($this->app);

        $wrong = $browser->signIn('admin', 'root', 'guess');
        $right = $browser->signIn('admin', 'root', self::ROOT_PASSWORD);
        $staff = $browser->get('/admin/staff');

        self::assertSame(422, $wrong->status());
        self::assertSame('/admin', $right->header('Location'));
        self::assertSame(200, $staff->status());
        self::assertSame(['auth.failed', 'auth.login'], $this->actions());
    }

    public function test_root_guessing_is_throttled_before_the_verifier_is_asked(): void
    {
        $browser = $this->browser($this->app);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(422, $browser->signIn('admin', 'root', 'guess-' . $i)->status());
        }

        self::assertSame(429, $browser->signIn('admin', 'root', self::ROOT_PASSWORD)->status());
    }

    public function test_logins_cannot_open_the_other_interface(): void
    {
        $root = $this->browser($this->app);
        $root->signIn('admin', 'root', self::ROOT_PASSWORD);
        $owner = $this->browser($this->app);
        $owner->signIn('user', 'bob', 'bob-password-1234');

        self::assertSame(403, $root->get('/panel')->status());
        self::assertSame(403, $root->get('/panel/security')->status());
        self::assertSame(403, $owner->get('/admin')->status());
        self::assertSame(403, $owner->get('/admin/audit')->status());
        self::assertSame(200, $owner->get('/panel/activity')->status());
    }

    public function test_only_root_manages_administrators(): void
    {
        $alice = $this->browser($this->app);
        $alice->signIn('admin', 'alice', 'alice-password-123');
        $reseller = $this->browser($this->app);
        $reseller->signIn('admin', 'rex', 'rex-password-1234');
        $root = $this->browser($this->app);
        $root->signIn('admin', 'root', self::ROOT_PASSWORD);

        $created = $root->post('/admin/staff', ['username' => 'carol', 'name' => 'Carol', 'email' => '', 'password' => 'carol-password-12']);

        self::assertSame(403, $alice->get('/admin/staff')->status());
        self::assertSame(403, $reseller->get('/admin/staff')->status());
        self::assertStringNotContainsString('Administrators', $reseller->get('/admin')->body());
        self::assertSame(303, $created->status());
        self::assertSame(303, $this->browser($this->app)->signIn('admin', 'carol', 'carol-password-12')->status());
    }

    public function test_disabling_an_administrator_ends_their_session_on_the_next_click(): void
    {
        $alice = $this->browser($this->app);
        $alice->signIn('admin', 'alice', 'alice-password-123');
        $root = $this->browser($this->app);
        $root->signIn('admin', 'root', self::ROOT_PASSWORD);
        $id = (string) ($this->app->container()->get(PanelUsers::class)->find('alice')['id'] ?? '');

        $root->post('/admin/staff/' . $id . '/active', ['active' => '0']);

        self::assertSame(302, $alice->get('/admin')->status());
        self::assertSame(422, $this->browser($this->app)->signIn('admin', 'alice', 'alice-password-123')->status());
    }

    public function test_the_page_asked_for_is_shown_after_sign_in_but_never_another_site(): void
    {
        $browser = $this->browser($this->app);
        $browser->get('/admin/login?next=%2Fadmin%2Faudit');
        $kept = $browser->post('/admin/login', ['username' => 'alice', 'password' => 'alice-password-123']);

        $evil = $this->browser($this->app);
        $evil->get('/admin/login?next=%2F%2Fevil.example%2Fadmin');
        $refused = $evil->post('/admin/login', ['username' => 'alice', 'password' => 'alice-password-123']);

        self::assertSame('/admin/audit', $kept->header('Location'));
        self::assertSame('/admin', $refused->header('Location'));
    }

    public function test_a_form_without_its_csrf_token_is_refused(): void
    {
        $response = $this->app->handle(\App\Engine\Http\Request::create('POST', '/admin/login', [
            'body' => ['username' => 'alice', 'password' => 'alice-password-123'],
        ]));

        self::assertSame(403, $response->status());
    }

    public function test_two_step_sign_in_from_set_up_to_recovery_code(): void
    {
        $browser = $this->browser($this->app);
        $browser->signIn('user', 'bob', 'bob-password-1234');

        $browser->post('/panel/security/two-factor/begin');
        $page = $browser->get('/panel/security')->body();
        self::assertSame(1, \preg_match('#<code>([A-Z2-7]{16,})</code>#', $page, $m));
        $secret = $m[1] ?? '';

        $wrong = $browser->post('/panel/security/two-factor/confirm', ['code' => '000000']);
        $confirmed = $browser->post('/panel/security/two-factor/confirm', ['code' => Totp::code($secret)]);
        \preg_match_all('#<span>([a-z0-9]{4,}-[a-z0-9]{4,})</span>#i', $confirmed->body(), $codes);
        $browser->post('/panel/logout');

        // The password alone now leads to the code form, not the account.
        $step = $browser->signIn('user', 'bob', 'bob-password-1234');
        $blocked = $browser->get('/panel');
        $badCode = $browser->post('/panel/login/code', ['code' => '123456']);
        $in = $browser->post('/panel/login/code', ['code' => Totp::code($secret, \time() + 30)]);

        // A recovery code works once.
        $other = $this->browser($this->app);
        $other->signIn('user', 'bob', 'bob-password-1234');
        $recovered = $other->post('/panel/login/code', ['code' => $codes[1][0] ?? '']);
        $again = $this->browser($this->app);
        $again->signIn('user', 'bob', 'bob-password-1234');
        $reused = $again->post('/panel/login/code', ['code' => $codes[1][0] ?? '']);

        self::assertSame(422, $wrong->status());
        self::assertSame(200, $confirmed->status());
        self::assertCount(10, $codes[1]);
        self::assertSame('/panel/login/code', $step->header('Location'));
        self::assertSame(302, $blocked->status());
        self::assertSame(422, $badCode->status());
        self::assertSame('/panel', $in->header('Location'));
        self::assertSame('/panel', $recovered->header('Location'));
        self::assertSame(422, $reused->status());
        self::assertContains('auth.two_factor_enabled', $this->actions());
    }

    public function test_changing_a_password_needs_the_current_one(): void
    {
        $browser = $this->browser($this->app);
        $browser->signIn('admin', 'alice', 'alice-password-123');

        $wrong = $browser->post('/admin/security/password', ['current_password' => 'nope', 'new_password' => 'a-new-password-99', 'repeat_password' => 'a-new-password-99']);
        $short = $browser->post('/admin/security/password', ['current_password' => 'alice-password-123', 'new_password' => 'short', 'repeat_password' => 'short']);
        $done = $browser->post('/admin/security/password', ['current_password' => 'alice-password-123', 'new_password' => 'a-new-password-99', 'repeat_password' => 'a-new-password-99']);

        self::assertSame(422, $wrong->status());
        self::assertSame(422, $short->status());
        self::assertSame(303, $done->status());
        self::assertSame(303, $this->browser($this->app)->signIn('admin', 'alice', 'a-new-password-99')->status());
    }

    public function test_the_audit_log_shows_everyone_to_admins_and_only_yourself_to_others(): void
    {
        $this->browser($this->app)->signIn('admin', 'alice', 'wrong-password');
        $owner = $this->browser($this->app);
        $owner->signIn('user', 'bob', 'bob-password-1234');
        $alice = $this->browser($this->app);
        $alice->signIn('admin', 'alice', 'alice-password-123');

        $all = $alice->get('/admin/audit')->body();
        $own = $owner->get('/panel/activity')->body();

        self::assertStringContainsString('auth.failed', $all);
        self::assertStringContainsString('bob', $all);
        self::assertStringContainsString('auth.login', $own);
        self::assertStringNotContainsString('auth.failed', $own);
    }

    /** @return list<string> audit actions, oldest first */
    private function actions(): array
    {
        $rows = $this->app->container()->get(ConnectionManager::class)->connection()
            ->table('audit_log')->orderBy('id')->get();

        return \array_values(\array_map(static fn(array $row): string => (string) $row['action'], $rows));
    }
}
