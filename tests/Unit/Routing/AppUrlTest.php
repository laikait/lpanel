<?php

declare(strict_types=1);

namespace App\Tests\Unit\Routing;

use App\Engine\Http\Response;
use App\Engine\Routing\AppUrl;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Routing\RoutingException;
use App\Tests\Support\TestCase;

final class AppUrlTest extends TestCase
{
    private function router(string $basePath = ''): Router
    {
        $router = new Router($basePath);
        $router->add((new Route('GET', '/account/reset', static fn(): Response => new Response('')))->name('reset'));
        $router->add((new Route('GET', '/', static fn(): Response => new Response('')))->name('home'));

        return $router;
    }

    public function test_a_route_under_the_configured_address(): void
    {
        self::assertSame('https://example.com/account/reset?token=a', (new AppUrl('https://example.com/', $this->router()))->route('reset', ['token' => 'a']));
        self::assertSame('https://example.com/', (new AppUrl('https://example.com', $this->router()))->route('home'));
    }

    /** The router's base path is the request's; APP_URL already says where the application lives. */
    public function test_the_base_path_is_not_doubled(): void
    {
        $urls = new AppUrl('https://example.com/shop', $this->router('/shop'));

        self::assertSame('https://example.com/shop/account/reset', $urls->route('reset'));
        self::assertSame('https://example.com/shop/', $urls->route('home'));
    }

    public function test_the_host_header_is_never_a_fallback(): void
    {
        $urls = new AppUrl(null, $this->router());
        self::assertFalse($urls->isConfigured());

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('never taken from the request\'s Host header');

        $urls->route('reset');
    }

    public function test_a_malformed_address_is_refused(): void
    {
        foreach (['example.com', 'ftp://example.com', 'https://example.com/?a=1', 'https://exa mple.com'] as $bad) {
            try {
                new AppUrl($bad, $this->router());
                self::fail($bad . ' was accepted.');
            } catch (RoutingException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
