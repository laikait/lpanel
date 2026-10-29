<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\AuthException;
use App\Engine\Auth\EmailVerification;
use App\Engine\Auth\Password;
use App\Engine\Auth\PasswordReset;
use App\Engine\Auth\Providers\EmptyProvider;
use App\Engine\Auth\UserProvider;
use App\Engine\Core\Application;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\Response;
use App\Engine\Mail\Message;
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\ArrayTransport;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Routing\RoutingException;
use App\Engine\Security\Secret;
use App\Engine\Security\Signer;
use App\Tests\Fixtures\Auth\MemoryAccounts;
use App\Tests\Support\TestCase;

final class PasswordResetTest extends TestCase
{
    private Application $app;

    private MemoryAccounts $accounts;

    /** @var list<Message> */
    private array $mailed = [];

    protected function setUp(): void
    {
        $this->app = $this->shippedApplication([
            'app' => ['url' => 'https://shop.example/store'],
            'security' => ['key' => Signer::generate()],
            'mail' => ['transport' => 'array', 'from' => ['address' => 'shop@example.test']],
            'queue' => ['store' => 'sync'],
            'auth' => ['password' => ['options' => ['cost' => 4]]],
        ])->boot();

        $container = $this->app->container();
        $this->accounts = new MemoryAccounts();
        $hash = $container->get(Password::class)->hash(new Secret('old password'));
        $this->accounts->add('7', 'ada@example.test', $hash, name: 'Ada');
        $this->accounts->add('8', 'suspended@example.test', $hash, active: false);
        $container->instance(UserProvider::class, $this->accounts);

        $router = $container->get(Router::class);
        $router->setBasePath('/store');
        $router->add((new Route('GET', '/account/reset', static fn(): Response => new Response('')))->name('password.reset'));
        $router->add((new Route('GET', '/account/verify', static fn(): Response => new Response('')))->name('email.verify'));

        $container->get(FilterEngine::class)->add('mail.message', function (Message $message): Message {
            $this->mailed[] = $message;

            return $message;
        });
    }

    private function resets(): PasswordReset
    {
        return $this->app->container()->get(PasswordReset::class);
    }

    private function verification(): EmailVerification
    {
        return $this->app->container()->get(EmailVerification::class);
    }

    /** The last link mailed, as the recipient reads it. */
    private function mailedLink(): string
    {
        $transport = $this->app->container()->get(Transport::class);
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $sent = $transport->sent();
        self::assertNotSame([], $sent, 'Nothing was mailed.');

        $raw = \quoted_printable_decode($sent[\count($sent) - 1]->raw);
        self::assertSame(1, \preg_match('#https://\S+?\?token=[A-Za-z0-9_.-]+#', $raw, $match), $raw);

        return $match[0] ?? '';
    }

    private function mailedToken(): string
    {
        \parse_str((string) \parse_url($this->mailedLink(), \PHP_URL_QUERY), $query);
        self::assertIsString($query['token'] ?? null);

        return $query['token'];
    }

    public function test_a_reset_link_is_mailed_built_from_app_url(): void
    {
        self::assertTrue($this->resets()->request('ada@example.test', '203.0.113.9'));

        self::assertCount(1, $this->mailed);
        $message = $this->mailed[0];
        self::assertSame('Reset your password', $message->subject);
        self::assertSame('ada@example.test', $message->to[0]->email);
        self::assertStringStartsWith('https://shop.example/store/account/reset?token=', $this->mailedLink());

        $transport = $this->app->container()->get(Transport::class);
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $raw = \quoted_printable_decode($transport->sent()[0]->raw);
        self::assertStringContainsString('Reset your password', $raw);
        self::assertStringContainsString('works once, for 60 minutes', $raw);
    }

    public function test_the_answer_is_the_same_whether_or_not_the_account_exists(): void
    {
        self::assertTrue($this->resets()->request('nobody@example.test'));
        self::assertTrue($this->resets()->request('suspended@example.test'));
        self::assertTrue($this->resets()->request(''));

        self::assertSame([], $this->mailed);
    }

