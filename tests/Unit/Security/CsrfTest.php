<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Http\Request;
use App\Engine\Security\Csrf;
use App\Engine\Security\Signer;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The check, and the four different things a failure can mean.
 *
 * check() returns a reason rather than a bool, and most of this file is about
 * that decision. "CSRF token mismatch" is one of the least useful errors a
 * framework produces: the form is missing a field, cookies are being dropped,
 * the page is older than the cookie, or the request really is cross-site --
 * four completely different fixes behind one message.
 */
final class CsrfTest extends TestCase
{
    private Csrf $csrf;

    private Signer $signer;

    protected function setUp(): void
    {
        $this->signer = Signer::fromEnvironment(Signer::generate());
        $this->csrf = new Csrf($this->signer);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function post(array $options = []): Request
    {
        return Request::create('POST', '/customers', $options);
    }

    // ---- which methods ---------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function methods(): array
    {
        return [
            'GET' => ['GET', false],
            'HEAD' => ['HEAD', false],
            'OPTIONS' => ['OPTIONS', false],
            'TRACE' => ['TRACE', false],
            'POST' => ['POST', true],
            'PUT' => ['PUT', true],
            'PATCH' => ['PATCH', true],
            'DELETE' => ['DELETE', true],
        ];
    }

    #[DataProvider('methods')]
    public function test_only_methods_that_change_something_are_checked(string $method, bool $protected): void
    {
        self::assertSame($protected, $this->csrf->protects($method));
        self::assertSame($protected, $this->csrf->check(Request::create($method, '/x')) !== null);
    }

    // ---- the happy path --------------------------------------------------------

    public function test_a_matching_cookie_and_field_pass(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));

