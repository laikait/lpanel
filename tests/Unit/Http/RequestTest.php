<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\Request;
use App\Engine\Http\UploadedFile;
use App\Tests\Support\TestCase;

final class RequestTest extends TestCase
{
    // ---- base path derivation --------------------------------------------
    //
    // This is the most environment-specific logic in the framework: the same
    // application has to work at http://localhost/framework/ under Apache and
    // at http://127.0.0.1:8080/ under the built-in server.

    public function test_apache_in_a_subdirectory_with_rewriting(): void
    {
        $request = Request::create('GET', '/framework/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_apache_in_a_subdirectory_without_rewriting(): void
    {
        $request = Request::create('GET', '/framework/index.php/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework/index.php', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    /** Decoded and normalised, so a handler sees one spelling of the same text. */
    public function test_a_path_in_another_language_is_decoded_to_nfc(): void
    {
        $request = Request::create('GET', '/framework/' . \rawurlencode("cafe\u{301}") . '/' . \rawurlencode('ঢাকা'), [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame("/caf\u{E9}/ঢাকা", $request->path());
    }

    /**
     * The project directory served by Apache, its .htaccess forwarding into
     * public/: the script is public/index.php, and the address is its parent.
     * A URL built from the base path must not grow a "/public".
     */
    public function test_a_request_forwarded_into_public_keeps_the_address_the_visitor_used(): void
    {
        $request = Request::create('GET', '/framework/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/public/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_the_forwarded_root_resolves_to_a_single_slash(): void
    {
        $request = Request::create('GET', '/framework/', [
            'server' => ['SCRIPT_NAME' => '/framework/public/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/', $request->path());
    }

    /** Addressing public/ directly still works; it is simply a longer base. */
    public function test_a_request_that_names_public_uses_it_as_the_base(): void
    {
        $request = Request::create('GET', '/framework/public/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/public/index.php'],
        ]);

        self::assertSame('/framework/public', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    /** A directory merely called public/ elsewhere in the URL is not forwarding. */
    public function test_public_at_the_domain_root_with_a_rewrite_has_no_base(): void
    {
        $request = Request::create('GET', '/customers', [
            'server' => ['SCRIPT_NAME' => '/public/index.php'],
        ]);

        self::assertSame('', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_the_builtin_server_has_no_base_path(): void
    {
        $request = Request::create('GET', '/customers', [
            'server' => ['SCRIPT_NAME' => '/server.php'],
        ]);

        self::assertSame('', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_the_subdirectory_root_resolves_to_a_single_slash(): void
    {
        $request = Request::create('GET', '/framework/', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/', $request->path());
    }

    public function test_the_subdirectory_root_without_a_trailing_slash(): void
    {
        $request = Request::create('GET', '/framework', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('/framework', $request->basePath());
        self::assertSame('/', $request->path());
    }

    public function test_an_explicit_base_path_overrides_derivation(): void
    {
        $request = Request::create('GET', '/proxied/customers', [
            'server' => ['SCRIPT_NAME' => '/index.php'],
            'basePath' => '/proxied',
        ]);

        self::assertSame('/proxied', $request->basePath());
        self::assertSame('/customers', $request->path());
    }

    public function test_a_sibling_directory_is_not_mistaken_for_the_base(): void
    {
        $request = Request::create('GET', '/frameworks/customers', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
        ]);

        self::assertSame('', $request->basePath());
        self::assertSame('/frameworks/customers', $request->path());
    }

    // ---- path normalisation ----------------------------------------------

    public function test_the_path_excludes_the_query_string(): void
    {
        $request = Request::create('GET', '/customers?page=2');

        self::assertSame('/customers', $request->path());
        self::assertSame('/customers?page=2', $request->uri());
    }

    public function test_the_path_is_percent_decoded(): void
    {
        self::assertSame('/customers/Ada Lovelace', Request::create('GET', '/customers/Ada%20Lovelace')->path());
    }

    public function test_a_trailing_slash_is_not_a_different_resource(): void
    {
        self::assertSame('/customers', Request::create('GET', '/customers/')->path());
    }

    // ---- query and body ---------------------------------------------------

    public function test_the_query_string_is_parsed_from_the_uri(): void
    {
        $request = Request::create('GET', '/customers?page=2&sort=name');

        self::assertSame('2', $request->query('page'));
        self::assertSame(['page' => '2', 'sort' => 'name'], $request->query());
        self::assertSame('fallback', $request->query('missing', 'fallback'));
    }

    public function test_an_explicit_query_array_wins_over_the_uri(): void
    {
        $request = Request::create('GET', '/customers?page=2', ['query' => ['page' => '9']]);

        self::assertSame('9', $request->query('page'));
    }

    public function test_a_json_body_is_decoded(): void
    {
        $request = Request::create('POST', '/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":"Ada","age":36}',
        ]);

        self::assertTrue($request->isJson());
        self::assertSame(['name' => 'Ada', 'age' => 36], $request->json());
        self::assertSame('Ada', $request->json('name'));
        self::assertSame('Ada', $request->input('name'));
    }

    public function test_a_malformed_json_body_yields_null_rather_than_a_fatal(): void
    {
        $request = Request::create('POST', '/customers', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{"name":',
        ]);

        self::assertNull($request->json());
        self::assertSame('default', $request->json('name', 'default'));
        self::assertSame([], $request->input());
    }

    public function test_a_form_body_is_available_through_input(): void
    {
        $request = Request::create('POST', '/customers', ['body' => ['name' => 'Ada']]);

        self::assertSame('Ada', $request->input('name'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertSame('name=Ada', $request->body());
    }

    public function test_query_parameters_are_not_merged_into_input(): void
    {
        $request = Request::create('POST', '/customers?source=web', ['body' => ['name' => 'Ada']]);

        self::assertSame('web', $request->query('source'));
        self::assertNull($request->input('source'));
    }

    // ---- headers and cookies ---------------------------------------------

    public function test_headers_are_read_case_insensitively(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['X-Custom-Header' => 'value']]);

        self::assertSame('value', $request->header('x-custom-header'));
        self::assertSame('value', $request->header('X-CUSTOM-HEADER'));
        self::assertTrue($request->hasHeader('X-Custom-Header'));
        self::assertSame(['X-Custom-Header' => 'value'], $request->headers());
    }

    public function test_headers_are_built_from_server_variables(): void
    {
        $request = Request::create('POST', '/', [
            'server' => [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '42',
            ],
        ]);

        // create() takes headers explicitly, so build them the way fromGlobals does.
        $fromServer = \App\Engine\Http\Headers::fromServer([
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
            'REQUEST_METHOD' => 'POST',
        ]);

        self::assertSame([
            'x-requested-with' => 'XMLHttpRequest',
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'content-length' => '42',
        ], $fromServer);

        self::assertSame('POST', $request->method());
    }

    /**
     * Apache receives Authorization and does not pass it on.
     *
     * The header is not in $_SERVER at all -- Apache treats it as its own
     * business unless CGIPassAuth is set or a rewrite copies it -- so a bearer
     * token the client definitely sent is invisible. The failure is silent and
     * environment-specific: token authentication passes every test, works under
     * php -S, and answers 401 to everything once deployed.
     *
     * This covers the rewrite path. The getallheaders() fallback cannot be
     * exercised from a unit test, because the function only exists under a web
     * SAPI and reports what the real server received.
     */
    public function test_an_authorization_header_survives_an_internal_rewrite(): void
    {
        $headers = \App\Engine\Http\Headers::fromServer([
            'REQUEST_METHOD' => 'GET',
            // What .htaccess puts there with E=HTTP_AUTHORIZATION.
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer a-token',
        ]);

        self::assertSame('Bearer a-token', $headers['authorization'] ?? null);
    }

    /** A header the server did pass through is never second-guessed. */
    public function test_a_real_authorization_header_is_left_alone(): void
    {
        $headers = \App\Engine\Http\Headers::fromServer([
            'REQUEST_METHOD' => 'GET',
            'HTTP_AUTHORIZATION' => 'Bearer the-real-one',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer a-stale-copy',
        ]);

        self::assertSame('Bearer the-real-one', $headers['authorization'] ?? null);
    }

    public function test_cookies_are_readable(): void
    {
        $request = Request::create('GET', '/', ['cookies' => ['session' => 'abc']]);

        self::assertSame('abc', $request->cookie('session'));
        self::assertNull($request->cookie('missing'));
        self::assertSame(['session' => 'abc'], $request->cookies());
    }

    // ---- uploads ----------------------------------------------------------

    public function test_a_single_upload_is_normalised(): void
    {
        $files = UploadedFile::normalizeAll([
            'avatar' => [
                'tmp_name' => '/tmp/php123',
                'name' => 'photo.png',
                'type' => 'image/png',
                'size' => 1024,
                'error' => \UPLOAD_ERR_OK,
            ],
        ]);

        $request = Request::create('POST', '/', ['files' => $files]);
        $avatar = $request->file('avatar');

        self::assertNotNull($avatar);
        self::assertSame('photo.png', $avatar->clientName());
        self::assertSame(1024, $avatar->size());
        self::assertTrue($avatar->isValid());
    }

    public function test_a_multi_upload_is_normalised_into_a_list(): void
    {
        $files = UploadedFile::normalizeAll([
            'documents' => [
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'size' => [10, 20],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_OK],
            ],
        ]);

        $request = Request::create('POST', '/', ['files' => $files]);

        self::assertIsArray($request->files()['documents']);
        self::assertCount(2, $request->files()['documents']);
        self::assertSame('a.pdf', $request->file('documents')?->clientName());
    }

    public function test_an_absent_upload_is_null(): void
    {
        self::assertNull(Request::create('POST', '/')->file('avatar'));
    }

    // ---- connection -------------------------------------------------------

    public function test_https_is_detected_from_the_server_variable(): void
    {
        $request = Request::create('GET', '/', ['server' => ['HTTPS' => 'on']]);

        self::assertTrue($request->isSecure());
        self::assertSame('https', $request->scheme());
    }

    public function test_https_off_is_not_secure(): void
    {
        self::assertFalse(Request::create('GET', '/', ['server' => ['HTTPS' => 'off']])->isSecure());
    }

    public function test_https_is_detected_from_port_443(): void
    {
        self::assertTrue(Request::create('GET', '/', ['server' => ['SERVER_PORT' => '443']])->isSecure());
    }

    public function test_host_and_port_come_from_the_host_header(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['Host' => 'example.test:8080']]);

        self::assertSame('example.test', $request->host());
        self::assertSame(8080, $request->port());
    }

    public function test_the_url_omits_a_standard_port(): void
    {
        $request = Request::create('GET', '/customers', ['headers' => ['Host' => 'example.test']]);

        self::assertSame('http://example.test/customers', $request->url());
    }

    /**
     * Trusting X-Forwarded-For unconditionally would let any client claim any
     * address, which silently breaks rate limiting and audit trails.
     */
    public function test_forwarded_for_is_ignored_without_a_trusted_proxy(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9'],
        ]);

        self::assertSame('10.0.0.5', $request->ip());
    }

    public function test_forwarded_for_is_honoured_from_a_trusted_proxy(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9, 10.0.0.5'],
            'trustedProxies' => ['10.0.0.5'],
        ]);

        self::assertSame('203.0.113.9', $request->ip());
    }

    public function test_a_trusted_proxy_can_be_a_cidr_block(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.20.30.40'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9'],
            'trustedProxies' => ['10.0.0.0/8'],
        ]);

        self::assertTrue($request->fromTrustedProxy());
        self::assertSame('203.0.113.9', $request->ip());
    }

    public function test_a_trusted_proxy_can_be_ipv6(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '2400:cb00::1'],
            'headers' => ['X-Forwarded-For' => '2001:db8::77'],
            'trustedProxies' => ['2400:cb00::/32'],
        ]);

        self::assertSame('2001:db8::77', $request->ip());
    }

    /**
     * The left end of the header is whatever the client sent. Reading from the
     * right, past the proxies this application trusts, finds the address the
     * first trusted proxy actually saw.
     */
    public function test_a_spoofed_forwarded_for_is_read_past(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'headers' => ['X-Forwarded-For' => '1.1.1.1, 198.51.100.20, 10.0.0.2'],
            'trustedProxies' => ['10.0.0.0/24'],
        ]);

