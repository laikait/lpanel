<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Core\Maintenance;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Counters\MemoryStore;
use App\Engine\Security\RateLimiter;
use App\Tests\Support\TestCase;

/**
 * php laika down and up, as the web sees them. The state file lives in a
 * temporary directory, never in the project's own system/Runtime.
 */
final class MaintenanceTest extends TestCase
{
    private string $directory = '';

    private Maintenance $maintenance;

    private Application $app;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-down-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        $this->maintenance = new Maintenance($this->directory);
        $this->app = $this->shippedApplication()->boot();
        $this->app->container()->instance(Maintenance::class, $this->maintenance);
    }

    protected function tearDown(): void
    {
        $this->maintenance->up();
        @\rmdir($this->directory . '/system/Runtime');
        @\rmdir($this->directory . '/system');
        @\rmdir($this->directory);

        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function get(string $uri, array $options = []): Response
    {
        return $this->app->handle(Request::create('GET', $uri, $options + ['server' => ['REMOTE_ADDR' => '203.0.113.9']]));
    }

    public function test_while_up_nothing_changes(): void
    {
        self::assertSame(200, $this->get('/')->status());
    }

    public function test_down_answers_503_with_retry_after_and_the_page(): void
    {
        $this->maintenance->down(120, 'Back at 14:00 UTC');

        $response = $this->get('/');

        self::assertSame(503, $response->status());
        self::assertSame('120', $response->header('Retry-After'));
        self::assertStringContainsString('Down for maintenance', $response->body());
        self::assertStringContainsString('Back at 14:00 UTC', $response->body());
    }

    public function test_a_page_that_does_not_exist_is_503_too(): void
    {
        $this->maintenance->down();

        self::assertSame(503, $this->get('/no-such-page')->status());
    }

    /** The 503 page keeps its stylesheet. */
    public function test_assets_are_still_served(): void
    {
        $this->maintenance->down();

        self::assertSame(200, $this->get('/assets/core/css/app.css')->status());
    }

    public function test_an_allowed_address_gets_in(): void
    {
        $this->maintenance->down(allow: ['198.51.100.0/24']);

        self::assertSame(200, $this->get('/', ['server' => ['REMOTE_ADDR' => '198.51.100.7']])->status());
        self::assertSame(503, $this->get('/', ['server' => ['REMOTE_ADDR' => '198.51.101.7']])->status());
    }

    /** Through a trusted proxy the forwarded address is the one checked -- and only then. */
    public function test_the_allowed_address_is_the_client_behind_a_trusted_proxy(): void
    {
        $this->maintenance->down(allow: ['198.51.100.7']);

        $forwarded = ['headers' => ['X-Forwarded-For' => '198.51.100.7'], 'trustedProxies' => ['10.0.0.1']];

        self::assertSame(200, $this->get('/', ['server' => ['REMOTE_ADDR' => '10.0.0.1']] + $forwarded)->status());
        self::assertSame(503, $this->get('/', ['server' => ['REMOTE_ADDR' => '10.0.0.2']] + $forwarded)->status(), 'an untrusted peer cannot claim an allowed address');
    }

    public function test_the_bypass_link_sets_a_cookie_that_lets_one_browser_in(): void
    {
        $this->maintenance->down(secret: 'preview-2026-long-enough');

        $redirect = $this->get('/?lphp_bypass=preview-2026-long-enough&page=2');
        self::assertSame(302, $redirect->status());
        self::assertSame('/?page=2', $redirect->header('Location'), 'the secret is dropped from the URL');

        $cookie = $redirect->cookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame(Maintenance::BYPASS_COOKIE, $cookie->name);
        self::assertTrue($cookie->httpOnly);
        self::assertStringNotContainsString('preview-2026-long-enough', $cookie->value);

        self::assertSame(200, $this->get('/', ['cookies' => [Maintenance::BYPASS_COOKIE => $cookie->value]])->status());
        self::assertSame(503, $this->get('/', ['cookies' => [Maintenance::BYPASS_COOKIE => 'forged']])->status());
        self::assertSame(503, $this->get('/?lphp_bypass=wrong')->status());
    }

    /** A cookie from one maintenance window does not open the next. */
    public function test_a_bypass_cookie_ends_with_its_window(): void
    {
        $this->maintenance->down(secret: 'preview-window-secret');
        $cookie = $this->get('/?lphp_bypass=preview-window-secret')->cookies()[0]->value;
        $this->maintenance->up();

        // A new window, a second later: the same secret, a different start.
        \sleep(1);
        $this->maintenance->down(secret: 'preview-window-secret');

        self::assertSame(503, $this->get('/', ['cookies' => [Maintenance::BYPASS_COOKIE => $cookie]])->status());
    }

    /** The secret is a password the whole internet can try against the 503 page. */
    public function test_a_short_bypass_secret_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 16 characters');

        $this->maintenance->down(secret: 'preview');
    }

    /**
     * Maintenance answers before routing, so no route limit applies to the
     * bypass parameter. Ten wrong guesses, and then even the right secret is
     * a 503 -- the page never confirms a guess.
     */
    public function test_guessing_the_bypass_secret_is_limited_per_address(): void
    {
        $maintenance = new Maintenance($this->directory, new RateLimiter(new MemoryStore()));
        $this->app->container()->instance(Maintenance::class, $maintenance);
        $maintenance->down(secret: 'preview-2026-long-enough');

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(503, $this->get('/?lphp_bypass=guess-' . $i)->status());
        }

        self::assertSame(503, $this->get('/?lphp_bypass=preview-2026-long-enough')->status(), 'the right one, after ten wrong');
        self::assertSame(302, $this->get('/?lphp_bypass=preview-2026-long-enough', ['server' => ['REMOTE_ADDR' => '198.51.100.4']])->status(), 'another address');
    }

    /**
     * "/\evil.example" and "//evil.example" are links to another host to a
     * browser. A bypass link on such a path redirected there; it now
     * redirects to the path on this site.
     */
    public function test_the_bypass_redirect_never_leaves_the_site(): void
    {
        $this->maintenance->down(secret: 'preview-2026-long-enough');

        foreach (['/%5Cevil.example', '/%5C%5Cevil.example', '//evil.example', '/%2F%2Fevil.example'] as $path) {
            $location = (string) $this->get($path . '?lphp_bypass=preview-2026-long-enough')->header('Location');

            self::assertMatchesRegularExpression('#^/[^/\\\\]#', $location, $path . ' redirected to ' . $location);
        }
    }

    public function test_up_brings_it_back(): void
    {
        $this->maintenance->down();
        self::assertTrue($this->maintenance->up());
        self::assertFalse($this->maintenance->up(), 'already up');

        self::assertSame(200, $this->get('/')->status());
    }

    public function test_an_unreadable_state_file_still_means_down(): void
    {
        \mkdir($this->directory . '/system/Runtime', 0o755, true);
        \file_put_contents($this->directory . '/' . Maintenance::FILE, 'not json');

        self::assertSame(503, $this->get('/')->status());
    }

    public function test_an_invalid_allowed_address_is_refused_when_going_down(): void
    {
        $this->expectException(\App\Engine\Network\IpException::class);
        $this->maintenance->down(allow: ['10.0.0.300']);
    }
}
