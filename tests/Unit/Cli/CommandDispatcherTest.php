<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cli;

use App\Engine\Cli\Command;
use App\Engine\Cli\CommandDispatcher;
use App\Engine\Cli\ConsoleException;
use App\Engine\Cli\Input;
use App\Engine\Cli\Output;
use App\Engine\Container\Container;
use App\Engine\Dispatch\DispatchException;
use App\Engine\Hook\HookEngine;
use App\Tests\Fixtures\Cli\CountItems;
use App\Tests\Fixtures\Cli\TouchItem;
use App\Tests\Support\TestCase;

/**
 * Calling a command's handler.
 *
 * The route dispatcher's twin, and what is checked here is that it behaves like
 * one: three handler forms, dependencies injected, declared input bound by name
 * and coerced narrowly, and objects taken by type so that nothing the operator
 * typed can displace them.
 */
final class CommandDispatcherTest extends TestCase
{
    private Container $container;

    private HookEngine $hooks;

    private CommandDispatcher $dispatcher;

    /** @var resource */
    private mixed $stream;

    private Output $output;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->hooks = new HookEngine();
        $this->dispatcher = new CommandDispatcher($this->container, $this->hooks);

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $this->stream = $stream;
        $this->output = new Output($stream);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }

        parent::tearDown();
    }

    /** @param list<string> $tokens */
    private function dispatch(Command $command, array $tokens = []): int
    {
        return $this->dispatcher->dispatch($command, Input::parse($command, $tokens), $this->output);
    }

    private function printed(): string
    {
        \rewind($this->stream);

        return (string) \stream_get_contents($this->stream);
    }

    // ---- handler forms -----------------------------------------------------

    public function test_a_closure_handler_runs(): void
    {
        $status = $this->dispatch(new Command('x', static fn(): int => 0));

        self::assertSame(0, $status);
    }

    public function test_an_invokable_class_is_built_by_the_container(): void
    {
        $this->dispatch(new Command('item:count', CountItems::class));

        self::assertStringContainsString('2 items', $this->printed());
    }

    public function test_a_class_and_method_pair_runs(): void
    {
        $command = (new Command('item:touch', [TouchItem::class, 'touch']))->argument('id');

        self::assertSame(0, $this->dispatch($command, ['7']));
        self::assertStringContainsString('touched 7', $this->printed());
    }

    public function test_a_handler_that_cannot_be_resolved_says_so(): void
    {
        $this->expectException(DispatchException::class);

        $this->dispatch(new Command('x', 'App\\Nope\\Missing'));
    }

    // ---- binding -----------------------------------------------------------

    public function test_an_argument_binds_to_a_parameter_of_the_same_name(): void
    {
        $seen = null;
        $command = (new Command('x', static function (string $since) use (&$seen): int {
            $seen = $since;

            return 0;
        }))->argument('since');

        $this->dispatch($command, ['2026-01-01']);

        self::assertSame('2026-01-01', $seen);
    }

    public function test_a_dashed_option_binds_to_the_camel_case_parameter(): void
    {
        $seen = null;
        $command = (new Command('x', static function (bool $dryRun) use (&$seen): int {
            $seen = $dryRun;

            return 0;
        }))->flag('dry-run');

        $this->dispatch($command, ['--dry-run']);

        self::assertTrue($seen);
    }

    public function test_a_declared_int_arrives_as_an_int(): void
    {
        $seen = null;
        $command = (new Command('x', static function (int $times) use (&$seen): int {
            $seen = $times;

            return 0;
        }))->option('times', default: '1');

        $this->dispatch($command, ['--times=4']);

        self::assertSame(4, $seen);
    }

    public function test_a_value_that_does_not_convert_is_a_usage_error(): void
    {
        $command = (new Command('x', static fn(int $times): int => $times))->option('times', default: '1');

        $caught = null;

        try {
            $this->dispatch($command, ['--times=lots']);
        } catch (ConsoleException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(ConsoleException::class, $caught);
        self::assertTrue($caught->usage, 'the operator mistyped something, so the synopsis is what helps');
        self::assertStringContainsString('"lots" is not a whole number', $caught->getMessage());
    }

    public function test_a_handler_need_not_declare_everything_the_command_accepts(): void
    {
        $command = (new Command('x', static fn(): int => 0))
            ->argument('since', required: false)
            ->flag('dry-run');

        self::assertSame(0, $this->dispatch($command, ['2026-01-01', '--dry-run']));
    }

    /**
     * The console's version of "the Request goes in by type, never by name".
     *
     * A command that declares an argument called "output" would otherwise have
     * a string where the Output belongs.
     */
    public function test_input_and_output_are_injected_by_type_not_by_name(): void
    {
        $seen = [];
        $command = (new Command('x', static function (Output $output, Input $input, string $id) use (&$seen): int {
            $seen = [$output::class, $input->command->name, $id];

            return 0;
        }))
            ->argument('id')
            ->option('output', default: 'hijacked')
            ->option('input', default: 'hijacked');

        $this->dispatch($command, ['7']);

        self::assertSame([Output::class, 'x', '7'], $seen);
    }

    public function test_the_output_a_handler_receives_is_the_one_the_console_is_writing_to(): void
    {
        $command = new Command('x', static function (Output $output): int {
            $output->line('from the handler');

            return 0;
        });

        $this->dispatch($command);

        self::assertStringContainsString('from the handler', $this->printed());
    }

    // ---- exit codes ---------------------------------------------------------

    public function test_an_int_is_the_exit_code(): void
    {
        self::assertSame(3, $this->dispatch(new Command('x', static fn(): int => 3)));
    }

    public function test_returning_nothing_is_success(): void
    {
        self::assertSame(0, $this->dispatch(new Command('x', static function (): void {})));
    }

    public function test_a_string_is_printed_and_succeeds(): void
    {
        self::assertSame(0, $this->dispatch(new Command('x', static fn(): string => 'pong')));
        self::assertStringContainsString('pong', $this->printed());
    }

    /**
     * A bool is refused because the two conventions disagree: PHP says true is
     * success, the shell says 0 is. Guessing turns a failed job into a tick.
     */
    public function test_a_bool_is_refused_rather_than_interpreted(): void
    {
        $caught = 'nothing was thrown';

        try {
            $this->dispatch(new Command('x', static fn(): bool => true));
        } catch (ConsoleException $e) {
            $caught = $e->getMessage();
        }

        self::assertStringContainsString('Returning a bool is not accepted', $caught);
    }

    public function test_anything_else_is_refused(): void
    {
        $this->expectException(ConsoleException::class);

        $this->dispatch(new Command('x', static fn(): array => ['nope']));
    }

    // ---- hooks ---------------------------------------------------------------

    public function test_the_lifecycle_hooks_fire_with_the_command_and_its_input(): void
    {
        $seen = [];

        $this->hooks->add('command.matched', static function (Command $command, Input $input) use (&$seen): void {
            $seen[] = ['matched', $command->name, $input->argument('id')];
        });

        $this->hooks->add('command.finished', static function (int $status, Command $command) use (&$seen): void {
            $seen[] = ['finished', $command->name, $status];
        });

        $command = (new Command('item:touch', static fn(): int => 3))->argument('id');

        $this->dispatch($command, ['7']);

        self::assertSame([['matched', 'item:touch', '7'], ['finished', 'item:touch', 3]], $seen);
    }
}
