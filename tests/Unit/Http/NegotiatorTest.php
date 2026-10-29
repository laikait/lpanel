<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\HttpException;
use App\Engine\Http\MediaType;
use App\Engine\Http\Negotiator;
use App\Engine\Http\Request;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content negotiation, including the cases a substring test gets wrong.
 */
final class NegotiatorTest extends TestCase
{
    // ---- parsing ----------------------------------------------------------

    public function test_a_media_type_is_parsed_into_its_parts(): void
    {
        $type = MediaType::parse('Application/Vnd.API+JSON; q=0.8; charset=utf-8');

        self::assertNotNull($type);
        self::assertSame('application', $type->type);
        self::assertSame('vnd.api+json', $type->subtype);
        self::assertSame(0.8, $type->quality);
        self::assertSame('utf-8', $type->charset());
    }

    public function test_anything_that_is_not_a_media_type_parses_to_null(): void
    {
        foreach (['', 'json', 'a/b/c', '/json', 'application/', '  '] as $value) {
            self::assertNull(MediaType::parse($value), $value);
        }
    }

    /** A typo in q should not silently mean "I refuse this type". */
    public function test_a_malformed_quality_is_treated_as_absent(): void
    {
        $type = MediaType::parse('text/html; q=high');

        self::assertNotNull($type);
        self::assertSame(1.0, $type->quality);
    }

    public function test_quality_is_clamped_to_its_legal_range(): void
    {
        self::assertSame(1.0, MediaType::parse('text/html;q=5')?->quality);
        self::assertSame(0.0, MediaType::parse('text/html;q=-1')?->quality);
    }

    /** The suffix rule, which is what makes vendor types work unregistered. */
    public function test_a_vendor_suffix_is_still_json(): void
    {
        self::assertTrue(MediaType::parse('application/json')?->isJson());
        self::assertTrue(MediaType::parse('application/vnd.example.v2+json')?->isJson());
        self::assertFalse(MediaType::parse('application/xml')?->isJson());
        self::assertFalse(MediaType::parse('text/jsonp')?->isJson());
    }

    public function test_a_range_matches_in_one_direction_only(): void
    {
        $range = MediaType::parse('text/*');
        self::assertNotNull($range);

        self::assertTrue($range->matches('text/html'));
        self::assertFalse($range->matches('application/json'));

        $concrete = MediaType::parse('text/html');
        self::assertNotNull($concrete);
        self::assertFalse($concrete->matches('text/*'), 'a concrete type does not match a range');
    }

    public function test_ranges_are_ordered_by_quality_then_specificity(): void
    {
        $ranges = MediaType::parseList('*/*;q=0.5, text/*;q=0.5, application/json;q=0.9, text/html');

        self::assertSame(
            ['text/html', 'application/json', 'text/*', '*/*'],
            \array_map(static fn(MediaType $t): string => $t->full(), $ranges),
        );
    }

    // ---- choosing ---------------------------------------------------------

    /**
     * The case the whole class exists for.
     *
     * A client saying this wants JSON. Any check of the form "does the header
     * contain text/html" hands it a web page, and an API quietly serves markup
     * to a program.
     */
    public function test_quality_decides_rather_than_the_order_the_types_appear_in(): void
    {
        self::assertSame(
            'application/json',
            Negotiator::best('application/json;q=0.9, text/html;q=0.8', ['text/html', 'application/json']),
        );
    }

    public function test_a_more_specific_range_beats_a_wildcard_at_the_same_quality(): void
    {
        self::assertSame(
            'application/json',
            Negotiator::best('*/*, application/json', ['text/html', 'application/json']),
        );
    }

    /** With nothing to choose between, the server's own order decides. */
    public function test_the_server_order_breaks_a_tie_the_client_did_not_break(): void
    {
        self::assertSame('text/html', Negotiator::best('*/*', ['text/html', 'application/json']));
        self::assertSame('application/json', Negotiator::best('*/*', ['application/json', 'text/html']));
    }

    public function test_an_absent_accept_means_anything(): void
    {
        self::assertSame('text/html', Negotiator::best(null, ['text/html', 'application/json']));
        self::assertSame('text/html', Negotiator::best('', ['text/html', 'application/json']));
        self::assertSame('text/html', Negotiator::best('   ', ['text/html', 'application/json']));
    }

    /** q=0 is a refusal, not a weak preference. */
    public function test_a_zero_quality_rejects_that_type(): void
    {
        self::assertSame(
            'application/json',
            Negotiator::best('text/html;q=0, application/json', ['text/html', 'application/json']),
        );

        self::assertNull(Negotiator::best('text/html;q=0', ['text/html']));
    }

    public function test_nothing_acceptable_is_null_rather_than_a_guess(): void
    {
        self::assertNull(Negotiator::best('application/xml', ['text/html', 'application/json']));
        self::assertNull(Negotiator::best('*/*', []));
    }

    /** An unparseable header is treated as no preference, not as a refusal. */
    public function test_a_nonsense_accept_header_does_not_produce_a_406(): void
    {
        self::assertSame('text/html', Negotiator::best('????', ['text/html']));
    }

