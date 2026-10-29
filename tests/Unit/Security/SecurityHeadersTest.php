<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\SecurityHeaders;
use App\Tests\Support\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_the_four_defaults_are_added(): void
    {
        $response = (new SecurityHeaders())(new Response('hello'));

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('SAMEORIGIN', $response->header('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        self::assertSame('same-origin', $response->header('Cross-Origin-Opener-Policy'));
    }

    /**
     * A handler that set its own policy has thought about it harder than a
     * default can. Stamping over it would make the careful case
     * indistinguishable from the careless one -- and it is how the asset
     * server's deliberately strict CSP would get loosened.
     */
    public function test_a_header_already_set_is_left_alone(): void
    {
        $response = (new SecurityHeaders())(
            (new Response('hello'))->withHeader('X-Frame-Options', 'DENY'),
        );

        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function test_a_policy_is_sent_when_one_is_configured(): void
    {
        $response = (new SecurityHeaders(csp: "default-src 'self'"))(new Response('hello'));

        self::assertSame("default-src 'self'", $response->header('Content-Security-Policy'));
    }

    /**
     * Off by default and deliberately so: a generic policy is either too loose
     * to be a policy or too strict to survive the first page with an inline
     * handler, and the one that gets switched off in a hurry is worse than the
     * one that was never claimed.
     */
    public function test_there_is_no_policy_unless_one_is_configured(): void
    {
        self::assertNull((new SecurityHeaders())(new Response('hello'))->header('Content-Security-Policy'));
    }

    // ---- HSTS ------------------------------------------------------------------

    /**
     * The one header here that cannot be taken back: a browser that has seen it
     * refuses plain HTTP for the whole max-age. Sending it from a development
     * machine breaks every other project on that hostname.
     */
    public function test_hsts_is_never_sent_over_plain_http(): void
    {
        $response = (new SecurityHeaders(hstsDays: 365))(
            new Response('hello'),
            Request::create('GET', '/', ['server' => ['HTTPS' => 'off']]),
        );

        self::assertNull($response->header('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_over_https_when_asked_for(): void
    {
        $response = (new SecurityHeaders(hstsDays: 365))(
            new Response('hello'),
            Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]),
        );

        self::assertSame('max-age=31536000', $response->header('Strict-Transport-Security'));
    }

    public function test_hsts_can_cover_subdomains(): void
    {
        $response = (new SecurityHeaders(hstsDays: 1, hstsSubdomains: true))(
            new Response('hello'),
            Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]),
        );

        self::assertSame('max-age=86400; includeSubDomains', $response->header('Strict-Transport-Security'));
    }

    public function test_hsts_is_off_by_default(): void
    {
        $response = (new SecurityHeaders())(
            new Response('hello'),
            Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]),
        );

        self::assertNull($response->header('Strict-Transport-Security'));
    }

    // ---- overrides -------------------------------------------------------------

    public function test_a_default_can_be_replaced(): void
    {
        $response = (new SecurityHeaders(['X-Frame-Options' => 'DENY']))(new Response('hello'));

        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function test_a_header_can_be_added(): void
    {
        $response = (new SecurityHeaders(['Permissions-Policy' => 'geolocation=()']))(new Response('hello'));

        self::assertSame('geolocation=()', $response->header('Permissions-Policy'));
    }

    /**
     * An empty value removes a default rather than sending an empty header,
     * so that configuration does not need a second shape meaning "no".
     */
    public function test_an_empty_value_removes_a_default(): void
    {
        $response = (new SecurityHeaders(['X-Frame-Options' => '']))(new Response('hello'));

        self::assertNull($response->header('X-Frame-Options'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    /** What security:check prints, so the audit and the response cannot disagree. */
    public function test_it_can_describe_what_it_will_add(): void
    {
        $described = (new SecurityHeaders(csp: "default-src 'self'", hstsDays: 30))->describe();

        self::assertArrayHasKey('X-Content-Type-Options', $described);
        self::assertArrayHasKey('Content-Security-Policy', $described);
        self::assertArrayHasKey('Strict-Transport-Security', $described);
    }

    public function test_a_response_with_no_request_still_gets_the_defaults(): void
    {
        self::assertSame(
            'nosniff',
            (new SecurityHeaders())(new Response('hello'))->header('X-Content-Type-Options'),
        );
    }
}
