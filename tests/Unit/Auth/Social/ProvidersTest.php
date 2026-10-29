<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Social;

use App\Engine\Auth\Social\FacebookProvider;
use App\Engine\Auth\Social\GitHubProvider;
use App\Engine\Auth\Social\Jwt;
use App\Engine\Auth\Social\OidcProvider;
use App\Engine\Auth\Social\SocialException;
use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\FakeTransport;
use App\Engine\Http\Request;
use App\Tests\Support\TestCase;

final class ProvidersTest extends TestCase
{
    public function test_the_authorization_url_carries_pkce_and_state(): void
    {
        $url = (new GitHubProvider(new Client(), 'github', 'gh-client', 'gh-secret', ['read:user', 'user:email']))
            ->authorizationUrl('https://shop.example/auth/github/callback', 'st', 'ch', 'nonce');

        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);

        self::assertStringStartsWith(GitHubProvider::AUTHORIZE . '?', $url);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'gh-client',
            'redirect_uri' => 'https://shop.example/auth/github/callback',
            'scope' => 'read:user user:email',
            'state' => 'st',
            'code_challenge' => 'ch',
            'code_challenge_method' => 'S256',
        ], $query, 'GitHub is not OpenID: no nonce.');
    }

    public function test_github_uses_the_primary_email_and_its_verified_flag(): void
    {
        $fake = (new FakeTransport())
            ->push(200, ['access_token' => 'gho_x', 'token_type' => 'bearer'])
            ->push(200, ['id' => 583231, 'login' => 'octocat', 'name' => null, 'avatar_url' => 'https://avatars.example/1'])
            ->push(200, [
                ['email' => 'old@example.test', 'primary' => false, 'verified' => true],
                ['email' => 'Octo@Example.test', 'primary' => true, 'verified' => false],
            ]);

        $user = (new GitHubProvider(new Client($fake), 'github', 'gh-client', 'gh-secret', ['read:user', 'user:email']))
            ->user(Request::create('GET', '/'), 'code', 'https://shop.example/cb', 'verifier', 'n');

        self::assertSame('583231', $user->id);
        self::assertSame('octo@example.test', $user->email);
        self::assertFalse($user->emailVerified);
        self::assertSame('octocat', $user->name);
        self::assertSame('https://avatars.example/1', $user->avatar);

        $sent = $fake->sent();
        self::assertSame(GitHubProvider::TOKEN, $sent[0]->url);
        self::assertStringContainsString('code_verifier=verifier', $sent[0]->body);
        self::assertSame('Bearer gho_x', $sent[1]->header('Authorization'));
    }

    public function test_a_provider_error_names_itself(): void
    {
        $fake = (new FakeTransport())->push(200, ['error' => 'bad_verification_code']);

        $this->expectException(SocialException::class);
        $this->expectExceptionMessage('"github" sign-in failed exchanging the code: HTTP 200 (bad_verification_code)');

        (new GitHubProvider(new Client($fake), 'github', 'gh-client', 'gh-secret'))->user(Request::create('GET', '/'), 'c', 'https://x/cb', 'v', 'n');
    }

    public function test_facebook_email_is_unverified_unless_trusted(): void
    {
        foreach ([false, true] as $trust) {
            $fake = (new FakeTransport())
                ->push(200, ['access_token' => 'EAA'])
                ->push(200, ['id' => '10', 'name' => 'Zuck', 'email' => 'z@example.test', 'picture' => ['data' => ['url' => 'https://p.example/z']]]);

            $user = (new FacebookProvider(new Client($fake), 'fb', 'fb-secret', trustEmail: $trust))
                ->user(Request::create('GET', '/'), 'c', 'https://x/cb', 'v', 'n');

            self::assertSame('z@example.test', $user->email);
            self::assertSame($trust, $user->emailVerified);
            self::assertSame('https://p.example/z', $user->avatar);
        }
    }

    public function test_apples_client_secret_is_a_token_signed_with_its_key(): void
    {
        $key = \openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        \openssl_pkey_export($key, $pem);
        $details = \openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => Jwt::encode($details['ec']['x']), 'y' => Jwt::encode($details['ec']['y']), 'kid' => 'KEY123'];

        $secret = null;
        $fake = (new FakeTransport())
            ->push(200, ['issuer' => 'https://appleid.apple.com', 'authorization_endpoint' => 'https://appleid.apple.com/auth/authorize', 'token_endpoint' => 'https://appleid.apple.com/auth/token', 'jwks_uri' => 'https://appleid.apple.com/auth/keys'])
            ->pushAnswer(static function (ClientRequest $request) use (&$secret): ClientResponse {
                \parse_str($request->body, $form);
                $secret = $form['client_secret'] ?? null;

                return new ClientResponse(400, ['Content-Type' => 'application/json'], '{"error":"invalid_grant"}');
            });

        $apple = OidcProvider::apple(new Client($fake), 'com.example.web', 'TEAM123', 'KEY123', (string) $pem);
        self::assertStringContainsString('response_mode=form_post', $apple->authorizationUrl('https://x/cb', 's', 'c', 'n'));

        try {
            $apple->user(Request::create('POST', '/'), 'code', 'https://x/cb', 'v', 'n');
        } catch (SocialException) {
            // Expected: the fake refuses the code. The secret is what is under test.
        }

        self::assertIsString($secret);
        $claims = Jwt::verify($secret, [$jwk]);
        self::assertSame('TEAM123', $claims['iss']);
        self::assertSame('com.example.web', $claims['sub']);
        self::assertSame('https://appleid.apple.com', $claims['aud']);
        self::assertSame('KEY123', Jwt::decode($secret)[0]['kid']);
    }

    public function test_microsofts_tenant_placeholder_is_filled_from_the_token(): void
    {
        $key = \openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        \openssl_pkey_export($key, $pem);
        $details = \openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => Jwt::encode($details['ec']['x']), 'y' => Jwt::encode($details['ec']['y']), 'kid' => 'm1'];

        $fake = (new FakeTransport())
            ->push(200, ['issuer' => 'https://login.microsoftonline.com/{tenantid}/v2.0', 'authorization_endpoint' => 'https://a', 'token_endpoint' => 'https://t', 'jwks_uri' => 'https://k'])
            ->push(200, ['keys' => [$jwk]])
            ->push(200, ['keys' => [$jwk]]);
        $microsoft = OidcProvider::microsoft(new Client($fake), 'ms-client', 'ms-secret');
        $claims = ['aud' => 'ms-client', 'exp' => \time() + 60, 'nonce' => 'n', 'sub' => 's', 'tid' => 'contoso', 'email' => 'a@contoso.example'];

        $good = Jwt::sign($claims + ['iss' => 'https://login.microsoftonline.com/contoso/v2.0'], (string) $pem, 'ES256', 'm1');
        self::assertSame('contoso', $microsoft->claims($good, 'n')['tid']);

        $this->expectException(SocialException::class);
        $this->expectExceptionMessage('"iss"');

        $microsoft->claims(Jwt::sign($claims + ['iss' => 'https://login.microsoftonline.com/fabrikam/v2.0'], (string) $pem, 'ES256', 'm1'), 'n');
    }
}
