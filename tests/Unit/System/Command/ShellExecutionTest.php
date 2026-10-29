<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\System\Command\CommandException;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\ShellCommand;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * bash, for real. Linux only: on Windows the bash on PATH is usually the WSL
 * launcher, which cannot read a Windows path, and shell execution is a
 * Linux-first feature.
 */
#[RequiresOperatingSystemFamily('Linux')]
final class ShellExecutionTest extends TestCase
{
    private string $directory;

    private CommandExecutor $executor;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp shell ' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        $this->executor = new CommandExecutor(shell: true);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    /** A script with no execute bit: bash reads it, the kernel never runs it. */
    private function script(string $body): string
    {
        $path = $this->directory . '/script ' . \bin2hex(\random_bytes(3)) . '.sh';
        \file_put_contents($path, "set -u\n" . $body . "\n");
        \chmod($path, 0o644);

        return $path;
    }

    public function test_a_script_runs_and_its_output_comes_back(): void
    {
        $result = $this->executor->run(ShellCommand::bash($this->script('echo "hello from bash"')));

        self::assertTrue($result->successful(), $result->stderr());
        self::assertSame("hello from bash\n", $result->stdout());
    }

    /**
     * The arguments are $1, $2, ... and never source. A script that quotes its
     * parameters receives each value as one word, whatever it holds.
     */
    public function test_arguments_reach_the_script_as_parameters_not_source(): void
    {
        $arguments = ['x; echo INJECTED', '$(echo INJECTED)', '`echo INJECTED`', 'a b', '', '*', "two\nlines", '-n'];

        $result = $this->executor->run(ShellCommand::bash(
            $this->script('printf "%s\0" "$@"'),
            $arguments,
        ));

        self::assertTrue($result->successful(), $result->stderr());
        self::assertSame($arguments, \explode("\0", \substr($result->stdout(), 0, -1)));
        self::assertStringNotContainsString("INJECTED\n", $result->stdout());
    }

    public function test_a_failing_script_returns_its_exit_code_and_stderr(): void
    {
        $result = $this->executor->run(ShellCommand::bash($this->script('echo "copying"; echo "disk full" >&2; exit 4')));

        self::assertSame(4, $result->exitCode());
        self::assertSame("copying\n", $result->stdout());
        self::assertSame("disk full\n", $result->stderr());
    }

    public function test_set_u_in_the_script_is_the_scripts_own_rule(): void
    {
        $result = $this->executor->run(ShellCommand::bash($this->script('echo "$UNSET_VARIABLE"')));

        self::assertTrue($result->failed());
        self::assertStringContainsString('UNSET_VARIABLE', $result->stderr());
    }

    public function test_a_pipeline_lives_in_the_script(): void
    {
        $result = $this->executor->run(ShellCommand::bash(
            $this->script('tr "a-z" "A-Z" | rev'),
            stdin: "bash\n",
        ));

        self::assertSame("HSAB\n", $result->stdout());
    }

    public function test_the_environment_and_working_directory_reach_the_script(): void
    {
        $result = $this->executor->run(ShellCommand::bash(
            $this->script('echo "$GREETING"; pwd'),
            workingDirectory: $this->directory,
            environment: ['GREETING' => 'hi there', 'PATH' => '/usr/bin:/bin'],
        ));

        self::assertSame("hi there\n" . $this->directory . "\n", $result->stdout(), $result->stderr());
    }

    public function test_a_script_that_runs_too_long_is_stopped(): void
    {
        $result = $this->executor->run(ShellCommand::bash($this->script('echo started; sleep 30'), timeout: 0.5));

        self::assertTrue($result->timedOut());
        self::assertSame("started\n", $result->stdout());
        self::assertLessThan(5.0, $result->duration());
    }

    public function test_a_missing_script_is_refused_without_repeating_its_path(): void
    {
        try {
            $this->executor->run(ShellCommand::bash($this->directory . '/missing -pS3cret.sh'));
            self::fail('no exception');
        } catch (CommandException $e) {
            self::assertStringContainsString('does not name a readable file', $e->getMessage());
            self::assertStringNotContainsString('S3cret', $e->getMessage());
        }
    }

    public function test_a_directory_is_not_a_script(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('does not name a readable file');

        $this->executor->run(ShellCommand::bash($this->directory));
    }

    /**
     * No startup file runs before the script. The executor's default
     * environment has no BASH_ENV, and bash is started with --noprofile --norc.
     */
    public function test_nothing_runs_before_the_script(): void
    {
        $result = $this->executor->run(ShellCommand::bash($this->script('echo "${BASH_ENV:-unset}"; shopt -q login_shell && echo login || echo plain')));

        self::assertSame("unset\nplain\n", $result->stdout(), $result->stderr());
    }
}
