<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Command;
use App\Engine\Cli\ConsoleException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Declaring a command.
 *
 * Every rule here is checked when the command is declared, which is the point
 * of declaring input as objects rather than parsing it out of a signature
 * string: a mistake surfaces while the module is being written, not when
 * somebody finally runs the thing.
 */
final class CommandTest extends TestCase
{
    private function command(string $name = 'customer:sync'): Command
    {
        return new Command($name, static fn(): int => 0);
    }

    // ---- names -------------------------------------------------------------

    /**
     * @return list<array{string}>
     */
    public static function acceptableNames(): array
    {
        return [['about'], ['cache:clear'], ['customer:sync'], ['a:b:c'], ['dry-run-it']];
    }

    /**
     * @return list<array{string}>
     */
    public static function unacceptableNames(): array
    {
        return [
            ['Customer:Sync'],
            ['customer sync'],
            ['customer/sync'],
            [':sync'],
            ['sync:'],
            ['9lives'],
            [''],
        ];
    }

    #[DataProvider('acceptableNames')]
    public function test_a_conventional_name_is_accepted(string $name): void
    {
        self::assertSame($name, $this->command($name)->name);
    }

    #[DataProvider('unacceptableNames')]
    public function test_an_unconventional_name_is_refused(string $name): void
    {
        $this->expectException(ConsoleException::class);

        $this->command($name);
    }

    public function test_the_group_is_everything_before_the_first_colon(): void
    {
        self::assertSame('customer', $this->command('customer:sync')->group());
        self::assertSame('', $this->command('about')->group());
    }

    // ---- arguments and options ---------------------------------------------

    public function test_an_argument_and_an_option_cannot_share_a_name(): void
    {
        $command = $this->command()->argument('limit');

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('already has an argument or option called "limit"');

        $command->option('limit');
    }

    public function test_two_arguments_cannot_share_a_name(): void
    {
        $command = $this->command()->argument('id');

        $this->expectException(ConsoleException::class);

        $command->argument('id');
    }

    /**
     * Positional means ordered, and an optional argument in the middle makes
     * the order unreadable: the second value typed could belong to either.
     */
    public function test_a_required_argument_cannot_follow_an_optional_one(): void
    {
        $command = $this->command()->argument('since', required: false);

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('follows an optional one');

        $command->argument('id');
    }

    public function test_an_optional_argument_may_follow_an_optional_one(): void
    {
        $command = $this->command()
            ->argument('since', required: false)
            ->argument('until', required: false);

        self::assertCount(2, $command->arguments());
    }

    public function test_an_option_name_must_be_something_a_shell_can_type(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('is invalid');

        $this->command()->option('Dry Run');
    }

    public function test_a_shortcut_is_exactly_one_letter(): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('is invalid');

        $this->command()->flag('dry-run', shortcut: 'dr');
    }

    public function test_a_flag_has_no_configurable_default(): void
    {
        $command = $this->command()->flag('dry-run');
        $option = $command->optionNamed('dry-run');

        self::assertNotNull($option);
        self::assertFalse($option->defaultValue());
    }

    // ---- synopsis -----------------------------------------------------------

    public function test_the_synopsis_shows_required_and_optional_arguments_differently(): void
    {
        $command = $this->command()
            ->argument('id')
            ->argument('since', required: false);

        self::assertSame('customer:sync <id> [since]', $command->synopsis());
    }

    public function test_the_synopsis_marks_that_there_are_options(): void
    {
        self::assertSame(
            'customer:sync [options]',
            $this->command()->flag('dry-run')->synopsis(),
        );
    }

    public function test_an_option_synopsis_shows_its_shortcut_and_whether_it_takes_a_value(): void
    {
        $command = $this->command()
            ->option('limit', shortcut: 'l')
            ->flag('dry-run', shortcut: 'd')
            ->flag('quiet');

        self::assertSame('-l, --limit=<value>', $command->options()[0]->synopsis());
        self::assertSame('-d, --dry-run', $command->options()[1]->synopsis());
        self::assertSame('--quiet', $command->options()[2]->synopsis());
    }

    // ---- ownership -----------------------------------------------------------

    public function test_a_command_remembers_which_module_declared_it(): void
    {
        $command = new Command('customer:sync', static fn(): int => 0, 'Example');

        self::assertSame('Example', $command->module);
    }

    public function test_lookups_find_nothing_rather_than_failing(): void
    {
        $command = $this->command();

        self::assertNull($command->argumentNamed('nope'));
        self::assertNull($command->optionNamed('nope'));
        self::assertNull($command->optionForShortcut('z'));
    }
}
