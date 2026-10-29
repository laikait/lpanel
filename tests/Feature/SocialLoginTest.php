<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AuthException;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Auth\Social\OidcProvider;
use App\Engine\Auth\Social\SocialException;
use App\Engine\Auth\Social\SocialLogin;
use App\Engine\Auth\Social\SocialUser;
use App\Engine\Auth\Totp;
use App\Engine\Auth\TwoFactor;
use App\Engine\Auth\TwoFactorResult;
use App\Engine\Auth\UserProvider;
use App\Engine\Core\Application;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Http\Client\Client;
use App\Engine\Http\Cookie;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\Auth\FakeOidc;
use App\Tests\Fixtures\Auth\SocialMemoryAccounts;
use App\Tests\Support\TestCase;

final class SocialLoginTest extends TestCase
{
    private Application $app;

    private FakeOidc $idp;

    private SocialMemoryAccounts $accounts;

    /** @var list<string> who auth.login saw */
    private array $logins = [];

    /**
     * @param array<string, mixed> $social
     * @param array<string, mixed> $twoFactor
     */
    private function boot(array $social = [], array $twoFactor = []): void
    {
        $this->app = $this->shippedApplication([
            'app' => ['url' => 'https://shop.example'],
            'security' => ['key' => Signer::generate()],
            'session' => ['store' => 'memory'],
            'auth' => ['social' => [
                'providers' => ['idp' => ['driver' => 'oidc', 'issuer' => FakeOidc::ISSUER, 'client_id' => 'client-1', 'client_secret' => 'secret-1']],
                ...$social,
            ], 'two_factor' => $twoFactor],
        ])->boot();

        $container = $this->app->container();
        $this->idp = new FakeOidc();
        $container->instance(Client::class, new Client($this->idp));
        $this->accounts = new SocialMemoryAccounts();
        $this->accounts->add('ada', 'ada@example.test');
        $container->instance(UserProvider::class, $this->accounts);
        $container->get(HookEngine::class)->add('auth.login', function ($identity): void {
            $this->logins[] = $identity->id;
        });
    }

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $body
     */
    private function request(string $method, string $uri, array $cookies = [], array $body = []): Response
    {
        return $this->app->handle(Request::create($method, $uri, [
            'cookies' => $cookies,
            'body' => $body,
            'server' => ['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9'],
        ]));
    }

    /**
     * The browser's side: follow /auth/idp, approve at the provider, and come back.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{Response, string, string, string} the callback's response, the flow cookie, the state and the code
     */
    private function signIn(array $claims, string $return = '/account'): array
    {
        $start = $this->request('GET', '/auth/idp?return=' . \rawurlencode($return));
        self::assertSame(302, $start->status());

        $location = (string) $start->header('Location');
        self::assertStringStartsWith(FakeOidc::ISSUER . '/authorize?', $location);
        \parse_str((string) \parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('https://shop.example/auth/idp/callback', $query['redirect_uri'] ?? null);

        $cookie = null;

        foreach ($start->cookies() as $set) {
            if ($set->name === SocialLogin::COOKIE) {
                $cookie = $set;
            }
        }

        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertTrue($cookie->secure && $cookie->httpOnly);
        self::assertSame('None', $cookie->sameSite);

        $code = $this->idp->authorize($location, $claims);
        $state = self::str($query, 'state');
        $callback = $this->request('GET', '/auth/idp/callback?' . \http_build_query(['state' => $state, 'code' => $code]), [SocialLogin::COOKIE => $cookie->value]);

        return [$callback, $cookie->value, $state, $code];
    }

    /**
     * Like signIn(), but the callback goes straight to SocialLogin, so a
     * refusal arrives as its exception rather than a rendered 500.
     *
     * @param array<string, mixed> $claims
     */
    private function callbackDirectly(array $claims): SocialUser
    {
        $start = $this->request('GET', '/auth/idp');
        $location = (string) $start->header('Location');
        \parse_str((string) \parse_url($location, \PHP_URL_QUERY), $query);

        return $this->app->container()->get(SocialLogin::class)->callback('idp', Request::create('GET', '/auth/idp/callback', [
            'query' => ['state' => self::str($query, 'state'), 'code' => $this->idp->authorize($location, $claims)],
            'cookies' => [SocialLogin::COOKIE => $start->cookies()[0]->value],
        ]));
    }

    public function test_a_linked_account_signs_in_and_lands_where_it_asked(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';

        [$response] = $this->signIn(['sub' => 'sub-1', 'email' => 'someone@else.test']);

        self::assertSame(302, $response->status());
        self::assertSame('/account', $response->header('Location'));
        self::assertSame(['ada'], $this->logins);
    }

    public function test_a_verified_email_links_an_existing_account(): void
    {
        [$response] = $this->signIn(['sub' => 'sub-2', 'email' => 'ADA@example.test', 'email_verified' => true]);

        self::assertSame(302, $response->status());
        self::assertSame(['ada'], $this->logins);
        self::assertSame('ada', $this->accounts->links['idp:sub-2'] ?? null);
    }

    /**
     * The provider is a first factor. Without this, turning two-factor on
     * protected nothing against anybody who could sign in to the provider --
     * or open an account there under the same verified email.
     */
    public function test_two_factor_is_still_asked_for_after_the_provider(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        $this->accounts->secrets['ada'] = Totp::secret();

        // No code form in this application: refused, and nobody logged in.
        [$refused] = $this->signIn(['sub' => 'sub-1']);

        self::assertSame(403, $refused->status());
        self::assertSame([], $this->logins);
    }

    public function test_two_factor_sends_a_social_sign_in_to_the_code_form(): void
    {
        $this->boot(twoFactor: ['route' => 'home']);
        $this->accounts->links['idp:sub-1'] = 'ada';
        $this->accounts->secrets['ada'] = $secret = Totp::secret();

        [$response] = $this->signIn(['sub' => 'sub-1']);

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));
        self::assertSame([], $this->logins, 'the provider alone logged the account in');