    public function test_a_reset_sets_the_password_and_the_link_then_stops_working(): void
    {
        $this->resets()->request('ada@example.test');
        $token = $this->mailedToken();

        self::assertNotNull($this->resets()->check($token));
        $identity = $this->resets()->reset($token, new Secret('new password'));

        self::assertSame('7', $identity?->id);
        self::assertTrue($this->app->container()->get(Password::class)->verify(new Secret('new password'), $this->accounts->hashOf('7')));
        self::assertNull($this->resets()->reset($token, new Secret('again')), 'A link worked twice.');
        self::assertNull($this->resets()->check($token));
    }

    public function test_any_password_change_voids_outstanding_links(): void
    {
        $token = $this->resets()->token($this->accounts->byId('7') ?? throw new \LogicException());
        $this->accounts->change('7', hash: $this->app->container()->get(Password::class)->hash(new Secret('changed elsewhere')));

        self::assertNull($this->resets()->reset($token, new Secret('new password')));
    }

    public function test_a_changed_expired_or_foreign_token_is_refused(): void
    {
        $account = $this->accounts->byId('7') ?? throw new \LogicException();
        $token = $this->resets()->token($account);

        self::assertNull($this->resets()->check($token . 'x'));
        self::assertNull($this->resets()->check('garbage'));
        self::assertNull($this->resets()->check(''));
        // A verification token is signed under another purpose.
        self::assertNull($this->resets()->check($this->verification()->token($account)));

        $expired = new PasswordReset(
            $this->accounts,
            new Password(),
            $this->app->container()->get(Signer::class),
            $this->app->container()->get(\App\Engine\Mail\Mailer::class),
            $this->app->container()->get(\App\Engine\Routing\AppUrl::class),
            ttl: -1,
        );
        self::assertNull($this->resets()->check($expired->token($account)));
    }

    public function test_a_suspended_account_cannot_reset(): void
    {
        $token = $this->resets()->token($this->accounts->byId('8') ?? throw new \LogicException());

        self::assertNull($this->resets()->reset($token, new Secret('x')));
    }

    public function test_requests_are_rate_limited_per_address_whether_or_not_it_exists(): void
    {
        foreach (['ada@example.test', 'nobody@example.test'] as $login) {
            for ($i = 0; $i < 3; ++$i) {
                self::assertTrue($this->resets()->request(\strtoupper($login)));
            }

            self::assertFalse($this->resets()->request($login), $login);
        }

        self::assertCount(3, $this->mailed);
    }

    public function test_without_app_url_nothing_is_mailed(): void
    {
        $app = $this->shippedApplication(['security' => ['key' => Signer::generate()], 'mail' => ['transport' => 'array']])->boot();
        $app->container()->instance(UserProvider::class, $this->accounts);
        $app->container()->get(Router::class)->add((new Route('GET', '/r', static fn(): Response => new Response('')))->name('password.reset'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('APP_URL');

        $app->container()->get(PasswordReset::class)->request('ada@example.test');
    }

    public function test_a_provider_without_the_interface_is_named(): void
    {
        $this->app->container()->instance(UserProvider::class, new EmptyProvider());

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('PasswordResettable');

        $this->app->container()->get(PasswordReset::class)->request('ada@example.test');
    }

    public function test_an_address_is_verified_by_its_link(): void
    {
        $account = $this->accounts->byId('7') ?? throw new \LogicException();
        $this->verification()->send($account);

        self::assertSame('Confirm your email address', $this->mailed[0]->subject);
        self::assertStringStartsWith('https://shop.example/store/account/verify?token=', $this->mailedLink());

        $token = $this->mailedToken();
        self::assertSame('7', $this->verification()->verify($token)?->id);
        self::assertTrue($this->accounts->isVerified('7'));
        self::assertSame('7', $this->verification()->verify($token)?->id, 'Verifying twice is harmless.');
    }

    public function test_a_link_for_an_old_address_does_not_verify_the_new_one(): void
    {
        $token = $this->verification()->token($this->accounts->byId('7') ?? throw new \LogicException());
        $this->accounts->change('7', email: 'attacker@example.test');

        self::assertNull($this->verification()->verify($token));
        self::assertFalse($this->accounts->isVerified('7'));
    }
}
