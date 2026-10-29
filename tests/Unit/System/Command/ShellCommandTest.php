<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Security\Secret;
use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\ShellCommand;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The model only; ShellExecutionTest runs bash for real on Linux. */
final class ShellCommandTest extends TestCase
{
    public function test_bash_keeps_the_script_its_arguments_and_limits(): void
    {
        $password = new Secret('S3cret');
        $command = ShellCommand::bash(
            '/srv/app/scripts/backup.sh',
            ['db main', $password],
            workingDirectory: '/srv/app',
            environment: ['TARGET' => '/backups'],
            timeout: 900.0,
            stdin: 'input',
            maxOutput: 4096,
        );

        self::assertSame('bash', $command->shell());
        self::assertSame('/srv/app/scripts/backup.sh', $command->script());
        self::assertSame(['db main', $password], $command->arguments());
        self::assertSame('/srv/app', $command->workingDirectory());
        self::assertSame(['TARGET' => '/backups'], $command->environment());
        self::assertSame(900.0, $command->timeout());
        self::assertSame('input', $command->stdin());
        self::assertSame(4096, $command->maxOutput());
    }

    public function test_the_limits_are_left_to_the_executor_by_default(): void
    {
        $command = ShellCommand::bash('/srv/app/run.sh');

        self::assertSame([], $command->arguments());
        self::assertNull($command->environment());
        self::assertNull($command->timeout());
        self::assertNull($command->maxOutput());
    }

    /** @return iterable<string, array{string}> */
    public static function scriptsThatAreNotPaths(): iterable
    {
        yield 'bare name bash would search for' => ['backup.sh'];
        yield 'relative' => ['scripts/backup.sh'];
        yield 'dot relative' => ['./backup.sh'];
        yield 'inline source' => ['tar czf out.tgz /srv'];
        yield 'empty' => [''];
        yield 'nul byte' => ["/srv/a.sh\0"];
    }

    #[DataProvider('scriptsThatAreNotPaths')]
    public function test_a_script_is_an_absolute_path_and_nothing_else(string $script): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('A script is an absolute path to a file');

        ShellCommand::bash($script);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedEnvironment(): iterable
    {
        yield 'BASH_ENV, sourced before a non-interactive script' => ['BASH_ENV'];
        yield 'ENV, sourced by sh-compatible startup' => ['ENV'];
        yield 'SHELLOPTS' => ['SHELLOPTS'];
        yield 'BASHOPTS' => ['BASHOPTS'];
        yield 'PS4, expanded under xtrace' => ['PS4'];
        yield 'BASH_XTRACEFD' => ['BASH_XTRACEFD'];
        yield 'an exported function' => ['BASH_FUNC_ls%%'];
    }

    #[DataProvider('refusedEnvironment')]
    public function test_variables_bash_acts_on_before_the_script_are_refused(string $name): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is refused for a shell command');

        ShellCommand::bash('/srv/app/run.sh', environment: [$name => '() { id; }']);
    }

    public function test_arguments_follow_the_command_rules(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('Argument 1 contains a NUL byte');

        ShellCommand::bash('/srv/app/run.sh', ["a\0b"]);
    }

    public function test_limits_follow_the_command_rules(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a timeout');

        ShellCommand::bash('/srv/app/run.sh', timeout: 0.0);
    }

    /**
     * Shell mode stays explicit in both directions: nothing but bash() makes
     * one, and nothing makes one from source text.
     */
    public function test_the_only_way_in_is_a_script_file(): void
    {
        $class = new \ReflectionClass(ShellCommand::class);

        self::assertTrue($class->getConstructor()?->isPrivate());

        $factories = [];

        foreach ($class->getMethods(\ReflectionMethod::IS_STATIC | \ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->isPublic()) {
                $factories[] = $method->getName();
            }
        }

        self::assertSame(['bash'], $factories);
    }
}
