<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Security\RequestLimits;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RequestLimitsTest extends TestCase
{
    private function post(int $length, string $type = 'application/json'): Request
    {
        return Request::create('POST', '/customers', [
            'headers' => ['Content-Length' => (string) $length, 'Content-Type' => $type],
            'body' => '{}',
        ]);
    }

    public function test_text_that_is_not_utf8_is_refused_with_400(): void
    {
        $request = Request::create('GET', '/search', ['query' => ['q' => "bad\xFF"]]);

        try {
            (new RequestLimits())($request);
            self::fail('invalid UTF-8 was accepted');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function test_a_nul_byte_in_a_cookie_is_refused(): void
    {
        $this->expectException(HttpException::class);

        (new RequestLimits())(Request::create('GET', '/', ['cookies' => ["a\0b" => 'x']]));
    }

    public function test_values_are_normalised_to_nfc(): void
    {
        $request = Request::create('GET', '/', ['query' => ['q' => "e\u{301}"]]);

        self::assertSame("\u{E9}", $request->query('q'));
    }

    public function test_a_host_outside_the_allowlist_is_refused(): void
    {
        $limits = new RequestLimits(trustedHosts: ['example.com', '*.example.org']);

        $limits(Request::create('GET', '/', ['headers' => ['Host' => 'example.com:8080']]));
        $limits(Request::create('GET', '/', ['headers' => ['Host' => 'app.example.org']]));

        $this->expectException(HttpException::class);

        $limits(Request::create('GET', '/', ['headers' => ['Host' => 'evil.test']]));
    }

    /** @return iterable<string, array{string}> */
    public static function hostsThatAreNotHosts(): iterable
    {
        yield 'a path' => ['evil.example/x'];
        yield 'a space' => ['a b'];
        yield 'a quote' => ['example.com"><script>'];
        yield 'userinfo' => ['user@example.com'];
        yield 'a second port' => ['example.com:80:80'];
        yield 'a backslash' => ['example.com\\evil'];
        yield 'a label too long' => [\str_repeat('a', 64) . '.example'];
        yield 'an unbracketed IPv6 with a port' => ['::1:8080x'];
    }

    /**
     * Whatever trusted_hosts says, including when it is empty, as it is by
     * default: none of these is a Host a browser sends, and each one ends up
     * in a comparison, a cache key or a log line.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hostsThatAreNotHosts')]
    public function test_a_host_header_that_is_not_a_host_is_refused(string $host): void
    {
        try {
            (new RequestLimits())(Request::create('GET', '/', ['headers' => ['Host' => $host]]));
            self::fail(\sprintf('"%s" was accepted as a host', $host));
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function test_every_real_kind_of_host_is_accepted(): void
    {
        foreach (['example.com', 'example.com:8080', 'localhost', '127.0.0.1:8000', '[::1]', '[2001:db8::1]:443',
            'xn--bcher-kva.example', 'my_service', 'example.com.'] as $host) {
            (new RequestLimits())(Request::create('GET', '/', ['headers' => ['Host' => $host]]));
        }

        (new RequestLimits())(Request::create('GET', '/'));

        $this->addToAssertionCount(1);
    }

    /**
     * A body over the limit with no Content-Length -- chunked transfer, which
     * the limit used to wave through after reading all of it.
     */
    public function test_a_body_over_the_limit_without_a_length_is_refused(): void
    {
        $request = Request::create('POST', '/customers', [
            'body' => \str_repeat('x', 101),
            'maxBodyBytes' => 100,
        ]);

        self::assertNull($request->header('Content-Length'));
        self::assertTrue($request->bodyTooLarge());
        self::assertSame('', $request->body(), 'what was over the limit was not kept');

        try {
            (new RequestLimits(100))($request);
            self::fail('a 101-byte body passed a 100-byte limit');
        } catch (HttpException $e) {
            self::assertSame(413, $e->status());
        }

        $exact = Request::create('POST', '/customers', ['body' => \str_repeat('x', 100), 'maxBodyBytes' => 100]);
        self::assertFalse($exact->bodyTooLarge(), 'the limit is inclusive');
        (new RequestLimits(100))($exact);
    }

    /** The reading itself: one byte past the limit and no further, from the real stream code. */
    public function test_the_body_is_read_no_further_than_the_limit(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'body');
        self::assertIsString($file);
        \file_put_contents($file, \str_repeat('y', 1000));

        try {
            $read = new \ReflectionMethod(Request::class, 'readBody');

            self::assertSame([\str_repeat('y', 1000), false], $read->invoke(null, 1000, $file));
            self::assertSame(['', true], $read->invoke(null, 999, $file));
            self::assertSame([\str_repeat('y', 1000), false], $read->invoke(null, null, $file));
        } finally {
            @\unlink($file);
        }
    }

    public function test_a_json_body_nested_too_deeply_reads_as_malformed(): void
    {
        $body = \str_repeat('[', 100) . \str_repeat(']', 100);
        $request = Request::create('POST', '/', ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]);

        self::assertNull($request->json());
    }

    public function test_a_body_within_the_limit_passes(): void
    {
        (new RequestLimits(1000))($this->post(999));

        $this->expectNotToPerformAssertions();
    }

    public function test_a_body_over_the_limit_is_refused_with_413(): void
    {
        try {
            (new RequestLimits(1000))($this->post(1001));
            self::fail('an oversized body was accepted');
        } catch (HttpException $e) {
            self::assertSame(413, $e->status());
            // A client told "too large" and not how large has to bisect its way
            // to the answer.
            self::assertStringContainsString('1000', $e->getMessage());
        }
    }

    public function test_the_limit_is_inclusive(): void
    {
        (new RequestLimits(1000))($this->post(1000));

        $this->expectNotToPerformAssertions();
    }

    public function test_a_request_with_no_content_length_is_not_guessed_at(): void
    {
        (new RequestLimits(10))(Request::create('GET', '/customers'));

        $this->expectNotToPerformAssertions();
    }

    public function test_a_limit_of_zero_means_no_limit(): void
    {
        (new RequestLimits(0, detectDiscardedBodies: false))($this->post(999999999));

        $this->expectNotToPerformAssertions();
    }

    // ---- shorthand -------------------------------------------------------------

    /** @return array<string, array{string, int}> */
    public static function sizes(): array
    {
        return [
            'bytes' => ['1024', 1024],
            'kilobytes' => ['8K', 8192],
            'megabytes' => ['8M', 8388608],
            'gigabytes' => ['1G', 1073741824],
            'lowercase' => ['8m', 8388608],
            'spaced' => [' 8M ', 8388608],
            'unlimited' => ['-1', 0],
            'empty' => ['', 0],
            'nonsense' => ['lots', 0],
        ];
    }

    /** php.ini uses a shorthand that ini_get() returns verbatim. */
    #[DataProvider('sizes')]
    public function test_php_shorthand_sizes_are_understood(string $value, int $bytes): void
    {
        self::assertSame($bytes, RequestLimits::toBytes($value));
    }

    /** @return array<string, array{int, string}> */
    public static function formats(): array
    {
        return [
            'bytes' => [512, '512 bytes'],
            'kilobytes' => [2048, '2 KB'],
            'megabytes' => [8388608, '8 MB'],
            'gigabytes' => [1073741824, '1 GB'],
        ];
    }

    #[DataProvider('formats')]
    public function test_sizes_are_reported_in_a_unit_people_read(int $bytes, string $expected): void
    {
        self::assertSame($expected, RequestLimits::format($bytes));
    }

    public function test_it_describes_its_own_limit(): void
    {
        self::assertStringContainsString('8 MB', (new RequestLimits(8388608))->describe());
    }

    // ---- the check worth having --------------------------------------------------

    /**
     * When an upload exceeds post_max_size, PHP does not fail: it hands the
     * script an empty $_POST and an empty $_FILES with Content-Length still
     * describing what was sent. The handler then reports "name is required",
     * the user swears the field was filled in, and the cause is an ini setting
     * nobody has looked at. The symptom is indistinguishable from an
     * application bug, which is why it is worth detecting.
     */
    public function test_a_form_body_php_discarded_is_reported_as_such(): void
    {
        $limit = RequestLimits::toBytes((string) \ini_get('post_max_size'));

        if ($limit <= 0) {
            self::markTestSkipped('post_max_size is unlimited here, so PHP never discards a body.');
        }

        try {
            (new RequestLimits(0))(Request::create('POST', '/customers', [
                'headers' => [
                    'Content-Length' => (string) ($limit + 1),
                    'Content-Type' => 'multipart/form-data; boundary=x',
                ],
                // Empty, which is exactly what PHP hands a script in this case.
                'body' => [],
            ]));

            self::fail('a discarded body went unreported');
        } catch (HttpException $e) {
            self::assertSame(413, $e->status());
            self::assertStringContainsString('post_max_size', $e->getMessage());
            self::assertStringContainsString('no fields at all', $e->getMessage());
        }
    }

    /**
     * A JSON body is read from the input stream rather than parsed into $_POST,
     * so an empty one means an empty one and must not be mistaken for this.
     */
    public function test_an_empty_json_body_is_not_mistaken_for_a_discarded_one(): void
    {
        $limit = RequestLimits::toBytes((string) \ini_get('post_max_size'));

        if ($limit <= 0) {
            self::markTestSkipped('post_max_size is unlimited here.');
        }

        (new RequestLimits(0))(Request::create('POST', '/customers', [
            'headers' => [
                'Content-Length' => (string) ($limit + 1),
                'Content-Type' => 'application/json',
            ],
            'body' => '',
        ]));

        $this->expectNotToPerformAssertions();
    }

    public function test_the_detection_can_be_turned_off(): void
    {
        $limit = RequestLimits::toBytes((string) \ini_get('post_max_size'));

        if ($limit <= 0) {
            self::markTestSkipped('post_max_size is unlimited here.');
        }

        (new RequestLimits(0, detectDiscardedBodies: false))(Request::create('POST', '/customers', [
            'headers' => [
                'Content-Length' => (string) ($limit + 1),
                'Content-Type' => 'multipart/form-data; boundary=x',
            ],
            'body' => [],
        ]));

        $this->expectNotToPerformAssertions();
    }
}
