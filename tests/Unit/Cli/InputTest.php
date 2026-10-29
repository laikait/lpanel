<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Command;
use App\Engine\Cli\ConsoleException;
use App\Engine\Cli\Input;
use App\Tests\Support\TestCase;

/**
 * Parsing a command line against what the command said it accepts.
 *
 * The definition is an input to parsing, not something applied afterwards,
 * which is why "--limit 50" can only be resolved with the definition in hand.
 */
final class InputTest extends TestCase
{
    private function command(): Command
    {
        return (new Command('customer:sync', static fn(): int => 0))
            ->argument('since', 'From when.', required: false, default: 'yesterday')
            ->option('limit', 'How many.', shortcut: 'l', default: '25')
            ->flag('dry-run', 'Change nothing.', shortcut: 'd');
    }

    /** @param list<string> $tokens */
    private function parse(array $tokens): Input
    {
        return Input::parse($this->command(), $tokens);
    }

    // ---- defaults ----------------------------------------------------------

    public function test_an_empty_line_yields_the_declared_defaults(): void
    {
        $input = $this->parse([]);

        self::assertSame('yesterday', $input->argument('since'));
        self::assertSame('25', $input->option('limit'));
        self::assertFalse($input->option('dry-run'));
    }

    public function test_a_flag_is_false_until_it_is_written(): void
    {
        self::assertFalse($this->parse([])->option('dry-run'));
        self::assertTrue($this->parse(['--dry-run'])->option('dry-run'));
    }

    // ---- long options ------------------------------------------------------

    public function test_an_attached_value_is_read(): void
    {
        self::assertSame('50', $this->parse(['--limit=50'])->option('limit'));
    }

    public function test_a_separate_value_is_read(): void
    {
        self::assertSame('50', $this->parse(['--limit', '50'])->option('limit'));
    }

    public function test_a_value_may_itself_contain_an_equals_sign(): void
    {
        self::assertSame('a=b', $this->parse(['--limit=a=b'])->option('limit'));
    }

    public function test_a_flag_given_a_value_is_refused(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('is a flag and takes no value');

        $this->parse(['--dry-run=yes']);
    }

    /**
     * A forgotten value must not swallow the next option.
     *
     * "--limit --dry-run" reading as limit="--dry-run" would hide the mistake
     * behind a nonsensical limit, and the run would look like it worked.
     */
    public function test_an_option_is_never_given_the_next_option_as_its_value(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('needs a value');

        $this->parse(['--limit', '--dry-run']);
    }

    public function test_a_trailing_option_with_no_value_is_refused(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('needs a value');

        $this->parse(['--limit']);
    }

    public function test_an_undeclared_option_is_refused_and_the_real_ones_are_named(): void
    {
        $caught = 'nothing was thrown';

        try {
            $this->parse(['--verbose']);
        } catch (ConsoleException $e) {
            $caught = $e->getMessage();
        }

        self::assertStringContainsString('no option --verbose', $caught);
        self::assertStringContainsString('--limit', $caught);
        self::assertStringContainsString('--dry-run', $caught);
    }

    /** Abbreviation is not supported: it turns ambiguous when an option is added. */
    public function test_an_abbreviated_option_is_not_guessed(): void
    {
        $this->expectException(ConsoleException::class);

        $this->parse(['--lim=5']);
    }

    // ---- short options -----------------------------------------------------

    public function test_a_shortcut_takes_the_next_token(): void
    {
        self::assertSame('50', $this->parse(['-l', '50'])->option('limit'));
    }

    public function test_a_shortcut_value_may_be_attached(): void
    {
        self::assertSame('50', $this->parse(['-l50'])->option('limit'));
        self::assertSame('50', $this->parse(['-l=50'])->option('limit'));
    }

    public function test_flags_bundle(): void
    {
        $command = (new Command('x', static fn(): int => 0))
            ->flag('one', shortcut: 'a')
            ->flag('two', shortcut: 'b')
            ->flag('three', shortcut: 'c');

        $input = Input::parse($command, ['-abc']);

        self::assertTrue($input->option('one'));
        self::assertTrue($input->option('two'));
        self::assertTrue($input->option('three'));
    }

    public function test_a_bundle_may_end_with_a_value_option(): void
    {
        $input = $this->parse(['-dl50']);

        self::assertTrue($input->option('dry-run'));
        self::assertSame('50', $input->option('limit'));
    }

    public function test_an_unknown_shortcut_is_refused(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('no option -z');

        $this->parse(['-z']);
    }

    // ---- arguments ---------------------------------------------------------

    public function test_a_positional_argument_is_bound_by_position(): void
    {
        self::assertSame('2026-01-01', $this->parse(['2026-01-01'])->argument('since'));
    }

    public function test_arguments_and_options_may_be_interleaved(): void
    {
        $input = $this->parse(['--dry-run', '2026-01-01', '--limit=5']);

        self::assertSame('2026-01-01', $input->argument('since'));
        self::assertSame('5', $input->option('limit'));
        self::assertTrue($input->option('dry-run'));
    }

    public function test_a_missing_required_argument_is_refused(): void
    {
        $command = (new Command('x', static fn(): int => 0))->argument('id', 'The id.');

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('needs the <id> argument');

        Input::parse($command, []);
    }

    public function test_more_arguments_than_were_declared_is_refused(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('takes 1 argument, but 2 were given');

        $this->parse(['a', 'b']);
    }

    // ---- the separator -----------------------------------------------------

    public function test_everything_after_a_double_dash_is_positional(): void
    {
        $input = $this->parse(['--', '--dry-run']);

        self::assertSame('--dry-run', $input->argument('since'));
        self::assertFalse($input->option('dry-run'));
    }

    /** A lone "-" conventionally means stdin, so it is a value, not an option. */
    public function test_a_lone_dash_is_an_argument(): void
    {
        self::assertSame('-', $this->parse(['-'])->argument('since'));
    }

    public function test_a_negative_number_can_be_passed_after_the_separator(): void
    {
        self::assertSame('-5', $this->parse(['--', '-5'])->argument('since'));
    }

    // ---- reading -----------------------------------------------------------

    public function test_parameters_merges_arguments_and_options(): void
    {
        self::assertSame(
            ['since' => '2026-01-01', 'limit' => '5', 'dry-run' => true],
            $this->parse(['2026-01-01', '--limit=5', '--dry-run'])->parameters(),
        );
    }

    public function test_given_distinguishes_a_default_from_something_typed(): void
    {
        self::assertFalse($this->parse([])->given('dry-run'));
        self::assertTrue($this->parse(['--dry-run'])->given('dry-run'));
    }

    public function test_the_raw_tokens_are_kept(): void
    {
        self::assertSame(['--limit=5'], $this->parse(['--limit=5'])->tokens());
    }

    public function test_the_command_travels_with_its_input(): void
    {
        self::assertSame('customer:sync', $this->parse([])->command->name);
    }
}
