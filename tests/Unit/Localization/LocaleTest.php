<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Localization\Locale;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LocaleTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function valid(): iterable
    {
        yield 'language' => ['en', 'en'];
        yield 'three letters' => ['fil', 'fil'];
        yield 'region' => ['en-US', 'en-US'];
        yield 'case' => ['EN-us', 'en-US'];
        yield 'underscore' => ['bn_bd', 'bn-BD'];
        yield 'numeric region' => ['es-419', 'es-419'];
        yield 'script' => ['zh-hant', 'zh-Hant'];
        yield 'script and region' => ['ZH-HANT-tw', 'zh-Hant-TW'];
        yield 'whitespace' => [' de ', 'de'];
    }

    #[DataProvider('valid')]
    public function test_a_locale_is_normalized(string $input, string $expected): void
    {
        self::assertSame($expected, Locale::normalize($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../../config'];
        yield 'deep traversal' => ['../../../etc/passwd'];
        yield 'traversal after a locale' => ['en/../../'];
        yield 'markup' => ['<script>'];
        yield 'null byte' => ["en\0"];
        yield 'dot' => ['en.php'];
        yield 'one letter' => ['e'];
        yield 'four letter language' => ['engl'];
        yield 'long region' => ['en-USA'];
        yield 'too many parts' => ['en-US-x-private'];
        yield 'trailing dash' => ['en-'];
        yield 'wildcard' => ['*'];
        yield 'oversized' => [\str_repeat('a', 200)];
    }

    #[DataProvider('invalid')]
    public function test_anything_else_is_not_a_locale(string $input): void
    {
        self::assertNull(Locale::normalize($input));
    }

    public function test_candidates_run_from_most_to_least_specific(): void
    {
        self::assertSame(['zh-Hant-TW', 'zh-Hant', 'zh'], Locale::candidates('zh-hant-tw'));
        self::assertSame(['en-US', 'en'], Locale::candidates('en-US'));
        self::assertSame(['bn'], Locale::candidates('bn'));
        self::assertSame([], Locale::candidates('../en'));
    }

    public function test_a_country_is_two_letters_in_upper_case(): void
    {
        self::assertSame('BD', Locale::country('bd'));
        self::assertSame('US', Locale::country(' US '));
        self::assertNull(Locale::country(null));
        self::assertNull(Locale::country(''));
        self::assertNull(Locale::country('BGD'));
        self::assertNull(Locale::country('T1'));
        self::assertNull(Locale::country('XX'), "Cloudflare's unknown");
        self::assertNull(Locale::country('../'));
    }
}
