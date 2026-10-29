<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\Authenticators\SessionAuthenticator;
use App\Engine\Auth\Authenticators\TokenAuthenticator;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\RateLimiter;
use App\Engine\Security\Secret;
use App\Engine\Session\SessionManager;
use App\Engine\Session\Stores\ArrayStore;
use App\Tests\Fixtures\Auth\FakeProvider;
use App\Tests\Support\TestCase;

/**
 * Who a request is, and what logging in and out actually do.
 *
 * The two tests worth reading first are the fixation one -- logging in changes
 * the session id -- and the enumeration one, where a wrong username and a wrong
 * password have to be indistinguishable.
 */
final class AuthManagerTest extends TestCase
{
    private FakeProvider $provider;

    private SessionManager $sessions;

    private HookEngine $hooks;

    private Password $passwords;

    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        // Cost 4 is the bcrypt minimum. Correctness is identical and the suite
        // does not spend a second per hash proving what password_verify()
        // already guarantees.
        $this->passwords = new Password(['cost' => 4]);
        $this->provider = new FakeProvider();
        $this->sessions = new SessionManager(new ArrayStore());
        $this->hooks = new HookEngine();
        $this->fired = [];

        foreach (['auth.login', 'auth.logout', 'auth.failed', 'auth.identified', 'auth.rehash'] as $hook) {
            $this->hooks->add($hook, function () use ($hook): void {
                $this->fired[] = $hook;
            }, 10, 'tests');
        }
    }

    private function manager(?Request $request = null, bool $withToken = true): AuthManager
    {
        $authenticators = $withToken ? [new TokenAuthenticator($this->provider)] : [];
        $authenticators[] = new SessionAuthenticator($this->sessions, $this->provider);

        $manager = new AuthManager(
            $this->provider,
            $this->passwords,
            $authenticators,
            $this->sessions,
            $this->hooks,
        );

        $request ??= Request::create('GET', '/');
        $this->sessions->onRequest($request);
        $manager->onRequest($request);

        return $manager;
    }

    private function withPassword(string $id, string $name, string $password, string ...$roles): void
    {
        $this->provider->add($id, $name, $this->passwords->hash(new Secret($password)), \array_values($roles));
    }

    // ---- who is this -------------------------------------------------------

    public function test_a_request_with_no_credentials_is_a_guest(): void
    {
        $identity = $this->manager()->identity();

        self::assertTrue($identity->isGuest());
        self::assertSame('guest', $identity->name);
    }

    public function test_a_guest_is_never_null(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->check());
        self::assertTrue($manager->guest());
        self::assertInstanceOf(Identity::class, $manager->identity());
    }

    /**
     * The laziness claim, measured.
     *
     * Nothing asks who the request is, so nothing looks anybody up. A framework
     * that identified eagerly would do a storage read on every request to every
     * public page.
     */
    public function test_nobody_is_looked_up_until_somebody_asks(): void
    {
        $this->manager();

        self::assertSame(0, $this->provider->lookups);
    }

    public function test_the_answer_is_worked_out_once(): void
    {
        $manager = $this->manager();
        $manager->identity();
        $manager->identity();

        self::assertLessThanOrEqual(1, $this->provider->lookups);
    }

    // ---- passwords ---------------------------------------------------------

    public function test_the_right_password_logs_in(): void
    {
        $this->withPassword('7', 'ada', 'correct horse', 'clerk');

        $identity = $this->manager()->attempt('ada', new Secret('correct horse'));

        self::assertNotNull($identity);
        self::assertSame('7', $identity->id);
        self::assertSame(['clerk'], $identity->roles);
        self::assertContains('auth.login', $this->fired);
    }

    public function test_the_wrong_password_does_not(): void
    {
        $this->withPassword('7', 'ada', 'correct horse');

        self::assertNull($this->manager()->attempt('ada', new Secret('wrong')));
        self::assertContains('auth.failed', $this->fired);
        self::assertNotContains('auth.login', $this->fired);
    }

    /**
     * A wrong username and a wrong password must be the same answer.
     *
     * Anything that distinguishes them is a way to find out which accounts
     * exist, and the usual next step is to try those names on other sites.
     */
    public function test_an_unknown_account_fails_the_same_way_as_a_wrong_password(): void
    {
        $this->withPassword('7', 'ada', 'correct horse');

        $manager = $this->manager();

        self::assertNull($manager->attempt('ada', new Secret('wrong')));
        self::assertNull($manager->attempt('nobody', new Secret('wrong')));
        self::assertSame(['auth.failed', 'auth.failed'], $this->fired);
    }

    // ---- guessing --------------------------------------------------------------

    private function throttled(RateLimiter $limiter, string $ip = '203.0.113.7'): AuthManager
    {
        $manager = new AuthManager(
            $this->provider,
            $this->passwords,
            [new SessionAuthenticator($this->sessions, $this->provider)],
            $this->sessions,
            $this->hooks,
            limiter: $limiter,
        );

        $request = Request::create('POST', '/login', ['server' => ['REMOTE_ADDR' => $ip]]);
        $this->sessions->onRequest($request);
        $manager->onRequest($request);

        return $manager;
    }

    /**
     * One address guessing one account: five tries, then 429 -- and the right
     * password arriving after that gets the same 429 rather than a login, so a
     * guess past the limit tells the attacker nothing.
     */
    public function test_guessing_one_accounts_password_is_throttled(): void
    {
        $this->withPassword('7', 'ada', 'correct horse');
        $limiter = new RateLimiter(new MemoryStore());
        $manager = $this->throttled($limiter);

        for ($i = 0; $i < 5; ++$i) {
            self::assertNull($manager->attempt('ada', new Secret('guess ' . $i)));
        }

        try {
            $manager->attempt('ada', new Secret('correct horse'));
            self::fail('the sixth try was checked');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status());
            self::assertArrayHasKey('Retry-After', $e->headers());
        }
    }

    /** A different address is still allowed, and so is the right password before the limit. */
    public function test_the_strict_limit_is_per_account_and_address(): void
    {
        $this->withPassword('7', 'ada', 'correct horse');
        $limiter = new RateLimiter(new MemoryStore());

        for ($i = 0; $i < 5; ++$i) {
            $this->throttled($limiter, '203.0.113.7')->attempt('ada', new Secret('guess'));
        }

        self::assertNotNull($this->throttled($limiter, '198.51.100.9')->attempt('ada', new Secret('correct horse')));
    }

    /** Mistyping and then getting it right starts the count again. */
    public function test_the_right_password_clears_the_strict_count(): void
    {
        $this->withPassword('7', 'ada', 'correct horse');
        $limiter = new RateLimiter(new MemoryStore());
        $manager = $this->throttled($limiter);

        for ($i = 0; $i < 4; ++$i) {
            $manager->attempt('ada', new Secret('typo'));
        }

        self::assertNotNull($manager->attempt('ada', new Secret('correct horse')));

        for ($i = 0; $i < 4; ++$i) {
            self::assertNull($manager->attempt('ada', new Secret('typo')), 'still counting from zero');
        }
    }

    /** One address trying a list of accounts ("spraying") meets the per-address limit. */
    public function test_spraying_many_accounts_from_one_address_is_throttled(): void
    {
        $limiter = new RateLimiter(new MemoryStore());

        for ($i = 0; $i < 30; ++$i) {
            self::assertNull($this->throttled($limiter)->attempt('user' . $i, new Secret('Summer2026!')));
        }

        $this->expectException(HttpException::class);

        $this->throttled($limiter)->attempt('user-31', new Secret('Summer2026!'));
    }

    /** A missing account counts the same as a wrong password, or the limit itself would enumerate. */
    public function test_a_missing_account_is_counted_like_a_real_one(): void
    {
        $limiter = new RateLimiter(new MemoryStore());
        $manager = $this->throttled($limiter);

        for ($i = 0; $i < 5; ++$i) {
            $manager->attempt('nobody', new Secret('x'));
        }

        $this->expectException(HttpException::class);

        $manager->attempt('nobody', new Secret('x'));
    }

    /**
     * A missing account costs what a wrong password costs, at whatever cost is
     * configured. A fixed dummy hash -- as there used to be -- has a fixed
     * cost, and stopped matching the moment the cost was raised.
     */
    public function test_a_missing_account_costs_the_same_as_a_wrong_password_at_any_cost(): void
    {
        $passwords = new Password(['cost' => 6]);
        $hash = $passwords->hash(new Secret('right'));

        $time = static function (\Closure $verify): float {
            $best = \PHP_FLOAT_MAX;

            for ($i = 0; $i < 3; ++$i) {
                $start = \hrtime(true);
                $verify();
                $best = \min($best, (\hrtime(true) - $start) / 1e6);
            }

            return $best;
        };

        $missing = $time(static fn() => $passwords->verify(new Secret('x'), null));
        $wrong = $time(static fn() => $passwords->verify(new Secret('x'), $hash));

        // Cost 6, not 10, on purpose: the old fixed dummy was bcrypt at cost
        // 10, so a missing account took ~16x as long as a wrong password here.
        // Within a factor of two is "the same".
        self::assertGreaterThan($wrong / 2, $missing);
        self::assertLessThan($wrong * 2, $missing);
    }

    public function test_a_suspended_account_cannot_log_in(): void
    {
        $this->provider->add('7', 'ada', $this->passwords->hash(new Secret('x')), [], active: false);

        self::assertNull($this->manager()->attempt('ada', new Secret('x')));
    }

    /** No hash means this account authenticates some other way, not "no password needed". */
    public function test_an_account_with_no_password_cannot_log_in_with_one(): void
    {
        $this->provider->add('7', 'ada');

        self::assertNull($this->manager()->attempt('ada', new Secret('')));
        self::assertNull($this->manager()->attempt('ada', new Secret('anything')));
    }

    public function test_an_empty_login_is_a_programming_mistake(): void
    {
        $this->expectException(AuthException::class);

        $this->manager()->attempt('', new Secret('x'));
    }

    public function test_an_outdated_hash_is_reported_so_it_can_be_upgraded(): void
    {
        // Hashed with a weaker cost than this manager now asks for.
        $this->provider->add('7', 'ada', (new Password(['cost' => 4]))->hash(new Secret('x')));

        $manager = new AuthManager($this->provider, new Password(['cost' => 5]), [], $this->sessions, $this->hooks);
        $manager->onRequest(Request::create('GET', '/'));

        self::assertNotNull($manager->attempt('ada', new Secret('x')));
        self::assertContains('auth.rehash', $this->fired);
    }

    // ---- the session -------------------------------------------------------

    /**
     * Session fixation, closed.
     *
     * An attacker who planted a session id in the victim's browser before the
     * login holds one that stopped meaning anything the moment it succeeded.
     */
    public function test_logging_in_changes_the_session_id(): void
    {
        $this->withPassword('7', 'ada', 'x');

        $manager = $this->manager();
        $before = $this->sessions->session()->id();

        $manager->attempt('ada', new Secret('x'));

        self::assertNotSame($before, $this->sessions->session()->id());
    }

    /**
     * The id from before the login leads nowhere -- not even for the grace
     * window that other regenerations get. Otherwise an attacker who obtained
     * a genuine id and planted it would be handed the logged-in session.
     */
    public function test_the_id_from_before_the_login_is_not_followed_to_it(): void
    {
        $this->withPassword('7', 'ada', 'x');

        $this->manager();
        $this->sessions->session()->set('cart', 3);
        $this->sessions->save();
        $planted = $this->sessions->session()->id();

        $this->manager(Request::create('POST', '/login', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $planted],
        ]))->attempt('ada', new Secret('x'));
        $this->sessions->save();

        $attacker = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $planted],
        ]));

        self::assertTrue($attacker->guest());
    }

    public function test_a_logged_in_session_is_recognised_on_the_next_request(): void
    {
        $this->withPassword('7', 'ada', 'x');

        $first = $this->manager();
        $first->attempt('ada', new Secret('x'));
        $this->sessions->save();

        $id = $this->sessions->session()->id();

        $second = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertTrue($second->check());
        self::assertSame('7', $second->identity()->id);
    }

    /**
     * Roles are re-read, never carried in the session.
     *
     * Revoking somebody's role has to take effect on their next click rather
     * than on their next login, and a serialised identity in the session is a
     * snapshot that cannot.
     */
    public function test_a_role_taken_away_is_gone_on_the_next_request(): void
    {
        $this->withPassword('7', 'ada', 'x', 'manager');

        $first = $this->manager();
        $first->attempt('ada', new Secret('x'));
        $this->sessions->save();
        $id = $this->sessions->session()->id();

        // Demoted while the session is still open.
        $this->withPassword('7', 'ada', 'x', 'clerk');

        $second = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertSame(['clerk'], $second->identity()->roles);
    }

    public function test_a_suspended_account_stops_being_recognised(): void
    {
        $this->withPassword('7', 'ada', 'x');

        $first = $this->manager();
        $first->attempt('ada', new Secret('x'));
        $this->sessions->save();
        $id = $this->sessions->session()->id();

        $this->provider->add('7', 'ada', $this->passwords->hash(new Secret('x')), [], active: false);

        $second = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
        ]));

        self::assertTrue($second->guest());
    }

    /** A session cookie nobody issued does not start a session or a login. */
    public function test_a_planted_session_key_cannot_log_anybody_in(): void
    {
        $manager = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => 'not-a-session-id'],
        ]));

        self::assertTrue($manager->guest());
    }

    public function test_logging_out_empties_the_session_and_changes_its_id(): void
    {
        $this->withPassword('7', 'ada', 'x');

        $manager = $this->manager();
        $manager->attempt('ada', new Secret('x'));

        $session = $this->sessions->session();
        $session->set('basket', ['a pencil']);
        $loggedIn = $session->id();

        $manager->logout();

        self::assertTrue($manager->guest());
        self::assertNotSame($loggedIn, $this->sessions->session()->id());
        self::assertNull($this->sessions->session()->get('basket'), 'a logout is not just forgetting the name');
        self::assertContains('auth.logout', $this->fired);
    }

    public function test_logging_out_when_nobody_is_logged_in_says_nothing(): void
    {
        $this->manager()->logout();

        self::assertNotContains('auth.logout', $this->fired);
    }

    // ---- tokens ------------------------------------------------------------

    public function test_a_bearer_token_identifies_a_request(): void
    {
        $this->provider->add('7', 'ada', null, ['api']);
        $this->provider->addToken('7', 'a-real-token');

        $manager = $this->manager(Request::create('GET', '/', [
            'headers' => ['Authorization' => 'Bearer a-real-token'],
        ]));

        self::assertTrue($manager->check());
        self::assertSame(['api'], $manager->identity()->roles);
    }

    public function test_an_unknown_token_is_a_guest_rather_than_a_refusal(): void
    {
        $manager = $this->manager(Request::create('GET', '/', [
            'headers' => ['Authorization' => 'Bearer nonsense'],
        ]));

        self::assertTrue($manager->guest());
    }

    /**
     * The deliberate credential beats the ambient one.
     *
     * A cookie is attached by the browser on its own; a token was put there on
     * purpose, so when both arrive the token is what was meant.
     */
    public function test_a_token_wins_over_a_session(): void
    {
        $this->withPassword('7', 'ada', 'x');
        $this->provider->add('9', 'grace', null, ['api'])->addToken('9', 'graces-token');

        $first = $this->manager();
        $first->attempt('ada', new Secret('x'));
        $this->sessions->save();
        $id = $this->sessions->session()->id();

        $second = $this->manager(Request::create('GET', '/', [
            'cookies' => [SessionManager::DEFAULT_COOKIE => $id],
            'headers' => ['Authorization' => 'Bearer graces-token'],
        ]));

        self::assertSame('9', $second->identity()->id);
    }

    public function test_the_authenticators_are_named(): void
    {
        self::assertSame('bearer token, session cookie', $this->manager()->describe());
        self::assertSame('session cookie', $this->manager(withToken: false)->describe());
    }
}
