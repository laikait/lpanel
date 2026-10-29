<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Engine\Config\ConfigurationException;
use App\Engine\Config\DotEnv;
use App\Engine\Config\Env;
use App\Tests\Support\TestCase;

/**
 * The .env file: a convenience for machines with no real environment.
 *
 * The parsing rules are small on purpose, and the two that matter are not about
 * parsing at all -- a real environment variable always wins, and there is no
 * interpolation.
 */
final class DotEnvTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    private string $file = '';

    protected function setUp(): void
    {
        $this->original = $_ENV;
        $this->file = \sys_get_temp_dir() . '/dotenv-' . \bin2hex(\random_bytes(6)) . '.env';
        Env::forget();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->original;
        Env::forget();

        if (\is_file($this->file)) {
            @\unlink($this->file);
        }

        parent::tearDown();
    }

    private function write(string $contents): string
    {
        \file_put_contents($this->file, $contents);

        return $this->file;
    }

    // ---- parsing ----------------------------------------------------------

    public function test_a_plain_assignment(): void
    {
        self::assertSame(['APP_ENV' => 'staging'], DotEnv::parse('APP_ENV=staging'));
    }

    public function test_blank_lines_and_comments_are_skipped(): void
    {
        self::assertSame(
            ['A' => '1'],
            DotEnv::parse("# a heading\n\n   \nA=1\n"),
        );
    }

    public function test_an_exported_line_is_accepted(): void
    {
        // Because people paste them out of a shell session.
        self::assertSame(['A' => '1'], DotEnv::parse('export A=1'));
    }

    public function test_whitespace_around_the_equals_sign_is_ignored(): void
    {
        self::assertSame(['A' => '1'], DotEnv::parse('A = 1'));
    }

    public function test_a_trailing_comment_needs_whitespace_in_front_of_it(): void
    {
        self::assertSame(
            ['A' => 'value', 'B' => 'a#b'],
            DotEnv::parse("A=value # explained\nB=a#b"),
        );
    }

    public function test_single_quotes_are_literal(): void
    {
        self::assertSame(['A' => 'a b # c'], DotEnv::parse("A='a b # c'"));
    }

    public function test_double_quotes_understand_four_escapes(): void
    {
        self::assertSame(
            ['A' => "one\ttwo\nthree\"four"],
            DotEnv::parse('A="one\ttwo\nthree\"four"'),
        );
    }

    public function test_a_value_may_be_empty(): void
    {
        self::assertSame(['A' => ''], DotEnv::parse('A='));
    }

    /**
     * Interpolation would turn a flat list of settings into a small programming
     * language, and the first question it raises -- does ${OTHER} see the real
     * environment, this file, or both -- has no good answer.
     */
    public function test_there_is_no_variable_interpolation(): void
    {
        $_ENV['OTHER'] = 'expanded';

        self::assertSame(['A' => '${OTHER}'], DotEnv::parse('A=${OTHER}'));
    }

    public function test_windows_line_endings_are_understood(): void
    {
        self::assertSame(['A' => '1', 'B' => '2'], DotEnv::parse("A=1\r\nB=2\r\n"));
    }

    /**
     * A line that cannot be read is louder than a line that is ignored: a
     * setting which silently fails to apply is the failure mode that eats an
     * afternoon.
     */
    public function test_a_line_that_is_not_an_assignment_stops_everything(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Line 2/');

        DotEnv::parse("A=1\nAPP_ENV: staging\n");
    }

    public function test_a_name_that_is_not_a_variable_name_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);

        DotEnv::parse('not a name=1');
    }

    // ---- loading ----------------------------------------------------------

    public function test_a_missing_file_is_not_an_error(): void
    {
        self::assertSame([], DotEnv::load(\sys_get_temp_dir() . '/definitely-not-here.env'));
    }

    public function test_loading_makes_values_readable_through_env(): void
    {
        DotEnv::load($this->write("FROM_FILE=yes\n"));

        self::assertSame('yes', Env::string('FROM_FILE'));
    }

    /**
     * The rule that makes this safe to load unconditionally: a .env file left
     * behind on a server cannot override what the deployment set.
     */
    public function test_the_real_environment_wins(): void
    {
        $_ENV['ALREADY_SET'] = 'from the environment';

        $applied = DotEnv::load($this->write("ALREADY_SET=from the file\n"));

        self::assertSame([], $applied);
        self::assertSame('from the environment', Env::string('ALREADY_SET'));
    }

    public function test_it_reports_what_it_supplied(): void
    {
        $_ENV['ALREADY_SET'] = 'kept';

        self::assertSame(
            ['NEW_ONE'],
            DotEnv::load($this->write("ALREADY_SET=ignored\nNEW_ONE=applied\n")),
        );
    }
}