        self::assertSame('198.51.100.20', $request->ip());
        self::assertSame(['1.1.1.1', '198.51.100.20', '10.0.0.2', '10.0.0.1'], $request->ips());
    }

    public function test_every_hop_trusted_gives_the_leftmost(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'headers' => ['X-Forwarded-For' => '10.0.0.3, 10.0.0.2'],
            'trustedProxies' => ['10.0.0.0/24'],
        ]);

        self::assertSame('10.0.0.3', $request->ip());
    }

    public function test_reading_stops_at_an_entry_that_is_not_an_address(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'headers' => ['X-Forwarded-For' => '198.51.100.20, <script>, 10.0.0.2'],
            'trustedProxies' => ['10.0.0.0/24'],
        ]);

        self::assertSame('10.0.0.2', $request->ip());
    }

    public function test_a_port_in_forwarded_for_is_dropped(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.1'],
            'headers' => ['X-Forwarded-For' => '[2001:db8::5]:4711, 198.51.100.20:51234'],
            'trustedProxies' => ['10.0.0.1', '198.51.100.20'],
        ]);

        self::assertSame('2001:db8::5', $request->ip());
    }

    public function test_an_unreadable_trusted_proxy_is_skipped(): void
    {
        $request = Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-For' => '203.0.113.9'],
            'trustedProxies' => ['not-an-address', '10.0.0.5'],
        ]);

        self::assertSame('203.0.113.9', $request->ip());
    }

    public function test_the_address_parsed(): void
    {
        $mapped = Request::create('GET', '/', ['server' => ['REMOTE_ADDR' => '::ffff:198.51.100.20']]);

        self::assertSame('198.51.100.20', $mapped->ipAddress()?->toString(), 'a dual-stack socket\'s IPv4');
        self::assertNull(Request::create('GET', '/', ['server' => []])->ipAddress());
        self::assertSame([], Request::create('GET', '/', ['server' => []])->ips());
        self::assertSame(
            ['10.0.0.5'],
            Request::create('GET', '/', [
                'server' => ['REMOTE_ADDR' => '10.0.0.5'],
                'headers' => ['X-Forwarded-For' => '203.0.113.9'],
            ])->ips(),
            'the header is not part of the chain from an untrusted peer',
        );
    }

    public function test_forwarded_proto_is_only_honoured_from_a_trusted_proxy(): void
    {
        $options = [
            'server' => ['REMOTE_ADDR' => '10.0.0.5'],
            'headers' => ['X-Forwarded-Proto' => 'https'],
        ];

        self::assertFalse(Request::create('GET', '/', $options)->isSecure());

        $options['trustedProxies'] = ['10.0.0.5'];
        self::assertTrue(Request::create('GET', '/', $options)->isSecure());
    }

    // ---- content negotiation ---------------------------------------------

    public function test_expects_json_for_an_ajax_request(): void
    {
        $request = Request::create('GET', '/', ['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

        self::assertTrue($request->isAjax());
        self::assertTrue($request->expectsJson());
    }

    public function test_expects_json_for_a_json_accept_header(): void
    {
        self::assertTrue(Request::create('GET', '/', ['headers' => ['Accept' => 'application/json']])->expectsJson());
        self::assertTrue(Request::create('GET', '/', ['headers' => ['Accept' => 'application/vnd.api+json']])->expectsJson());
    }

    public function test_a_browser_does_not_expect_json(): void
    {
        $request = Request::create('GET', '/', [
            'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
        ]);

        self::assertFalse($request->expectsJson());
    }

    public function test_no_accept_header_does_not_expect_json(): void
    {
        self::assertFalse(Request::create('GET', '/')->expectsJson());
    }

    // ---- method and attributes -------------------------------------------

    public function test_the_method_is_upper_cased(): void
    {
        $request = Request::create('post', '/');

        self::assertSame('POST', $request->method());
        self::assertTrue($request->isMethod('post'));
        self::assertFalse($request->isMethod('GET'));
    }

    public function test_with_attribute_returns_a_new_instance(): void
    {
        $request = Request::create('GET', '/');
        $tagged = $request->withAttribute('tenant', 42);

        self::assertNotSame($request, $tagged);
        self::assertNull($request->attribute('tenant'));
        self::assertSame(42, $tagged->attribute('tenant'));
        self::assertSame(['tenant' => 42], $tagged->attributes());
    }

    public function test_with_attribute_preserves_everything_else(): void
    {
        $request = Request::create('POST', '/framework/customers?page=2', [
            'server' => ['SCRIPT_NAME' => '/framework/index.php'],
            'headers' => ['X-Trace' => 'abc'],
            'cookies' => ['session' => 'xyz'],
            'body' => '{"name":"Ada"}',
        ]);

        $tagged = $request->withAttribute('tenant', 1);

        self::assertSame('POST', $tagged->method());
        self::assertSame('/framework', $tagged->basePath());
        self::assertSame('/customers', $tagged->path());
        self::assertSame('2', $tagged->query('page'));
        self::assertSame('abc', $tagged->header('X-Trace'));
        self::assertSame('xyz', $tagged->cookie('session'));
        self::assertSame('{"name":"Ada"}', $tagged->body());
    }

    /**
     * Routing must not leak into the request object. If any of these ever
     * appear, route state has started living in two places.
     */
    public function test_the_request_knows_nothing_about_routing(): void
    {
        $reflection = new \ReflectionClass(Request::class);

        foreach (['route', 'routeParam', 'routeName', 'user'] as $forbidden) {
            self::assertFalse(
                $reflection->hasMethod($forbidden),
                \sprintf('Request::%s() exists; routing has leaked into the HTTP layer.', $forbidden),
            );
        }
    }
}
