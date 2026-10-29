<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Command;

use App\Engine\Security\Secret;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\System\Command\CommandPolicy;
use App\Engine\System\Command\CommandPolicyException;
use App\Engine\System\Command\Invocation;
use App\Engine\System\Command\ShellCommand;
use App\Engine\System\Process\ProcessManager;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

final class CommandPolicyTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        if ($this->directory === '') {
            return;
        }

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);
    }

    private function stdoutOf(CommandPolicy $policy, Command|ShellCommand $command): string
    {
        return (new CommandExecutor(policy: $policy, shell: true))->run($command)->stdout();
    }

    private function refused(CommandPolicy $policy, Command|ShellCommand $command, string $message): void
    {
        try {
            (new CommandExecutor(policy: $policy, shell: true))->run($command);
            self::fail('the policy did not refuse the command');
        } catch (CommandPolicyException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_without_a_policy_nothing_changes(): void
    {
        self::assertSame('ok', (new CommandExecutor())->run(new Command(\PHP_BINARY, ['-n', '-r', 'echo "ok";']))->stdout());
    }

    public function test_an_empty_allowlist_runs_nothing(): void
    {
        $this->refused(CommandPolicy::allowlist(), new Command(\PHP_BINARY, ['-v']), 'The executable is not on the command allowlist');
    }

    public function test_an_allowed_executable_runs(): void
    {
        self::assertSame('ok', $this->stdoutOf(
            CommandPolicy::allowlist()->allowExecutable(\PHP_BINARY),
            new Command(\PHP_BINARY, ['-n', '-r', 'echo "ok";']),
        ));
    }

    /** The same policy holds for a process started in the background. */
    public function test_the_process_manager_is_held_to_the_same_policy(): void
    {
        $this->expectException(CommandPolicyException::class);

        (new ProcessManager(policy: CommandPolicy::allowlist()))->start(new Command(\PHP_BINARY, ['-v']));
    }

    public function test_every_argument_must_match_the_pattern_whole(): void
    {
        $policy = CommandPolicy::allowlist()->allowExecutable(\PHP_BINARY, arguments: '/-[a-z]/');

        self::assertStringContainsString('PHP', $this->stdoutOf($policy, new Command(\PHP_BINARY, ['-n', '-v'])));

        // "-vr" contains a match, and is not one.
        $this->refused($policy, new Command(\PHP_BINARY, ['-n', '-vr']), 'Argument 2 does not match');
    }

    /** Nothing about the argument reaches the message: it may be the password. */
    public function test_a_refused_argument_is_named_by_position_only(): void
    {
        try {
            (new CommandExecutor(policy: CommandPolicy::allowlist()->allowExecutable(\PHP_BINARY, arguments: '/-[a-z]/')))
                ->run(new Command(\PHP_BINARY, ['-n', new Secret('--password=S3cret')]));
            self::fail('no exception');
        } catch (CommandPolicyException $e) {
            self::assertSame('Argument 2 does not match the pattern the command allowlist permits for this program.', $e->getMessage());
        }
    }

    public function test_working_directories_are_exact(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-policy-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory . '-child', 0o777, true);
        \mkdir($this->directory);

        $policy = CommandPolicy::allowlist()->allowExecutable(\PHP_BINARY, workingDirectories: [$this->directory]);

        self::assertSame('ok', $this->stdoutOf($policy, new Command(\PHP_BINARY, ['-n', '-r', 'echo "ok";'], workingDirectory: $this->directory)));

        try {
            $this->refused($policy, new Command(\PHP_BINARY, ['-v'], workingDirectory: $this->directory . '-child'), 'The working directory is not one');
            $this->refused($policy, new Command(\PHP_BINARY, ['-v']), 'The working directory is not one');
        } finally {
            \rmdir($this->directory . '-child');
        }
    }

    public function test_only_listed_environment_names_may_be_set(): void
    {
        $policy = CommandPolicy::allowlist()->allowExecutable(\PHP_BINARY, environment: ['GREETING']);

        self::assertSame('hi', $this->stdoutOf($policy, new Command(\PHP_BINARY, ['-n', '-r', 'echo getenv("GREETING");'], environment: ['GREETING' => 'hi'])));
        self::assertSame('ok', $this->stdoutOf($policy, new Command(\PHP_BINARY, ['-n', '-r', 'echo "ok";'])), 'the default environment is always allowed');

        $this->refused(
            $policy,
            new Command(\PHP_BINARY, ['-v'], environment: ['GREETING' => 'hi', 'LD_PRELOAD' => '/tmp/evil.so']),
            'Environment variable LD_PRELOAD is not one',
        );
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_name_that_starts_like_an_allowed_program_is_not_it(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-policy-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);

        foreach (['tool', 'tool-evil'] as $name) {
            \file_put_contents($this->directory . '/' . $name, "#!/bin/sh\necho {$name}\n");
            \chmod($this->directory . '/' . $name, 0o755);
        }

        $policy = CommandPolicy::allowlist()->allowExecutable($this->directory . '/tool');

        self::assertSame("tool\n", $this->stdoutOf($policy, new Command($this->directory . '/tool')));
        $this->refused($policy, new Command($this->directory . '/tool-evil'), 'not on the command allowlist');
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_links_are_judged_by_where_they_lead(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-policy-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        \file_put_contents($this->directory . '/allowed', "#!/bin/sh\necho allowed\n");
        \file_put_contents($this->directory . '/other', "#!/bin/sh\necho other\n");
        \chmod($this->directory . '/allowed', 0o755);
        \chmod($this->directory . '/other', 0o755);
        \symlink($this->directory . '/allowed', $this->directory . '/alias');
        \symlink($this->directory . '/other', $this->directory . '/allowed-looking');

        $policy = CommandPolicy::allowlist()->allowExecutable($this->directory . '/allowed');

        self::assertSame("allowed\n", $this->stdoutOf($policy, new Command($this->directory . '/alias')));
        $this->refused($policy, new Command($this->directory . '/allowed-looking'), 'once symbolic links are resolved');
    }

    /** Allowing bash would allow every script on the machine; scripts are allowed one by one. */
    #[RequiresOperatingSystemFamily('Linux')]
    public function test_a_shell_command_needs_its_script_allowed_and_bash_does_not_count(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-policy-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
        \file_put_contents($this->directory . '/backup.sh', 'echo "backing up $1"');
        \file_put_contents($this->directory . '/wipe.sh', 'echo wiping');

        $bash = Invocation::locate('bash');
        self::assertNotNull($bash, 'bash is not on the PATH');
        $policy = CommandPolicy::allowlist()
            ->allowExecutable($bash)
            ->allowScript($this->directory . '/backup.sh', arguments: '/[a-z]+/');

        self::assertSame("backing up db\n", $this->stdoutOf($policy, ShellCommand::bash($this->directory . '/backup.sh', ['db'])));
        $this->refused($policy, ShellCommand::bash($this->directory . '/wipe.sh'), 'The script is not on the command allowlist');
        $this->refused($policy, ShellCommand::bash($this->directory . '/backup.sh', ['db; rm']), 'Argument 1 does not match');
    }

    public function test_a_rule_with_a_relative_path_or_a_broken_pattern_is_refused(): void
    {
        foreach ([
            static fn() => CommandPolicy::allowlist()->allowExecutable('bin/tool'),
            static fn() => CommandPolicy::allowlist()->allowExecutable('/usr/bin/tool', arguments: '/[unclosed/'),
            static fn() => CommandPolicy::allowlist()->allowExecutable('/usr/bin/tool', workingDirectories: ['relative']),
        ] as $rule) {
            try {
                $rule();
                self::fail('an unusable rule was accepted');
            } catch (CommandPolicyException $e) {
                self::assertStringContainsString('A command allowlist rule is unusable', $e->getMessage());
            }
        }
    }
}
