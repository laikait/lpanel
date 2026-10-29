<?php

declare(strict_types=1);

namespace App\Tests\Unit\Template;

use App\Engine\Template\Escaper;
use App\Tests\Support\TestCase;

/**
 * PHP templates do not escape on their own. This is the mitigation, so it gets
 * tested like one.
 */
final class EscaperTest extends TestCase
{
    private Escaper $e;

    protected function setUp(): void
    {
        $this->e = new Escaper();
    }

    public function test_calling_it_directly_escapes_html(): void
    {
        $e = $this->e;

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $e('<script>alert(1)</script>'));
    }

    public function test_both_kinds_of_quote_are_escaped(): void
    {
        // Single quotes matter: an attribute written with them is common, and
        // ENT_COMPAT would leave it open. ENT_HTML5 spells the entity &apos;
        // rather than &#039;; both are the same character to a browser.
        self::assertSame('&apos;a&apos; &quot;b&quot;', $this->e->attr("'a' \"b\""));
    }

    public function test_an_attribute_cannot_be_broken_out_of(): void
    {
        $escaped = $this->e->attr('" onmouseover="alert(1)');

        self::assertStringNotContainsString('"', \str_replace('&quot;', '', $escaped));
    }

    /**
     * The one that catches people out: HTML-escaping a value that lands in a
     * JavaScript string does nothing useful, and </script> inside the data ends
     * the block early no matter how the quotes were handled.
     */
    public function test_a_script_tag_inside_data_cannot_close_the_block(): void
    {
        $escaped = $this->e->js('</script><script>alert(1)</script>');

        self::assertStringNotContainsString('</script>', $escaped);
        self::assertStringNotContainsString('<script>', $escaped);
    }

    public function test_js_produces_a_complete_literal_rather_than_a_bare_string(): void
    {
        self::assertSame('42', $this->e->js(42));
        self::assertSame('"ada"', $this->e->js('ada'));
        self::assertSame('null', $this->e->js(null));
        self::assertSame('[1,2]', $this->e->js([1, 2]));
        self::assertSame('true', $this->e->js(true));
    }

    public function test_url_encodes_a_single_value(): void
    {
        self::assertSame('a%20b%26c', $this->e->url('a b&c'));
    }

    public function test_raw_is_named_so_it_shows_up_in_a_diff(): void
    {
        self::assertSame('<b>bold</b>', $this->e->raw('<b>bold</b>'));
    }

    public function test_numbers_and_stringables_print(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return '<x>';
            }
        };

        self::assertSame('7', $this->e->html(7));
        self::assertSame('1.5', $this->e->html(1.5));
        self::assertSame('&lt;x&gt;', $this->e->html($stringable));
    }

    /**
     * A float that is not a number still prints, and prints quietly.
     *
     * fdiv() and sqrt(-1) are how these arrive -- ordinary arithmetic with a
     * zero denominator, which an ERP computing a ratio does by accident. PHP
     * 8.5 warns on casting NAN to a string, and a warning raised in the middle
     * of rendering a page is the worst place to discover one.
     */
    public function test_a_non_finite_float_prints_without_a_warning(): void
    {
        self::assertSame('NAN', $this->e->html(\sqrt(-1)));
        self::assertSame('INF', $this->e->html(\fdiv(1, 0)));
        self::assertSame('-INF', $this->e->html(\fdiv(-1, 0)));
    }

    /**
     * "1" for true and "" for false is a silent surprise in markup; empty for
     * both false and null is at least predictable.
     */
    public function test_booleans_and_null_are_predictable(): void
    {
        self::assertSame('1', $this->e->html(true));
        self::assertSame('', $this->e->html(false));
        self::assertSame('', $this->e->html(null));
    }

    /**
     * "Array" printed in a page is how this gets found three weeks later.
     */
    public function test_printing_an_array_is_an_error_rather_than_the_word_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be printed');

        $this->e->html(['a', 'b']);
    }

    public function test_printing_a_plain_object_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->e->html(new \stdClass());
    }

    public function test_invalid_utf8_is_substituted_rather_than_emptied(): void
    {
        // Without ENT_SUBSTITUTE, htmlspecialchars returns "" for invalid
        // input, which silently deletes content instead of showing it is wrong.
        self::assertNotSame('', $this->e->html("valid\x80tail"));
    }
}
