<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Cron;

use App\Engine\Scheduler\SchedulerException;
use App\Engine\Security\Secret;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Cron\CronException;
use App\Engine\System\Cron\CronJob;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

final class CronJobTest extends TestCase
{
    private function job(Command $command, string $schedule = '*/5 * * * *', ?string $log = null): CronJob
    {
        return new CronJob('billing.invoice-check', $schedule, $command, $log);
    }

    public function test_the_line_is_the_schedule_then_every_part_quoted(): void
    {
        $job = $this->job(new Command('/usr/bin/php', ['console', 'billing:invoice-check'], workingDirectory: '/srv/app'));

        self::assertSame(
            "*/5 * * * * cd '/srv/app' && '/usr/bin/php' 'console' 'billing:invoice-check' > /dev/null 2>&1",
            $job->line(),
        );
    }

    public function test_output_is_appended_to_a_log_when_one_is_given(): void
    {
        $job = $this->job(new Command('/usr/bin/php', ['laika', 'schedule:run']), '* * * * *', '/srv/app/system/Logs/cron.log');

        self::assertSame(
            "* * * * * '/usr/bin/php' 'laika' 'schedule:run' >> '/srv/app/system/Logs/cron.log' 2>&1",
            $job->line(),
        );
    }

    /**
     * A crontab line is shell source. Each of these would end the quoting, run
     * a second command, or -- the % -- be turned into a line break by cron
     * before the shell even sees it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function quoting(): iterable
    {
        yield 'single quote' => ["it's", "'it'\\''s'"];
        yield 'separator' => ['a; rm -rf /', "'a; rm -rf /'"];
        yield 'substitution' => ['$(id)', "'$(id)'"];
        yield 'backticks' => ['`id`', "'`id`'"];
        yield 'percent, which cron reads first' => ['date +%Y-%m-%d', "'date +\\%Y-\\%m-\\%d'"];
        yield 'spaces' => ['March 2026', "'March 2026'"];
        yield 'empty' => ['', "''"];
    }

    #[DataProvider('quoting')]
    public function test_each_value_is_quoted_for_sh_and_for_cron(string $value, string $quoted): void
    {
        self::assertSame($quoted, CronJob::quote($value));
    }

    public function test_the_schedule_is_normalised_to_single_spaces(): void
    {
        self::assertSame('0 3 * * SUN', $this->job(new Command('/bin/true'), "  0  3\t* *   SUN ")->schedule());
        self::assertSame('@daily', $this->job(new Command('/bin/true'), '@daily')->schedule());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSchedules(): iterable
    {
        yield 'too few fields' => ['* * * *'];
        yield 'minute out of range' => ['60 * * * *'];
        yield 'reboot, which the scheduler grammar does not have' => ['@reboot'];
        yield 'a second line smuggled in' => ["* * * * *\n* * * * * /bin/evil"];
        yield 'a command in the schedule' => ['* * * * * /bin/evil'];
    }

    #[DataProvider('invalidSchedules')]
    public function test_a_schedule_the_scheduler_would_not_accept_is_refused(string $schedule): void
    {
        try {
            $this->job(new Command('/bin/true'), $schedule);
            self::fail('no exception');
        } catch (CronException $e) {
            self::assertStringContainsString('has an unusable schedule', $e->getMessage());
            self::assertInstanceOf(SchedulerException::class, $e->getPrevious());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'capitals' => ['Billing'];
        yield 'space' => ['billing check'];
        yield 'line break' => ["billing\nx"];
        yield 'too long' => [\str_repeat('a', 101)];
    }

    #[DataProvider('invalidIds')]
    public function test_an_invalid_id_is_refused(string $id): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('is invalid');

        new CronJob($id, '* * * * *', new Command('/bin/true'));
    }

    /** @return iterable<string, array{Command, string}> */
    public static function commandsCronCannotHonour(): iterable
    {
        yield 'environment' => [new Command('/bin/true', environment: ['A' => 'b']), 'environment'];
        yield 'stdin' => [new Command('/bin/true', stdin: 'x'), 'stdin'];
        yield 'timeout' => [new Command('/bin/true', timeout: 5.0), 'timeout'];
        yield 'output limit' => [new Command('/bin/true', maxOutput: 10), 'maxOutput'];
        yield 'bare name, which cron\'s PATH may not find' => [new Command('php', ['console']), 'absolute path'];
        yield 'a secret, into a plain-text file' => [new Command('/usr/bin/mysql', [new Secret('S3cret')]), 'Secret'];
        yield 'a line break in an argument' => [new Command('/bin/echo', ["a\n* * * * * /bin/evil"]), 'line break'];
        yield 'a line break in the directory' => [new Command('/bin/true', workingDirectory: "/tmp\n"), 'line break'];
        yield 'bytes that are not text' => [new Command('/bin/echo', ["\xFF"]), 'UTF-8'];
    }

    #[DataProvider('commandsCronCannotHonour')]
    public function test_a_command_cron_cannot_honour_is_refused(Command $command, string $reason): void
    {
        try {
            $this->job($command);
            self::fail('no exception');
        } catch (CronException $e) {
            self::assertStringContainsString('cannot be written to a crontab', $e->getMessage());
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    public function test_a_relative_log_is_refused(): void
    {
        $this->expectException(CronException::class);
        $this->expectExceptionMessage('unusable log path');

        $this->job(new Command('/bin/true'), log: 'system/Logs/cron.log');
    }

    /**
     * The line, run the way cron runs it: % unescaped by cron, the rest by a
     * shell. Every hostile argument reaches the program as itself.
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function test_the_line_means_what_the_job_says_when_a_shell_runs_it(): void
    {
        $directory = \sys_get_temp_dir() . '/lphp cron ' . \bin2hex(\random_bytes(4));
        \mkdir($directory);
        $log = $directory . "/it's.log";
        $arguments = ["it's", 'a; echo INJECTED', '$(echo INJECTED)', '`echo INJECTED`', '100%', '%d', '', 'a  b'];

        $job = new CronJob(
            'test.argv',
            '* * * * *',
            new Command(\PHP_BINARY, ['-n', '-r', 'echo json_encode([getcwd(), array_slice($argv, 1)]);', '--', ...$arguments], workingDirectory: $directory),
            $log,
        );

        $script = $directory . '/run.sh';
        $commandLine = \substr($job->line(), \strlen('* * * * * '));
        // cron's own step: \% is a literal %.
        \file_put_contents($script, \str_replace('\\%', '%', $commandLine) . "\n");

        try {
            $result = (new CommandExecutor(shell: true))->run(ShellCommand::bash($script));

            self::assertTrue($result->successful(), $result->stderr());
            self::assertSame([$directory, $arguments], \json_decode((string) \file_get_contents($log), true));
        } finally {
            foreach (\glob($directory . '/*') ?: [] as $file) {
                \unlink($file);
            }

            \rmdir($directory);
        }
    }
}
