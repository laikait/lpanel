<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Auth\Totp;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;
use App\Tests\Support\Browser;

/** Optional for everyone (product owner, 2026-10-27), and binding once switched on. */
final class TwoFactorTest extends ApplicationTestCase
{
    private const PASSWORD = 'a long enough password';

    private function signedIn(): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create('grace', 'Grace Hopper', null, $hash, ['admin']);
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'grace', 'password' => self::PASSWORD]);

        return $browser;
    }

    /** @return array{0: string, 1: list<string>} the secret and the recovery codes */
    private function enable(Browser $browser): array
    {
        $page = $browser->post('/account/security/2fa/begin')->body();
        if (\preg_match('/id="lp-secret">([A-Z2-7]+)<\/code>/', $page, $m) !== 1) {
            self::fail('The enrolment page shows no secret to type in.');
        }
        $secret = $m[1];

        $confirmed = $browser->post('/account/security/2fa/confirm', ['code' => Totp::code($secret)]);
        self::assertSame(200, $confirmed->status());
        if (\preg_match('/id="lp-recovery">(.*?)<\/div>/s', $confirmed->body(), $block) !== 1) {
            self::fail('The recovery codes are not shown.');
        }
        self::assertSame(10, \preg_match_all('/<code>([^<]+)<\/code>/', $block[1], $codes));

        return [$secret, $codes[1]];
    }

    public function test_it_is_off_until_the_user_turns_it_on(): void
    {
        $page = $this->signedIn()->get('/account/security');

        self::assertSame(200, $page->status());
        self::assertStringContainsString('Two-factor sign-in is off', $page->body());
    }

    public function test_once_on_a_password_alone_is_not_enough(): void
    {
        $browser = $this->signedIn();
        [, $recovery] = $this->enable($browser);
        $browser->post('/logout');

        $fresh = $this->browser();
        $step = $fresh->post('/login', ['login' => 'grace', 'password' => self::PASSWORD]);
        self::assertSame('/login/code', $step->header('Location'));
        self::assertFalse($fresh->data('/api/v1/session')['authenticated'], 'not signed in before the code');

        self::assertSame(422, $fresh->post('/login/code', ['code' => '000000'])->status());
        self::assertSame(303, $fresh->post('/login/code', ['code' => $recovery[0]])->status());
        self::assertTrue($fresh->data('/api/v1/session')['authenticated']);

        // A recovery code works once.
        $fresh->post('/logout');
        $again = $this->browser();
        $again->post('/login', ['login' => 'grace', 'password' => self::PASSWORD]);
        self::assertSame(422, $again->post('/login/code', ['code' => $recovery[0]])->status());
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        [$secret] = $this->enable($this->signedIn());
        $stored = $this->service(ConnectionManager::class)->connection()->table('users')->where('login', 'grace')->first();

        self::assertIsArray($stored);
        self::assertStringNotContainsString($secret, (string) $stored['totp_secret']);
        self::assertStringNotContainsString($secret, (string) $stored['recovery_codes']);
    }

    public function test_turning_it_off_needs_a_current_code(): void
    {
        $browser = $this->signedIn();
        [$secret] = $this->enable($browser);

        self::assertSame(422, $browser->post('/account/security/2fa/disable', ['code' => '123456'])->status());
        // The confirm step used this 30-second step; the next one is a fresh code.
        $off = $browser->post('/account/security/2fa/disable', ['code' => Totp::code($secret, \time() + 30)]);
        self::assertSame(303, $off->status());
        self::assertStringContainsString('Two-factor sign-in is off', $browser->get('/account/security')->body());
    }

    public function test_root_can_turn_it_on_too(): void
    {
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);
        [, $recovery] = $this->enable($browser);
        $browser->post('/logout');

        $fresh = $this->browser();
        self::assertSame('/login/code', $fresh->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD])->header('Location'));
        self::assertSame(303, $fresh->post('/login/code', ['code' => $recovery[1]])->status());
        self::assertSame(['root'], $fresh->data('/api/v1/session')['user']['roles']);
    }
}
