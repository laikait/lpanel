<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Error\FrameworkException;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandResult;
use App\Engine\System\SystemException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CommandResultTest extends TestCase
{
    public function test_a_zero_exit_is_successful(): void
    {
        $result = new CommandResult(0, "done\n", '', 0.25);

        self::assertTrue($result->successful());
        self::assertFalse($result->failed());
        self::assertSame(0, $result->exitCode());
        self::assertSame("done\n", $result->stdout());
        self::assertSame('', $result->stderr());
        self::assertSame(0.25, $result->duration());
        self::assertFalse($result->timedOut());
    }

    public function test_a_non_zero_exit_failed_and_keeps_what_it_wrote(): void
    {
        $result = new CommandResult(2, 'partial', 'rsync: link_stat failed', 1.5);

        self::assertTrue($result->failed());
        self::assertSame(2, $result->exitCode());
        self::assertSame('partial', $result->stdout());
        self::assertSame('rsync: link_stat failed', $result->stderr());
    }

    /** Warnings on stderr are not failure; the exit code decides. */
    public function test_output_on_stderr_does_not_make_a_zero_exit_a_failure(): void
    {
        self::assertTrue((new CommandResult(0, '', 'warning: deprecated flag'))->successful());
    }

    public function test_a_killed_process_has_no_exit_code_and_failed(): void
    {
        $result = new CommandResult(null);

        self::assertNull($result->exitCode());
        self::assertTrue($result->failed());
        self::assertFalse($result->timedOut());
    }

    public function test_a_timeout_failed_and_keeps_the_output_so_far(): void
    {
        $result = new CommandResult(null, 'copied 3 of 9', '', 30.0, timedOut: true);

        self::assertTrue($result->timedOut());
        self::assertTrue($result->failed());
        self::assertNull($result->exitCode());
        self::assertSame('copied 3 of 9', $result->stdout());
    }

    public function test_the_defaults_describe_an_instant_silent_run(): void
    {
        $result = new CommandResult(0);

        self::assertSame('', $result->stdout());
        self::assertSame('', $result->stderr());
        self::assertSame(0.0, $result->duration());
        self::assertFalse($result->timedOut());
        self::assertFalse($result->truncated());
    }

    public function test_truncated_output_is_reported_and_is_not_failure(): void
    {
        $result = new CommandResult(0, 'first megabyte', '', 2.0, truncated: true);

        self::assertTrue($result->truncated());
        self::assertTrue($result->successful());
    }

    public function test_the_highest_exit_code_is_allowed(): void
    {
        self::assertSame(255, (new CommandResult(255))->exitCode());
    }

    /** @return iterable<string, array{int}> */
    public static function impossibleExitCodes(): iterable
    {
        yield 'negative, as proc_close reports an unknown status' => [-1];
        yield 'past a byte' => [256];
    }

    #[DataProvider('impossibleExitCodes')]
    public function test_an_exit_code_no_process_can_return_is_refused(int $exitCode): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage(\sprintf('Exit code %d is not one a process can return', $exitCode));

        new CommandResult($exitCode);
    }

    /** @return iterable<string, array{float}> */
    public static function impossibleDurations(): iterable
    {
        yield 'negative' => [-0.001];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('impossibleDurations')]
    public function test_a_duration_that_cannot_be_measured_is_refused(float $seconds): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The duration is a finite number of seconds');

        new CommandResult(0, seconds: $seconds);
    }

    public function test_a_timeout_with_an_exit_code_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('timed out cannot also have exited with 0');

        new CommandResult(0, timedOut: true);
    }

    public function test_the_result_cannot_be_changed_after_construction(): void
    {
        foreach ((new \ReflectionClass(CommandResult::class))->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is writable');
        }
    }

    public function test_its_exception_is_caught_as_a_system_and_a_framework_refusal(): void
    {
        try {
            new CommandResult(-1);
            self::fail('no exception');
        } catch (SystemException $e) {
            self::assertInstanceOf(CommandException::class, $e);
            self::assertInstanceOf(FrameworkException::class, $e);
            self::assertTrue($e->disclosesMessage());
        }
    }

    public function test_the_system_exception_is_only_a_type_to_catch(): void
    {
        self::assertTrue((new \ReflectionClass(SystemException::class))->isAbstract());
    }
}
