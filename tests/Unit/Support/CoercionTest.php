<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Support\Coercion;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The single definition of "is there exactly one reading of this value".
 *
 * Route parameters, storage rows and schema input all ask this question. They
 * report failure differently, but they must not disagree about what the answer
 * is, which is why the table below is one table.
 */
final class CoercionTest extends TestCase
{
    #[DataProvider('integers')]
    public function test_values_with_one_integer_reading(mixed $value, int $expected): void
    {
        self::assertSame($expected, Coercion::toInt($value));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function integers(): array
    {
        return [
            'an int' => [42, 42],
            'a numeric string' => ['42', 42],
            'a negative string' => ['-42', -42],
            'zero' => ['0', 0],
            'leading zeroes' => ['007', 7],
        ];
    }

    #[DataProvider('notIntegers')]
    public function test_values_with_no_single_integer_reading(mixed $value): void
    {
        self::assertNull(Coercion::toInt($value));
    }

    /** @return array<string, array{0: mixed}> */
    public static function notIntegers(): array
    {
        return [
            'a word' => ['abc'],
            'a decimal string' => ['42.5'],
            'exponent notation' => ['4e2'],
            'a float' => [42.5],
            // 42.0 is an integer mathematically, but accepting it means
            // accepting a lossy read of 42.7 the day someone changes a column.
            'a whole float' => [42.0],
            'true' => [true],
            'false' => [false],
            'an empty string' => [''],
            'a padded number' => [' 42'],
            'a plus sign' => ['+42'],
            'an array' => [[42]],
            'an object' => [new \stdClass()],
        ];
    }

    #[DataProvider('floats')]
    public function test_values_with_one_float_reading(mixed $value, float $expected): void
    {
        self::assertSame($expected, Coercion::toFloat($value));
    }

    /** @return array<string, array{0: mixed, 1: float}> */
    public static function floats(): array
    {
        return [
            'a float' => [12.5, 12.5],
            'an int widens' => [12, 12.0],
            'a decimal string' => ['12.5', 12.5],
            'an integer string' => ['12', 12.0],
            'exponent notation' => ['1e3', 1000.0],
        ];
    }

    public function test_values_with_no_single_float_reading(): void
    {
        self::assertNull(Coercion::toFloat('lots'));
        self::assertNull(Coercion::toFloat(true));
        self::assertNull(Coercion::toFloat([]));
        self::assertNull(Coercion::toFloat(''));
    }

    public function test_numbers_have_one_string_form_and_booleans_do_not(): void
    {
        self::assertSame('hello', Coercion::toString('hello'));
        self::assertSame('42', Coercion::toString(42));
        self::assertSame('12.5', Coercion::toString(12.5));

        // "1" or "true"? There is no answer, so there is no conversion.
        self::assertNull(Coercion::toString(true));
        self::assertNull(Coercion::toString(false));
        self::assertNull(Coercion::toString([]));
    }

    /**
     * The three floats that are not numbers are named, not cast.
     *
     * PHP 8.5 raises a warning when NAN is coerced to a string, so a plain cast
     * here would make a route parameter, a schema field or a log line emit a
     * warning of its own. These are the strings PHP has always produced; what
     * the naming removes is the warning, not the output.
     */
    public function test_a_non_finite_float_has_a_name_rather_than_a_cast(): void
    {
        self::assertSame('NAN', Coercion::fromFloat(\NAN));
        self::assertSame('INF', Coercion::fromFloat(\INF));
        self::assertSame('-INF', Coercion::fromFloat(-\INF));

        self::assertSame('12.5', Coercion::fromFloat(12.5));
        self::assertSame('NAN', Coercion::toString(\NAN));
        self::assertSame('INF', Coercion::toString(\INF));
    }

    #[DataProvider('booleans')]
    public function test_values_with_one_boolean_reading(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Coercion::toBool($value));
    }

    /** @return array<string, array{0: mixed, 1: bool}> */
    public static function booleans(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'one' => [1, true],
            'zero' => [0, false],
            'the string one' => ['1', true],
            'the string zero' => ['0', false],
            'true spelled out' => ['true', true],
            'false spelled out' => ['false', false],
            'mixed case' => ['True', true],
            'yes' => ['yes', true],
            'no' => ['no', false],
            'on' => ['on', true],
            'off' => ['off', false],
        ];
    }

    public function test_values_with_no_single_boolean_reading(): void
    {
        self::assertNull(Coercion::toBool('maybe'));
        self::assertNull(Coercion::toBool(2));
        self::assertNull(Coercion::toBool(-1));
        self::assertNull(Coercion::toBool(1.0));
        self::assertNull(Coercion::toBool(''));
        self::assertNull(Coercion::toBool([]));
    }

    /**
     * False and 0 are successful conversions, not failures. Every caller must
     * distinguish them from null, so this is worth stating outright.
     */
    public function test_a_falsy_result_is_still_a_result(): void
    {
        self::assertNotNull(Coercion::toBool('false'));
        self::assertNotNull(Coercion::toInt('0'));
        self::assertNotNull(Coercion::toFloat('0.0'));
        self::assertNotNull(Coercion::toString(0));
    }
}
