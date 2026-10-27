<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;

/** "Forgot your password?": one answer for everyone, a link that works once, every other session ended. */
final class PasswordResetTest extends ApplicationTestCase
{
    private const OLD = 'the original password';

    private const NEW = 'a brand new passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $hash = $this->service(Password::class)->hash(new Secret(self::OLD));
        $this->service(UserStore::class)->create('ada', 'Ada Lovelace', 'ada@example.test', $hash, ['customer']);
        $this->service(UserStore::class)->create('nomail', 'No Mail', null, $hash, ['customer']);
    }

    private function link(): string
    {
        $mail = $this->mailTo('ada@example.test');
        self::assertNotNull($mail, 'a reset email was sent');
        if (\preg_match('#https://panel\.test(/password/reset\?token=[A-Za-z0-9._~%-]+)#', $mail, $m) !== 1) {
            self::fail('The email has no reset link.');
        }

        return $m[1];
    }

    public function test_the_answer_is_the_same_whether_or_not_the_account_exists(): void
    {
        $bodies = [];

        foreach (['ada', 'nobody-here', 'nomail', 'root'] as $login) {
            $response = $this->browser('198.51.100.' . \count($bodies))->post('/password/forgot', ['login' => $login]);
            self::assertSame(200, $response->status(), $login);
            $bodies[] = \preg_replace('/name="_token" value="[^"]+"|content="[^"]+" name|<meta name="csrf-token" content="[^"]*">/', '', $response->body());
        }

        self::assertCount(1, \array_unique($bodies), 'every login gets the same page');
        self::assertNotNull($this->mailTo('ada@example.test'));
        self::assertNull($this->mailTo('root'), 'root has no reset link: its password is the server\'s');
    }

    public function test_the_link_sets_a_new_password_once_and_ends_other_sessions(): void
    {
        $elsewhere = $this->browser('203.0.113.50');
        $elsewhere->post('/login', ['login' => 'ada', 'password' => self::OLD]);
        self::assertTrue($elsewhere->data('/api/v1/session')['authenticated']);

        $visitor = $this->browser();
        $visitor->post('/password/forgot', ['login' => 'ada']);
        $link = $this->link();

        $form = $visitor->get($link);
        self::assertSame(200, $form->status());
        self::assertSame('no-referrer', $form->header('Referrer-Policy'), 'the token in the URL must not leak in a Referer');
        if (\preg_match('/name="token" value="([^"]+)"/', $form->body(), $m) !== 1) {
            self::fail('The form does not carry the token.');
        }
        $token = \html_entity_decode($m[1]);

        self::assertSame(422, $visitor->post('/password/reset', ['token' => $token, 'password' => self::NEW, 'password_confirmation' => 'something else'])->status());
        $done = $visitor->post('/password/reset', ['token' => $token, 'password' => self::NEW, 'password_confirmation' => self::NEW]);
        self::assertSame(303, $done->status());
        self::assertSame('/login', $done->header('Location'));
        self::assertFalse($visitor->data('/api/v1/session')['authenticated'], 'a reset does not sign anyone in');

        self::assertSame(410, $visitor->get($link)->status(), 'the link works once');
        self::assertFalse($elsewhere->data('/api/v1/session')['authenticated'], 'the session from before the reset has ended');

        self::assertSame(422, $this->browser('203.0.113.60')->post('/login', ['login' => 'ada', 'password' => self::OLD])->status());
        self::assertSame(303, $this->browser('203.0.113.61')->post('/login', ['login' => 'ada', 'password' => self::NEW])->status());

        $events = $this->service(ConnectionManager::class)->connection()->table('audit_events')->where('action', 'like', 'auth.password_reset%')->orderBy('id')->get();
        self::assertSame(['auth.password_reset_requested', 'auth.password_reset'], \array_column($events, 'action'));
    }

    /** Found on a real install: without a sender address, only accounts with email made the page fail. */
    public function test_a_mail_failure_does_not_reveal_that_the_account_exists(): void
    {
        $this->app(['mail' => ['from' => ['address' => '']]]);
        $hash = $this->service(Password::class)->hash(new Secret(self::OLD));
        $this->service(UserStore::class)->create('ada', 'Ada Lovelace', 'ada@example.test', $hash, ['customer']);

        $known = $this->browser('198.51.100.1')->post('/password/forgot', ['login' => 'ada']);
        $unknown = $this->browser('198.51.100.2')->post('/password/forgot', ['login' => 'nobody-here']);

        self::assertSame(200, $known->status());
        self::assertSame(200, $unknown->status());
        self::assertStringContainsString('Check your email', $known->body());
    }

    public function test_a_forged_or_missing_token_is_refused(): void
    {
        $browser = $this->browser();

        self::assertSame(410, $browser->get('/password/reset?token=not-a-token')->status());
        self::assertSame(410, $browser->get('/password/reset')->status());
        self::assertSame(410, $browser->post('/password/reset', ['token' => 'x.y', 'password' => self::NEW, 'password_confirmation' => self::NEW])->status());
    }

    public function test_requests_are_rate_limited(): void
    {
        $browser = $this->browser();
        $statuses = [];

        for ($i = 0; $i < 4; ++$i) {
            $statuses[] = $browser->post('/password/forgot', ['login' => 'ada'])->status();
        }

        self::assertSame([200, 200, 200, 429], $statuses, 'three a quarter-hour per login');
    }
}
