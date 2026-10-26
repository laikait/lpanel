<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;

final class PanelLoginTest extends ApplicationTestCase
{
    private function customer(string $login = 'ada', string $password = 'a long enough password'): User
    {
        $hash = $this->service(Password::class)->hash(new Secret($password));

        return $this->service(UserStore::class)->create($login, 'Ada Lovelace', 'ada@example.test', $hash, ['customer']);
    }

    public function test_a_panel_user_signs_in_and_out(): void
    {
        $this->customer();
        $browser = $this->browser();

        self::assertSame(303, $browser->post('/login', ['login' => 'Ada', 'password' => 'a long enough password'])->status());
        $session = $browser->data('/api/v1/session');
        self::assertSame('ada', $session['user']['login']);
        self::assertSame(['customer'], $session['user']['roles']);
        self::assertArrayNotHasKey('password_hash', $session['user']);

        self::assertSame(303, $browser->post('/logout')->status());
        self::assertFalse($browser->data('/api/v1/session')['authenticated']);
    }

    public function test_a_wrong_password_and_an_unknown_login_look_the_same(): void
    {
        $this->customer();

        $wrong = $this->browser()->post('/login', ['login' => 'ada', 'password' => 'not it']);
        $unknown = $this->browser()->post('/login', ['login' => 'nobody', 'password' => 'not it']);

        self::assertSame(422, $wrong->status());
        self::assertSame(422, $unknown->status());
        self::assertStringContainsString('Those details are not right.', $wrong->body());
        self::assertStringContainsString('Those details are not right.', $unknown->body());
        self::assertSame('auth.failed', $this->service(AuditLog::class)->latest()[0]['action']);
    }

    public function test_a_suspended_user_cannot_sign_in_or_keep_a_session(): void
    {
        $user = $this->customer();
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'ada', 'password' => 'a long enough password']);
        self::assertTrue($browser->data('/api/v1/session')['authenticated']);

        $this->service(ConnectionManager::class)->connection()->table('users')->where('uid', $user->uid)->update(['status' => 'suspended']);

        // The session holds only the id; byId() runs again on the next request.
        self::assertFalse($browser->data('/api/v1/session')['authenticated']);
        self::assertSame(422, $this->browser()->post('/login', ['login' => 'ada', 'password' => 'a long enough password'])->status());
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $this->customer();
        $browser = $this->browser();

        for ($i = 0; $i < 5; $i++) {
            $browser->post('/login', ['login' => 'ada', 'password' => 'guess ' . $i]);
        }
        $response = $browser->post('/login', ['login' => 'ada', 'password' => 'a long enough password']);

        self::assertSame(429, $response->status());
        self::assertStringContainsString('Too many attempts', $response->body());
    }

    public function test_the_session_id_changes_at_login(): void
    {
        $this->customer();
        $browser = $this->browser();
        $before = $browser->token();
        $browser->post('/login', ['login' => 'ada', 'password' => 'a long enough password']);

        self::assertNotSame($before, $browser->token(), 'the CSRF token rotates with the session at login');
    }
}