        $twoFactor = $this->app->container()->get(TwoFactor::class);

        self::assertTrue($twoFactor->pending());
        self::assertSame('ada', $twoFactor->challenge(Totp::code($secret))?->id);
        self::assertSame(['ada'], $this->logins);
    }

    public function test_attempt_and_login_agree_on_two_factor(): void
    {
        $this->accounts->secrets['ada'] = Totp::secret();
        $social = $this->app->container()->get(SocialLogin::class);
        $user = new SocialUser('idp', 'sub-9', 'ada@example.test', true);

        self::assertNull($social->login($user));
        self::assertSame(TwoFactorResult::ChallengeRequired, $social->attempt($user));
        self::assertSame([], $this->logins);
    }

    public function test_an_unverified_email_never_links(): void
    {
        [$response] = $this->signIn(['sub' => 'sub-3', 'email' => 'ada@example.test', 'email_verified' => false]);

        self::assertSame(403, $response->status());
        self::assertSame([], $this->logins);
        self::assertSame([], $this->accounts->links);
    }

    public function test_a_newcomer_gets_an_account_only_when_registration_is_on(): void
    {
        [$refused] = $this->signIn(['sub' => 'sub-4', 'email' => 'grace@example.test', 'email_verified' => true]);
        self::assertSame(403, $refused->status());

        $this->boot(['register' => true]);
        [$response] = $this->signIn(['sub' => 'sub-4', 'email' => 'grace@example.test', 'email_verified' => true]);

        self::assertSame(302, $response->status());
        self::assertSame(2, $this->accounts->count());
        self::assertSame(['new-2'], $this->logins);
    }

    public function test_the_social_account_filter_decides_last(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        $this->app->container()->get(FilterEngine::class)->add('social.account', static fn($account, SocialUser $user) => ($user->raw['hd'] ?? null) === 'example.test' ? $account : false);

        [$response] = $this->signIn(['sub' => 'sub-1']);

        self::assertSame(403, $response->status());
    }

    public function test_another_browsers_state_is_refused(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        [, $cookie, , ] = $this->signIn(['sub' => 'sub-1']);

        // An attacker's own code and state, planted on the victim's browser.
        $start = $this->request('GET', '/auth/idp');
        $code = $this->idp->authorize((string) $start->header('Location'), ['sub' => 'attacker']);
        \parse_str((string) \parse_url((string) $start->header('Location'), \PHP_URL_QUERY), $query);

        $response = $this->request('GET', '/auth/idp/callback?' . \http_build_query(['state' => 'forged', 'code' => $code]), [SocialLogin::COOKIE => $cookie]);
        self::assertSame(400, $response->status());

        $noCookie = $this->request('GET', '/auth/idp/callback?' . \http_build_query(['state' => $query['state'] ?? '', 'code' => $code]));
        self::assertSame(400, $noCookie->status());
        self::assertSame(['ada'], $this->logins);
    }

    public function test_a_replayed_code_is_refused_by_the_provider(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        [, $cookie, $state, $code] = $this->signIn(['sub' => 'sub-1']);
        $again = Request::create('GET', '/auth/idp/callback', ['query' => ['state' => $state, 'code' => $code], 'cookies' => [SocialLogin::COOKIE => $cookie]]);

        $this->expectException(SocialException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->app->container()->get(SocialLogin::class)->callback('idp', $again);
    }

    public function test_apples_posted_callback_works_without_a_csrf_token(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        $start = $this->request('GET', '/auth/idp');
        $location = (string) $start->header('Location');
        \parse_str((string) \parse_url($location, \PHP_URL_QUERY), $query);
        $cookie = $start->cookies()[0]->value;

        $response = $this->request('POST', '/auth/idp/callback', [SocialLogin::COOKIE => $cookie], [
            'state' => self::str($query, 'state'),
            'code' => $this->idp->authorize($location, ['sub' => 'sub-1']),
        ]);

        self::assertSame(302, $response->status());
        self::assertSame(['ada'], $this->logins);
    }

    public function test_a_denied_consent_is_a_400(): void
    {
        self::assertSame(400, $this->request('GET', '/auth/idp/callback?error=access_denied')->status());
    }

    public function test_an_unconfigured_provider_is_a_404(): void
    {
        self::assertSame(404, $this->request('GET', '/auth/myspace')->status());
        self::assertSame(404, $this->request('GET', '/auth/myspace/callback?code=x&state=y')->status());
    }

    public function test_only_a_local_path_is_returned_to(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';

        foreach (['https://evil.example/', '//evil.example/x', '/\\evil.example'] as $return) {
            [$response] = $this->signIn(['sub' => 'sub-1'], $return);
            self::assertSame('/', $response->header('Location'), $return);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badTokens(): iterable
    {
        yield 'another audience' => [['aud' => 'someone-else'], '"aud"'];
        yield 'expired' => [['exp' => \time() - 3600], '"exp"'];
        yield 'another issuer' => [['iss' => 'https://evil.example'], '"iss"'];
        yield 'another sign-in' => [['nonce' => 'stolen'], '"nonce"'];
        yield 'from the future' => [['iat' => \time() + 3600], '"iat"'];
    }

    /** @param array<string, mixed> $tamper */
    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function test_an_id_token_that_is_not_for_this_sign_in_is_refused(array $tamper, string $claim): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        $this->idp->tamper = $tamper;

        try {
            $this->callbackDirectly(['sub' => 'sub-1']);
            self::fail('The token was accepted.');
        } catch (SocialException $e) {
            self::assertStringContainsString($claim, $e->getMessage());
            self::assertFalse($e->isClientError(), 'A bad token is a 500 to investigate, not a visitor mistake.');
        }

        [$response] = $this->signIn(['sub' => 'sub-1']);
        self::assertSame(500, $response->status());
        self::assertSame([], $this->logins);
    }

    public function test_a_rotated_key_is_fetched_again_once(): void
    {
        $this->accounts->links['idp:sub-1'] = 'ada';
        $this->signIn(['sub' => 'sub-1']);
        self::assertSame(1, $this->idp->keyFetches);

        $this->idp->kid = 'key-2';
        $this->signIn(['sub' => 'sub-1']);

        self::assertSame(2, $this->idp->keyFetches);
        self::assertSame(1, $this->idp->discoveryFetches, 'Discovery was not cached.');
        self::assertSame(['ada', 'ada'], $this->logins);
    }

    public function test_a_provider_without_social_accounts_is_named(): void
    {
        $this->app->container()->instance(UserProvider::class, new EmptyProvider());
        $user = $this->callbackDirectly(['sub' => 'sub-1']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('SocialAccounts');

        $this->app->container()->get(SocialLogin::class)->login($user);
    }

    public function test_a_custom_provider_can_be_added(): void
    {
        $social = $this->app->container()->get(SocialLogin::class);
        $social->extend('keycloak', static fn() => new OidcProvider(new Client(new FakeOidc()), 'keycloak', FakeOidc::ISSUER, 'client-1', 'secret-1'));

        self::assertSame(['idp', 'keycloak'], $social->names());
        self::assertSame('keycloak', $social->provider('keycloak')->name());
        self::assertTrue($this->app->container()->get(AuthManager::class)->guest());
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}
