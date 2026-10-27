<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Signer;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Auth\RootVerdict;
use App\Modules\Identity\Auth\RootVerifier;
use App\Modules\Identity\Auth\SocketRootVerifier;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;

/** ADR-0010: root signs in with the system password, and with nothing else. */
final class RootLoginTest extends ApplicationTestCase
{
    public function test_root_signs_in_with_the_system_password_and_holds_every_capability(): void
    {
        $browser = $this->browser();
        $response = $browser->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(303, $response->status());
        self::assertSame('/', $response->header('Location'));

        $session = $browser->data('/api/v1/session');
        self::assertTrue($session['authenticated']);
        self::assertSame(['root'], $session['user']['roles']);
        self::assertSame('system', $session['user']['kind']);
        self::assertContains('*', $session['capabilities']);

        $home = $browser->get('/');
        self::assertSame(200, $home->status());
        self::assertStringContainsString('class="lp-root-pill"', $home->body());
    }

    public function test_login_name_is_case_and_space_insensitive_for_root(): void
    {
        $response = $this->browser()->post('/login', ['login' => '  ROOT ', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(303, $response->status());
    }

    public function test_a_wrong_root_password_fails_generically_and_is_audited(): void
    {
        $response = $this->browser()->post('/login', ['login' => 'root', 'password' => 'nope']);

        self::assertSame(422, $response->status());
        self::assertStringContainsString('Those details are not right.', $response->body());

        $events = $this->service(AuditLog::class)->latest();
        self::assertSame('auth.root.failed', $events[0]['action']);
        self::assertSame(['reason' => 'denied'], $events[0]['metadata']);
        self::assertSame('203.0.113.10', $events[0]['ip']);
    }

    public function test_a_panel_password_never_logs_in_as_root(): void
    {
        $this->service(UserStore::class)->ensureRoot();
        // Even with a hash planted in root's row, the root login goes to authd and nowhere else.
        $this->service(ConnectionManager::class)->connection()->table('users')
            ->where('login', 'root')->update(['password_hash' => \password_hash('planted', \PASSWORD_DEFAULT)]);

        $response = $this->browser()->post('/login', ['login' => 'root', 'password' => 'planted']);

        self::assertSame(422, $response->status());
        self::assertFalse($this->browser()->data('/api/v1/session')['authenticated']);
    }

    public function test_an_authd_lockout_is_shown_as_a_wait(): void
    {
        $this->app()->container()->instance(RootVerifier::class, new class implements RootVerifier {
            public function describe(): string
            {
                return 'locked';
            }

            public function verify(string $user, \App\Engine\Security\Secret $password, string $ip): RootVerdict
            {
                return RootVerdict::refused(RootVerdict::LOCKED, 840);
            }
        });

        $response = $this->browser()->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(429, $response->status());
        self::assertSame('840', $response->header('Retry-After'));
        self::assertStringContainsString('Try again in 14 minutes', $response->body());
    }

    public function test_an_unreachable_authd_refuses_and_says_nothing_more(): void
    {
        $this->app()->container()->instance(RootVerifier::class, new SocketRootVerifier(\sys_get_temp_dir() . '/no-such-authd.sock'));

        $response = $this->browser()->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(422, $response->status());
        self::assertSame(['reason' => 'unavailable'], $this->service(AuditLog::class)->latest()[0]['metadata']);
    }

    public function test_login_without_its_csrf_token_is_refused(): void
    {
        $response = $this->browser()->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD], withToken: false);

        self::assertSame(403, $response->status());
    }

    public function test_the_dev_verifier_refuses_to_boot_in_production(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/app\.env is "production"/');

        $this->shippedApplication([
            'app' => ['env' => 'production'],
            'security' => ['key' => Signer::generate()],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
            'Identity' => ['root' => ['verifier' => 'dev', 'dev_password_hash' => 'x']],
        ])->boot();
    }
}