        self::assertNull($this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ])));
    }

    // ---- the cookie's name ----------------------------------------------------

    /**
     * A token is valid for whoever fetched it, so a sibling subdomain or an
     * HTTP man-in-the-middle could plant its own valid one as XSRF-TOKEN and post
     * the matching field. Over HTTPS only __Host-XSRF-TOKEN is read, and that
     * name is one neither of them can set.
     */
    public function test_over_https_a_planted_plain_cookie_counts_for_nothing(): void
    {
        $https = ['HTTPS' => 'on'];
        $planted = $this->csrf->token(Request::create('GET', '/'));
        $this->csrf->begin();

        $reason = $this->csrf->check($this->post([
            'server' => $https,
            'cookies' => [Csrf::COOKIE => $planted],
            'body' => [Csrf::FIELD => $planted],
        ]));

        self::assertNotNull($reason);
        self::assertStringContainsString(Csrf::SECURE_COOKIE, $reason);

        $token = $this->csrf->token(Request::create('GET', '/', ['server' => $https]));

        self::assertSame(Csrf::SECURE_COOKIE, $this->csrf->cookie($token, true)->name);
        self::assertNull($this->csrf->check($this->post([
            'server' => $https,
            'cookies' => [Csrf::SECURE_COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ])));
    }

    public function test_plain_http_and_the_switch_keep_the_old_name(): void
    {
        self::assertSame(Csrf::COOKIE, $this->csrf->cookie('abc', false)->name);
        self::assertSame(Csrf::COOKIE, $this->csrf->cookieName(Request::create('GET', '/')));

        $unprefixed = new Csrf($this->signer, hostPrefix: false);

        self::assertSame(Csrf::COOKIE, $unprefixed->cookie('abc', true)->name);
        self::assertSame(Csrf::COOKIE, $unprefixed->cookieName(Request::create('GET', '/', ['server' => ['HTTPS' => 'on']])));
    }

    // ---- one token per request ------------------------------------------------

    /**
     * The form and the cookie ask separately. With no cookie to reuse, both
     * must be handed the same new token, or the form cannot be submitted.
     */
    public function test_a_new_token_is_issued_once_per_request(): void
    {
        $this->csrf->begin();
        $firstVisit = Request::create('GET', '/');

        self::assertSame($this->csrf->token($firstVisit), $this->csrf->token($firstVisit));
    }

    /** One browser's new token is never handed to the next. */
    public function test_each_request_is_issued_its_own(): void
    {
        $this->csrf->begin();
        $first = $this->csrf->token(Request::create('GET', '/'));

        $this->csrf->begin();
        $second = $this->csrf->token(Request::create('GET', '/'));

        self::assertNotSame($first, $second);
    }

    /** After a login, a page rendered in the same response carries the token its cookie will. */
    public function test_a_rotated_token_is_what_the_rest_of_the_request_is_given(): void
    {
        $this->csrf->begin();
        $held = $this->csrf->token(Request::create('GET', '/'));
        $request = Request::create('GET', '/', ['cookies' => [Csrf::COOKIE => $held]]);

        $rotated = $this->csrf->rotate();

        self::assertNotSame($held, $rotated);
        self::assertSame($rotated, $this->csrf->token($request));
    }

    /** A script sends a header rather than a field, under either spelling. */
    #[DataProvider('tokenHeaders')]
    public function test_a_matching_cookie_and_header_pass(string $header): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));

        self::assertNull($this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'headers' => [$header => $token],
        ])));
    }

    /** @return array<string, array{string}> */
    public static function tokenHeaders(): array
    {
        return ['X-CSRF-TOKEN' => ['X-CSRF-TOKEN'], 'X-XSRF-TOKEN' => ['X-XSRF-TOKEN']];
    }

    // ---- the four failures -----------------------------------------------------

    public function test_no_cookie_says_the_cookie_is_missing(): void
    {
        $reason = $this->csrf->check($this->post(['body' => [Csrf::FIELD => 'anything']]));

        self::assertNotNull($reason);
        self::assertStringContainsString('no ' . Csrf::COOKIE . ' cookie', $reason);
    }

    public function test_no_submitted_token_says_which_field_or_header(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));
        $reason = $this->csrf->check($this->post(['cookies' => [Csrf::COOKIE => $token]]));

        self::assertNotNull($reason);
        self::assertStringContainsString(Csrf::FIELD, $reason);
        self::assertStringContainsString(Csrf::HEADERS[0], $reason);
    }

    /**
     * The check that a signature buys: a cookie this application did not issue
     * is refused even when the field agrees with it.
     */
    public function test_a_planted_cookie_and_field_are_refused(): void
    {
        $reason = $this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => 'attacker-chose-this'],
            'body' => [Csrf::FIELD => 'attacker-chose-this'],
        ]));

        self::assertNotNull($reason);
        self::assertStringContainsString('not signed by this application', $reason);
    }

    /** A form from one request, a cookie from a later one. */
    public function test_a_stale_token_says_the_page_is_older_than_the_cookie(): void
    {
        $this->csrf->begin();
        $page = $this->csrf->token(Request::create('GET', '/'));

        $this->csrf->begin();
        $cookie = $this->csrf->token(Request::create('GET', '/'));

        $reason = $this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $cookie],
            'body' => [Csrf::FIELD => $page],
        ]));

        self::assertNotNull($reason);
        self::assertStringContainsString('does not match the cookie', $reason);
    }

    // ---- origin ----------------------------------------------------------------

    /**
     * The second, independent check. A browser will not send another site's
     * Origin for a request your own page made, and script cannot set it.
     */
    public function test_a_cross_origin_request_is_refused_even_with_a_valid_token(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));

        $reason = $this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
            'headers' => ['Origin' => 'https://evil.test', 'Host' => 'app.test'],
        ]));

        self::assertNotNull($reason);
        self::assertStringContainsString('evil.test', $reason);
        self::assertStringContainsString('app.test', $reason);
    }

    public function test_a_same_origin_request_passes(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));

        self::assertNull($this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
            'headers' => ['Origin' => 'https://app.test', 'Host' => 'app.test'],
        ])));
    }

    /**
     * Absent means "cannot tell", not "suspicious". Proxies and privacy tools
     * strip it, and treating that as an attack produces false rejections.
     */
    public function test_a_missing_origin_falls_through_to_the_token(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));

        self::assertNull($this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ])));
    }

    public function test_origin_checking_can_be_turned_off(): void
    {
        $csrf = new Csrf($this->signer, checkOrigin: false);
        $token = $csrf->token(Request::create('GET', '/'));

        self::assertNull($csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
            'headers' => ['Origin' => 'https://evil.test', 'Host' => 'app.test'],
        ])));
    }

    // ---- the token -------------------------------------------------------------

    /**
     * Two tabs open on the same site is ordinary browsing, and rotating the
     * token on every response would show the second one a security error for
     * it.
     */
    public function test_an_existing_valid_token_is_reused_rather_than_rotated(): void
    {
        $token = $this->csrf->token(Request::create('GET', '/'));
        $again = $this->csrf->token(Request::create('GET', '/', ['cookies' => [Csrf::COOKIE => $token]]));

        self::assertSame($token, $again);
    }

    public function test_an_unsigned_cookie_is_replaced_rather_than_reused(): void
    {
        $fresh = $this->csrf->token(Request::create('GET', '/', ['cookies' => [Csrf::COOKIE => 'planted']]));

        self::assertNotSame('planted', $fresh);
    }

    public function test_the_cookie_is_readable_by_script_and_not_sent_cross_site(): void
    {
        $cookie = $this->csrf->cookie('abc', secure: true);

        self::assertSame(Csrf::SECURE_COOKIE, $cookie->name);
        // The one cookie in the framework that is not HttpOnly: it has to be
        // readable to be echoed, and the credential it protects stays HttpOnly.
        self::assertFalse($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
        self::assertTrue($cookie->secure);
    }

    // ---- without a key ---------------------------------------------------------

    /**
     * An application with no APP_KEY still refuses cross-site requests -- a
     * cookie another site cannot read is still a cookie another site cannot
     * echo. What is lost is the signature, and the framework says which mode
     * it is in rather than implying the stronger one.
     */
    public function test_without_a_key_double_submit_still_works(): void
    {
        $csrf = new Csrf(new Signer());

        self::assertFalse($csrf->isSigned());

        $token = $csrf->token(Request::create('GET', '/'));

        self::assertNull($csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => $token],
        ])));

        $reason = $csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => $token],
            'body' => [Csrf::FIELD => 'something else'],
        ]));

        self::assertNotNull($reason);
    }

    public function test_a_key_makes_the_difference_visible(): void
    {
        self::assertTrue($this->csrf->isSigned());
        self::assertFalse((new Csrf(new Signer()))->isSigned());
    }

    /**
     * The exact weakness of the unkeyed mode, written down so that nobody
     * later mistakes it for something stronger.
     *
     * Without APP_KEY there is nothing to verify, so a cookie and field the
     * attacker chose agree with each other and the check passes. That is not a
     * hole in double-submit -- it is what double-submit alone can do, and it
     * still stops the ordinary attack, because a site at evil.test cannot set a
     * cookie for app.test or read the one already there.
     *
     * Who it does not stop: a sibling subdomain, and anyone able to inject a
     * Set-Cookie over plain HTTP. Both are exactly what the signature closes,
     * which is why security:check reports the unkeyed mode as a warning rather
     * than leaving it to be discovered.
     */
    public function test_without_a_key_a_forged_pair_is_accepted(): void
    {
        $unkeyed = new Csrf(new Signer());

        self::assertNull($unkeyed->check($this->post([
            'cookies' => [Csrf::COOKIE => 'attacker-chose-this'],
            'body' => [Csrf::FIELD => 'attacker-chose-this'],
        ])));

        // And with a key, the same request is refused. This is the one line of
        // difference APP_KEY buys.
        self::assertNotNull($this->csrf->check($this->post([
            'cookies' => [Csrf::COOKIE => 'attacker-chose-this'],
            'body' => [Csrf::FIELD => 'attacker-chose-this'],
        ])));
    }
}
