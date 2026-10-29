<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Route;
use App\Engine\Routing\Router;
use App\Engine\Routing\UrlSigner;
use App\Engine\Security\SecurityException;
use App\Engine\Security\SignedUrl;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;

final class SignedUrlTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = $this->shippedApplication(['security' => ['key' => Signer::generate()]])->boot();
        $router = $this->app->container()->get(Router::class);
        $router->add((new Route('GET', '/unsubscribe/{id}', static fn(string $id): Response => new Response('unsubscribed ' . $id)))
            ->name('unsubscribe')
            ->meta(['signed' => true]));
    }

    private function signer(): UrlSigner
    {
        return $this->app->container()->get(UrlSigner::class);
    }

    private function get(string $url): Response
    {
        return $this->app->handle(Request::create('GET', $url, ['server' => ['REMOTE_ADDR' => '203.0.113.9']]));
    }

    public function test_a_signed_link_gets_in(): void
    {
        $url = $this->signer()->signed('unsubscribe', ['id' => 7, 'list' => 'news'], '+1 day');

        self::assertMatchesRegularExpression('#^/unsubscribe/7\?list=news&expires=\d+&signature=[A-Za-z0-9_-]+$#', $url);

        $response = $this->get($url);
        self::assertSame(200, $response->status(), $response->body());
        self::assertSame('unsubscribed 7', $response->body());
    }

    /** The browser may reorder the query; the signature does not depend on the order. */
    public function test_the_query_order_does_not_matter(): void
    {
        $url = $this->signer()->signed('unsubscribe', ['id' => 7, 'b' => '2', 'a' => '1']);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);
        \krsort($query);

        self::assertSame(200, $this->get('/unsubscribe/7?' . \http_build_query($query))->status());
    }

    public function test_a_link_without_a_signature_is_refused(): void
    {
        self::assertSame(403, $this->get('/unsubscribe/7')->status());
    }

    public function test_changing_anything_signed_is_refused(): void
    {
        $url = $this->signer()->signed('unsubscribe', ['id' => 7, 'list' => 'news'], '+1 day');

        self::assertSame(403, $this->get(\str_replace('/unsubscribe/7', '/unsubscribe/8', $url))->status(), 'the path');
        self::assertSame(403, $this->get(\str_replace('list=news', 'list=all', $url))->status(), 'a parameter');
        self::assertSame(403, $this->get((string) \preg_replace('/expires=\d+/', 'expires=9999999999', $url))->status(), 'the expiry');
        self::assertSame(403, $this->get($url . '&extra=1')->status(), 'an added parameter');
    }

    public function test_an_expired_link_is_gone(): void
    {
        $url = $this->app->container()->get(SignedUrl::class)->sign('/unsubscribe/7', \time() - 1);

        self::assertSame(410, $this->get($url)->status());
    }

    /** Signed with another purpose's key material, it does not pass as a link. */
    public function test_a_signature_from_another_key_is_refused(): void
    {
        $other = (new SignedUrl(Signer::fromEnvironment(Signer::generate())))->sign('/unsubscribe/7');

        self::assertSame(403, $this->get($other)->status());
    }

    public function test_without_app_key_nothing_is_signed(): void
    {
        $this->expectException(SecurityException::class);
        (new SignedUrl(new Signer()))->sign('/unsubscribe/7');
    }
}