    public function test_a_vendor_type_is_matched_exactly_not_by_suffix(): void
    {
        // isJson() is a question about a type; negotiation is about what the
        // endpoint literally offers. A client asking for a vendor type this
        // endpoint does not produce gets a 406, which is correct.
        self::assertNull(Negotiator::best('application/vnd.example+json', ['application/json']));
        self::assertSame(
            'application/vnd.example+json',
            Negotiator::best('application/vnd.example+json', ['application/vnd.example+json']),
        );
    }

    // ---- prefersJson ------------------------------------------------------

    /** @return list<array{string|null, bool}> */
    public static function jsonPreferences(): array
    {
        return [
            [null, false],
            ['', false],
            ['*/*', false],
            ['text/html', false],
            ['text/html,application/xhtml+xml,*/*;q=0.8', false],
            ['application/json', true],
            // Neither text/html nor application/json is acceptable to this
            // client, so neither is "preferred" -- but it named its types and
            // HTML was not among them, so it is not a browser. A JSON:API
            // client gets a JSON error rather than a web page.
            ['application/vnd.api+json', true],
            ['application/xml', true],
            ['application/json, text/html;q=0.9', true],
            ['application/json;q=0.9, text/html;q=0.8', true],
            ['text/html;q=0.8, application/json;q=0.9', true],
            ['text/html;q=0, application/json', true],
        ];
    }

    #[DataProvider('jsonPreferences')]
    public function test_json_is_preferred_only_when_it_outranks_html(?string $accept, bool $expected): void
    {
        self::assertSame($expected, Negotiator::prefersJson($accept));
    }

    /**
     * The rule above must not swallow the common case: a browser lists
     * text/html explicitly, so it is never treated as a machine.
     */
    public function test_a_client_that_accepts_html_is_never_given_json(): void
    {
        foreach (['text/html', 'text/*', '*/*', 'text/html;q=0.1'] as $accept) {
            self::assertFalse(Negotiator::prefersJson($accept), $accept);
        }
    }

    /** A browser sends a wildcard somewhere in its list; it must still get a page. */
    public function test_a_browser_accept_header_still_gets_html(): void
    {
        $browser = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

        self::assertFalse(Negotiator::prefersJson($browser));
        self::assertSame('text/html', Negotiator::best($browser, ['text/html', 'application/json']));
    }

    // ---- the request side -------------------------------------------------

    public function test_require_throws_a_406_naming_what_is_available(): void
    {
        $request = Request::create('GET', '/customers', ['headers' => ['Accept' => 'application/xml']]);

        try {
            $request->negotiate(['text/html', 'application/json']);
            self::fail('an unacceptable request was not refused');
        } catch (HttpException $e) {
            self::assertSame(406, $e->status());
            self::assertStringContainsString('text/html', $e->getMessage());
            self::assertStringContainsString('application/json', $e->getMessage());
        }
    }

    public function test_expects_json_uses_real_negotiation(): void
    {
        $request = Request::create('GET', '/', [
            'headers' => ['Accept' => 'application/json;q=0.9, text/html;q=0.8'],
        ]);

        // The old substring check said false here, because "text/html" appears
        // in the header. This is the behaviour change the phase introduces.
        self::assertTrue($request->expectsJson());
    }

    public function test_a_client_that_sent_json_is_assumed_to_read_it(): void
    {
        $request = Request::create('POST', '/', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'text/html'],
            'body' => '{}',
        ]);

        self::assertTrue($request->expectsJson());
    }

    // ---- request payloads -------------------------------------------------

    public function test_a_body_in_the_wrong_format_is_a_415(): void
    {
        $request = Request::create('POST', '/', [
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => 'name=Ada',
        ]);

        try {
            $request->requirePayload();
            self::fail('a text/plain body was accepted as JSON');
        } catch (HttpException $e) {
            self::assertSame(415, $e->status());
            self::assertStringContainsString('text/plain', $e->getMessage());
        }
    }

    public function test_a_body_with_no_content_type_is_a_415(): void
    {
        $request = Request::create('POST', '/', ['body' => '{"name":"Ada"}']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('needs a Content-Type');

        $request->requirePayload();
    }

    /** A client sending application/vnd.example+json is sending JSON. */
    public function test_a_vendor_json_body_satisfies_a_json_requirement(): void
    {
        $request = Request::create('POST', '/', [
            'headers' => ['Content-Type' => 'application/vnd.example+json'],
            'body' => '{"name":"Ada"}',
        ]);

        $request->requirePayload();

        self::assertSame('Ada', $request->json('name'));
    }

    public function test_a_charset_parameter_does_not_break_the_check(): void
    {
        $request = Request::create('POST', '/', [
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => '{"name":"Ada"}',
        ]);

        $caught = 'nothing was thrown';

        try {
            $request->requirePayload();
        } catch (HttpException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('nothing was thrown', $caught);
        self::assertSame('Ada', $request->json('name'));
    }

    /** There is nothing to misread when there is nothing to read. */
    public function test_a_request_with_no_body_is_not_checked(): void
    {
        $caught = 'nothing was thrown';

        try {
            // No Content-Type either, which would be a 415 if there were a body.
            Request::create('GET', '/')->requirePayload();
        } catch (HttpException $e) {
            $caught = $e->getMessage();
        }

        self::assertSame('nothing was thrown', $caught);
    }
}
