<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\Cookie;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Response;
use App\Engine\Http\StreamResponse;
use App\Tests\Support\TestCase;

final class ResponseTest extends TestCase
{
    private string $streamed = '';

    public function test_the_defaults_are_an_empty_two_hundred(): void
    {
        $response = new Response();

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
        self::assertSame([], $response->headers());
    }

    // ---- immutability -----------------------------------------------------
    //
    // Every module on the response filter receives the same object. Immutability
    // is what makes "who changed this header?" answerable.

    public function test_with_status_returns_a_clone(): void
    {
        $original = new Response('body', 200);
        $changed = $original->withStatus(404);

        self::assertNotSame($original, $changed);
        self::assertSame(200, $original->status());
        self::assertSame(404, $changed->status());
    }

    public function test_with_header_returns_a_clone(): void
    {
        $original = new Response();
        $changed = $original->withHeader('X-Engine', 'app');

        self::assertNull($original->header('X-Engine'));
        self::assertSame('app', $changed->header('X-Engine'));
    }

    public function test_with_body_returns_a_clone(): void
    {
        $original = new Response('first');

        self::assertSame('first', $original->body());
        self::assertSame('second', $original->withBody('second')->body());
        self::assertSame('first', $original->body());
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Response())->withStatus(99);
    }

    // ---- headers ----------------------------------------------------------

    public function test_headers_are_matched_case_insensitively(): void
    {
        $response = (new Response())->withHeader('content-type', 'text/plain');

        self::assertSame('text/plain', $response->header('Content-Type'));
        self::assertSame('text/plain', $response->header('CONTENT-TYPE'));
        self::assertTrue($response->hasHeader('Content-Type'));
    }

    public function test_headers_are_rendered_in_canonical_form(): void
    {
        $response = (new Response())->withHeader('x-request-id', 'abc');

        self::assertSame(['X-Request-Id' => 'abc'], $response->headers());
    }

    /**
     * A newline in a header value is response splitting. There is exactly one
     * place this is stopped, and this is the test that keeps it there.
     */
    public function test_carriage_returns_and_newlines_are_stripped_from_header_values(): void
    {
        $response = (new Response())->withHeader('X-Evil', "value\r\nSet-Cookie: admin=1");

        self::assertSame('valueSet-Cookie: admin=1', $response->header('X-Evil'));
        self::assertStringNotContainsString("\r", (string) $response->header('X-Evil'));
        self::assertStringNotContainsString("\n", (string) $response->header('X-Evil'));
    }

    public function test_constructor_headers_are_also_sanitised(): void
    {
        $response = new Response('', 200, ['X-Evil' => "a\nb"]);

        self::assertSame('ab', $response->header('X-Evil'));
    }

    public function test_with_added_header_joins_values(): void
    {
        $response = (new Response())
            ->withHeader('Vary', 'Accept')
            ->withAddedHeader('Vary', 'Accept-Encoding');

        self::assertSame('Accept, Accept-Encoding', $response->header('Vary'));
    }

    public function test_with_added_header_on_an_absent_header_just_sets_it(): void
    {
        self::assertSame('Accept', (new Response())->withAddedHeader('Vary', 'Accept')->header('Vary'));
    }

    public function test_without_header_removes_it(): void
    {
        $response = (new Response())->withHeader('X-Engine', 'app')->withoutHeader('x-engine');

        self::assertFalse($response->hasHeader('X-Engine'));
    }

    public function test_with_content_type_appends_a_charset(): void
    {
        self::assertSame(
            'text/html; charset=UTF-8',
            (new Response())->withContentType('text/html')->contentType(),
        );

        self::assertSame(
            'application/octet-stream',
            (new Response())->withContentType('application/octet-stream', '')->contentType(),
        );
    }

    // ---- JSON -------------------------------------------------------------

    public function test_json_response_encodes_and_sets_its_content_type(): void
    {
        $response = new JsonResponse(['name' => 'Ada']);

        self::assertSame('{"name":"Ada"}', $response->body());
        self::assertSame('application/json; charset=UTF-8', $response->contentType());
        self::assertSame(['name' => 'Ada'], $response->data());
    }

    public function test_json_response_does_not_escape_slashes_or_unicode(): void
    {
        $response = new JsonResponse(['path' => '/customers', 'name' => 'Ada Lovelace ✓']);

        self::assertStringContainsString('"/customers"', $response->body());
        self::assertStringContainsString('✓', $response->body());
    }

    public function test_json_response_accepts_a_status_and_headers(): void
    {
        $response = new JsonResponse(['id' => 1], 201, ['Location' => '/customers/1']);

        self::assertSame(201, $response->status());
        self::assertSame('/customers/1', $response->header('Location'));
    }

    public function test_json_response_respects_an_explicit_content_type(): void
    {
        $response = new JsonResponse(['a' => 1], 200, ['Content-Type' => 'application/vnd.api+json']);

        self::assertSame('application/vnd.api+json', $response->contentType());
    }

    public function test_json_response_rejects_unencodable_data(): void
    {
        $this->expectException(\JsonException::class);

        new JsonResponse(\fopen('php://memory', 'r'));
    }

    // ---- redirect ---------------------------------------------------------

    public function test_redirect_sets_location_and_defaults_to_302(): void
    {
        $response = new RedirectResponse('/customers');

        self::assertSame(302, $response->status());
        self::assertSame('/customers', $response->header('Location'));
        self::assertSame('/customers', $response->location());
    }

    public function test_redirect_accepts_a_permanent_status(): void
    {
        self::assertSame(301, (new RedirectResponse('/moved', 301))->status());
    }

    public function test_redirect_rejects_a_non_redirect_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RedirectResponse('/customers', 200);
    }

    public function test_redirect_sanitises_the_location(): void
    {
        $response = new RedirectResponse("/customers\r\nX-Injected: 1");

        self::assertSame('/customersX-Injected: 1', $response->location());
    }

    // ---- streaming --------------------------------------------------------

    /**
     * A streamed response really does flush, which means a plain ob_start()
     * cannot hold it: the chunks go straight past the buffer. Capturing through
     * an output-buffer callback both works and proves the flushing happens.
     */
    private function capture(\Closure $run): string
    {
        $this->streamed = '';

        \ob_start(function (string $buffer): string {
            $this->streamed .= $buffer;

            return '';
        });

        try {
            $run();
        } finally {
            \ob_end_flush();
        }

        return $this->streamed;
    }

    public function test_a_closure_stream_writes_while_sending(): void
    {
        $response = new StreamResponse(static function (): void {
            echo 'chunk-1';
            echo 'chunk-2';
        });

        self::assertSame('chunk-1chunk-2', $this->capture(static fn() => $response->send()));
    }

    public function test_an_iterable_stream_writes_each_chunk(): void
    {
        $response = new StreamResponse((static function (): \Generator {
            yield 'a';
            yield 'b';
            yield 'c';
        })());

        self::assertSame('abc', $this->capture(static fn() => $response->send()));
    }

    public function test_a_stream_has_no_buffered_body(): void
    {
        self::assertSame('', (new StreamResponse(static function (): void {}))->body());
    }

    // ---- sending ----------------------------------------------------------

    public function test_send_marks_the_response_as_sent(): void
    {
        $response = new Response('body');

        self::assertFalse($response->isSent());

        \ob_start();
        $response->send();
        \ob_end_clean();

        self::assertTrue($response->isSent());
    }

    public function test_sending_twice_is_a_no_op(): void
    {
        $response = new Response('body');

        \ob_start();
        $response->send();
        $response->send();
        $output = (string) \ob_get_clean();

        self::assertSame('body', $output);
    }

    public function test_send_can_omit_the_body_for_head_requests(): void
    {
        \ob_start();
        (new Response('body'))->send(omitBody: true);

        self::assertSame('', (string) \ob_get_clean());
    }

    // ---- cookies ----------------------------------------------------------

    public function test_a_cookie_serialises_with_safe_defaults(): void
    {
        $value = (new Cookie('session', 'abc'))->toHeaderValue();

        self::assertStringContainsString('session=abc', $value);
        self::assertStringContainsString('Path=/', $value);
        self::assertStringContainsString('HttpOnly', $value);
        self::assertStringContainsString('SameSite=Lax', $value);
        self::assertStringNotContainsString('Secure', $value);
    }

    public function test_a_cookie_value_is_url_encoded(): void
    {
        self::assertStringContainsString('k=a%20b', (new Cookie('k', 'a b'))->toHeaderValue());
    }

    public function test_a_secure_cookie_says_so(): void
    {
        $value = (new Cookie('s', 'v', 0, '/', 'example.test', true, false, 'Strict'))->toHeaderValue();

        self::assertStringContainsString('Domain=example.test', $value);
        self::assertStringContainsString('Secure', $value);
        self::assertStringNotContainsString('HttpOnly', $value);
        self::assertStringContainsString('SameSite=Strict', $value);
    }

    public function test_an_expiring_cookie_carries_both_expires_and_max_age(): void
    {
        $value = (new Cookie('s', 'v', \time() + 3600))->toHeaderValue();

        self::assertStringContainsString('Expires=', $value);
        self::assertStringContainsString('Max-Age=', $value);
    }

    public function test_forget_produces_an_already_expired_cookie(): void
    {
        $value = Cookie::forget('session')->toHeaderValue();

        self::assertStringContainsString('session=', $value);
        self::assertStringContainsString('Max-Age=0', $value);
    }

    public function test_same_site_none_requires_secure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cookie('s', 'v', 0, '/', '', false, true, 'None');
    }

    public function test_an_invalid_cookie_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Cookie('bad name');
    }

    public function test_cookies_are_attached_immutably(): void
    {
        $original = new Response();
        $withCookie = $original->withCookie(new Cookie('session', 'abc'));

        self::assertSame([], $original->cookies());
        self::assertCount(1, $withCookie->cookies());
    }

    // ---- status text ------------------------------------------------------

    public function test_status_text_covers_the_codes_the_kernel_produces(): void
    {
        self::assertSame('OK', Response::statusText(200));
        self::assertSame('No Content', Response::statusText(204));
        self::assertSame('Bad Request', Response::statusText(400));
        self::assertSame('Not Found', Response::statusText(404));
        self::assertSame('Method Not Allowed', Response::statusText(405));
        self::assertSame('Internal Server Error', Response::statusText(500));
        self::assertSame('Unknown Status', Response::statusText(599));
    }
}
