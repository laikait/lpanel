<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Engine\Config\ConfigurationException;
use App\Engine\Config\Env;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Reading the environment, which is all text and none of it trustworthy.
 *
 * The conversions are the whole subject. "false" is a non-empty string and
 * therefore true to PHP, which is the single most expensive gotcha in this
 * area, and every one of these tests exists because some framework somewhere
 * got one of them wrong.
 */
final class EnvTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        $this->original = $_ENV;
        Env::forget();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->original;
        Env::forget();

        parent::tearDown();
    }

    private function set(string $name, string $value): void
    {
        $_ENV[$name] = $value;
    }

    // ---- presence ---------------------------------------------------------

    public function test_a_missing_variable_is_null_and_takes_the_default(): void
    {
        self::assertNull(Env::raw('NOTHING_SET_HERE'));
        self::assertSame('fallback', Env::string('NOTHING_SET_HERE', 'fallback'));
        self::assertFalse(Env::has('NOTHING_SET_HERE'));
    }

    /**
     * "APP_ENV=" is a variable somebody meant to fill in, not a request for an
     * empty environment name.
     */
    public function test_an_empty_value_counts_as_absent(): void
    {
        $this->set('EMPTY_ONE', '');

        self::assertNull(Env::raw('EMPTY_ONE'));
        self::assertSame('production', Env::string('EMPTY_ONE', 'production'));
    }

    public function test_a_value_is_returned_as_written(): void
    {
        $this->set('SOME_NAME', 'staging');

        self::assertSame('staging', Env::string('SOME_NAME', 'production'));
    }

    public function test_the_server_array_is_consulted_too(): void
    {
        // Apache's SetEnv lands here rather than in $_ENV, depending on
        // variables_order, which is why all three sources are read.
        $_SERVER['FROM_THE_SERVER'] = 'yes';

        try {
            self::assertSame('yes', Env::string('FROM_THE_SERVER'));
        } finally {
            unset($_SERVER['FROM_THE_SERVER']);
        }
    }

    // ---- booleans ---------------------------------------------------------

    /**
     * @param non-empty-string $written
     */
    #[DataProvider('booleans')]
    public function test_booleans_are_spelled_the_way_people_spell_them(string $written, bool $expected): void
    {
        $this->set('A_FLAG', $written);

        self::assertSame($expected, Env::bool('A_FLAG'));
    }

    /** @return array<string, array{string, bool}> */
    public static function booleans(): array
    {
        return [
            'the word 1' => ['1', true],
            'the word true' => ['true', true],
            'the word TRUE' => ['TRUE', true],
            'the word yes' => ['yes', true],
            'the word on' => ['on', true],
            'the word 0' => ['0', false],
            'the word false' => ['false', false],
            'the word False' => ['False', false],
            'the word no' => ['no', false],
            'the word off' => ['off', false],
        ];
    }

    /**
     * The important one: "false" is a non-empty string, and a cast would make
     * it true.
     */
    public function test_the_string_false_is_false(): void
    {
        $this->set('APP_DEBUG_PROBE', 'false');

        self::assertFalse(Env::bool('APP_DEBUG_PROBE', true));
    }

    public function test_a_boolean_that_is_neither_is_an_error_rather_than_a_guess(): void
    {
        $this->set('A_FLAG', 'maybe');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/A_FLAG/');

        Env::bool('A_FLAG');
    }

    // ---- numbers and lists -------------------------------------------------

    public function test_an_integer_is_parsed(): void
    {
        $this->set('RETENTION', '30');

        self::assertSame(30, Env::int('RETENTION'));
    }

    public function test_a_negative_integer_is_parsed(): void
    {
        $this->set('OFFSET', '-5');

        self::assertSame(-5, Env::int('OFFSET'));
    }

    public function test_something_that_is_not_an_integer_is_an_error(): void
    {
        $this->set('RETENTION', '30 days');

        $this->expectException(ConfigurationException::class);

        Env::int('RETENTION');
    }

    public function test_a_list_is_comma_separated_and_trimmed(): void
    {
        $this->set('WRITERS', 'file, stderr ,');

        self::assertSame(['file', 'stderr'], Env::list('WRITERS'));
    }

    public function test_a_missing_list_is_the_default(): void
    {
        self::assertSame(['file'], Env::list('NOT_SET_AT_ALL', ['file']));
    }

    // ---- the read log ------------------------------------------------------

    /**
     * The log is what lets the configuration cache notice that the environment
     * has moved. A miss has to be recorded as carefully as a hit: a variable
     * that was unset at build time and is set now is exactly the change that
     * must invalidate the cache.
     */
    public function test_every_read_is_recorded_including_the_misses(): void
    {
        $this->set('PRESENT_ONE', 'here');

        Env::string('PRESENT_ONE');
        Env::string('ABSENT_ONE', 'default');

        self::assertSame(['PRESENT_ONE' => 'here', 'ABSENT_ONE' => null], Env::reads());
    }

    public function test_the_log_records_the_raw_text_not_the_converted_value(): void
    {
        $this->set('A_FLAG', 'yes');

        Env::bool('A_FLAG');

        self::assertSame(['A_FLAG' => 'yes'], Env::reads());
    }

    public function test_the_log_can_be_cleared(): void
    {
        Env::string('ANYTHING');
        Env::forget();

        self::assertSame([], Env::reads());
    }
}
