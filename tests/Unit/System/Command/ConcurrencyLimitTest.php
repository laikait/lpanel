<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Command\ConcurrencyLimit;
use App\Engine\System\Process\ProcessManager;
use App\Tests\Support\TestCase;

final class ConcurrencyLimitTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-slots-' . \bin2hex(\random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->directory);

        parent::tearDown();
    }

    public function test_slots_run_out_and_come_back(): void
    {
        $limit = new ConcurrencyLimit($this->directory, 2);

        $first = $limit->acquire();
        $second = $limit->acquire();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNull($limit->acquire(), 'a third slot out of two');

        $first->release();

        self::assertNotNull($limit->acquire(), 'a released slot was not given out again');
    }

    public function test_a_slot_dropped_without_release_is_returned(): void
    {
        $limit = new ConcurrencyLimit($this->directory, 1);
        $slot = $limit->acquire();
        self::assertNotNull($slot);

        unset($slot);

        self::assertNotNull($limit->acquire());
    }

    /**
     * The property the design is for: another PHP process holding a slot is
     * excluded here too, and its death returns the slot without anybody
     * cleaning up.
     */
    public function test_slots_are_shared_with_other_processes_and_freed_when_they_die(): void
    {
        $limit = new ConcurrencyLimit($this->directory, 1);
        // Creates the directory and slot file; the slot is released at once.
        self::assertNotNull($limit->acquire(), 'the directory could not be prepared');

        $holder = (new ProcessManager())->start(new Command(\PHP_BINARY, [
            '-n',
            '-r',
            '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); sleep(30);',
            $this->directory . '/slot-0.lock',
        ]));

        // Until the other process has taken the lock, the slot is still free.
        $deadline = \hrtime(true) + 5_000_000_000;

        while (self::free($limit) && \hrtime(true) < $deadline) {
            \usleep(20_000);
        }

        self::assertFalse(self::free($limit), 'a slot held by another process was handed out');

        $holder->terminate();

        // The kernel drops a dead process's locks as it tears the process down,
        // which on Windows can land a moment after the exit is reported.
        $deadline = \hrtime(true) + 5_000_000_000;

        while (!self::free($limit) && \hrtime(true) < $deadline) {
            \usleep(20_000);
        }

        self::assertTrue(self::free($limit), 'the slot of a killed process was not returned');
    }

    /**
     * Whether a slot can be had right now; the one taken to find out is given straight back.
     *
     * @phpstan-impure
     */
    private static function free(ConcurrencyLimit $limit): bool
    {
        return $limit->acquire() !== null;
    }

    public function test_the_executor_refuses_when_every_slot_is_taken_and_releases_after_a_run(): void
    {
        $limit = new ConcurrencyLimit($this->directory, 1);
        $executor = new CommandExecutor(concurrency: $limit);

        self::assertSame('1', $executor->run(new Command(\PHP_BINARY, ['-n', '-r', 'echo 1;']))->stdout());
        self::assertSame('2', $executor->run(new Command(\PHP_BINARY, ['-n', '-r', 'echo 2;']))->stdout(), 'the first run kept its slot');

        $held = $limit->acquire();
        self::assertNotNull($held);

        $this->expectException(CommandPolicyException::class);
        $this->expectExceptionMessage('All 1 concurrency slots are in use');

        $executor->run(new Command(\PHP_BINARY, ['-v']));
    }

    public function test_a_started_process_holds_its_slot_until_it_ends(): void
    {
        $limit = new ConcurrencyLimit($this->directory, 1);
        $processes = new ProcessManager(concurrency: $limit);

        $running = $processes->start(new Command(\PHP_BINARY, ['-n', '-r', 'sleep(30);']));

        try {
            $processes->start(new Command(\PHP_BINARY, ['-v']));
            self::fail('a second process started with one slot');
        } catch (CommandPolicyException $e) {
            self::assertStringContainsString('concurrency slots are in use', $e->getMessage());
        }

        $running->terminate();

        self::assertSame(0, $processes->start(new Command(\PHP_BINARY, ['-n', '-r', 'exit(0);']))->wait()->exitCode());
    }

    public function test_a_limit_below_one_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('A concurrency limit of 0 is not a limit');

        new ConcurrencyLimit($this->directory, 0);
    }
}
