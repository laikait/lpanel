<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Filter\FilterEngine;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\Invocation;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Process\ProcessManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The two filters engine/System offers, and the one direction they work in.
 */
final class LimitFiltersTest extends TestCase
{
    private function prepared(FilterEngine $filters, ?float $timeout = 10.0, ?int $maxOutput = 1000): Invocation
    {
        return Invocation::prepare(new Command(\PHP_BINARY, ['-v'], timeout: $timeout, maxOutput: $maxOutput), 60.0, 4096, filters: $filters);
    }

    public function test_a_filter_can_shorten_the_timeout_and_lower_the_output_limit(): void
    {
        $filters = new FilterEngine();
        $filters->add(Invocation::TIMEOUT_FILTER, static fn(float $timeout): float => \min($timeout, 2.5));
        $filters->add(Invocation::MAX_OUTPUT_FILTER, static fn(int $bytes): int => 100);

        $invocation = $this->prepared($filters);

        self::assertSame(2.5, $invocation->timeout);
        self::assertSame(100, $invocation->limit);
    }

    /** @return iterable<string, array{mixed}> */
    public static function widenings(): iterable
    {
        yield 'longer' => [3600.0];
        yield 'zero, which might read as unlimited' => [0];
        yield 'negative' => [-1];
        yield 'infinite' => [\INF];
        yield 'not a number' => ['forever'];
        yield 'null' => [null];
    }

    /** No listener can lift a limit the application set. */
    #[DataProvider('widenings')]
    public function test_a_filter_cannot_lengthen_or_remove_a_limit(mixed $returned): void
    {
        $filters = new FilterEngine();
        $filters->add(Invocation::TIMEOUT_FILTER, static fn(): mixed => $returned);
        $filters->add(Invocation::MAX_OUTPUT_FILTER, static fn(): mixed => $returned);

        $invocation = $this->prepared($filters);

        self::assertSame(10.0, $invocation->timeout);
        self::assertSame(1000, $invocation->limit);
    }

    public function test_the_filter_sees_the_command_it_is_deciding_for(): void
    {
        $filters = new FilterEngine();
        $filters->add(
            Invocation::TIMEOUT_FILTER,
            static fn(float $timeout, Command|ShellCommand $command): float => $command instanceof Command && \str_contains($command->executable(), 'php') ? 1.0 : $timeout,
        );

        self::assertSame(1.0, $this->prepared($filters)->timeout);
    }

    public function test_the_defaults_are_filtered_too(): void
    {
        $filters = new FilterEngine();
        $filters->add(Invocation::TIMEOUT_FILTER, static fn(): float => 5.0);

        self::assertSame(5.0, $this->prepared($filters, timeout: null)->timeout, 'the executor default of 60 was not narrowed');
    }

    /** End to end: the executor and the process manager apply it to what actually runs. */
    public function test_the_executor_and_process_manager_run_with_the_narrowed_limits(): void
    {
        $filters = new FilterEngine();
        $filters->add(Invocation::TIMEOUT_FILTER, static fn(): float => 0.3);

        $sleep = new Command(\PHP_BINARY, ['-n', '-r', 'sleep(10);'], timeout: 30.0);

        self::assertTrue((new CommandExecutor(filters: $filters))->run($sleep)->timedOut());
        self::assertTrue((new ProcessManager(filters: $filters))->start($sleep)->wait()->timedOut());
    }
}
