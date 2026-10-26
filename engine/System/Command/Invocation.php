<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\Config\Env;
use App\Engine\Filter\FilterEngine;
use App\Engine\Security\Secret;
use App\Engine\Support\Path;

/**
 * A Command or ShellCommand turned into exactly what proc_open() is given.
 *
 * Internal. The executor, which waits, and the process manager, which does
 * not, start processes the same way, and this is that one way: the executable
 * found, the working directory checked, secrets revealed, the environment
 * reduced, the defaults applied. Anything that goes wrong here goes wrong
 * before a process exists, and throws CommandException.
 *
 * The static helpers below are the other half both of them share: stopping a
 * process, reading its exit status, and the temporary files output goes
 * through when nobody is reading a pipe.
 */
final class Invocation
{
    /** A listener may shorten a command's timeout, never lengthen it. */
    public const TIMEOUT_FILTER = 'system.command.timeout';

    /** A listener may lower a command's output limit, never raise it. */
    public const MAX_OUTPUT_FILTER = 'system.command.max_output';

    public const SIGTERM = 15;

    public const SIGKILL = 9;

    /**
     * @param non-empty-list<string>  $argv
     * @param array<string, string>   $environment
     */
    private function __construct(
        public readonly array $argv,
        public readonly ?string $directory,
        public readonly array $environment,
        public readonly string $stdin,
        public readonly float $timeout,
        public readonly int $limit,
    ) {}

    /**
     * @param bool          $shell   whether a ShellCommand may run at all
     * @param string        $bash    the bash to run it with: a name on PATH or an absolute path
     * @param ?FilterEngine $filters where TIMEOUT_FILTER and MAX_OUTPUT_FILTER may narrow the limits
     *
     * @throws CommandException|CommandPolicyException
     */
    public static function prepare(
        Command|ShellCommand $command,
        float $defaultTimeout,
        int $defaultMaxOutput,
        ?CommandPolicy $policy = null,
        bool $shell = false,
        string $bash = ShellCommand::BASH,
        ?FilterEngine $filters = null,
    ): self {
        if ($command instanceof ShellCommand && !$shell) {
            throw CommandPolicyException::shellDisabled();
        }

        if (!\function_exists('proc_open')) {
            throw CommandException::processesDisabled();
        }

        $windows = \PHP_OS_FAMILY === 'Windows';
        $environment = self::environmentFor($command, $windows);

        if ($command instanceof ShellCommand) {
            $argv = [self::resolve($bash, $environment, $windows), '--noprofile', '--norc', '--'];

            if (!\is_file($command->script()) || !\is_readable($command->script())) {
                throw CommandException::scriptNotFound();
            }

            $argv[] = $command->script();
        } else {
            $argv = [self::resolve($command->executable(), $environment, $windows)];
        }

        $arguments = [];

        foreach ($command->arguments() as $argument) {
            $arguments[] = $argument instanceof Secret ? $argument->reveal() : $argument;
        }

        $argv = [...$argv, ...$arguments];
        $directory = $command->workingDirectory();

        if ($directory !== null && !\is_dir($directory)) {
            throw CommandException::workingDirectoryNotFound($directory);
        }

        $policy?->check(
            $command instanceof ShellCommand,
            $command instanceof ShellCommand ? $command->script() : $argv[0],
            $arguments,
            $directory,
            $command->environment() === null ? null : \array_keys($command->environment()),
        );

        $timeout = $command->timeout() ?? $defaultTimeout;
        $limit = $command->maxOutput() ?? $defaultMaxOutput;

        if ($filters !== null) {
            $timeout = self::narrowed($timeout, $filters->apply(self::TIMEOUT_FILTER, $timeout, $command));
            $limit = (int) self::narrowed($limit, $filters->apply(self::MAX_OUTPUT_FILTER, $limit, $command));
        }

        return new self($argv, $directory, $environment, $command->stdin() ?? '', $timeout, $limit);
    }

    /**
     * What a limit filter returned, if it is a smaller positive number; the
     * original otherwise. A filter can make a command stricter and nothing
     * else: a larger value, zero, or something that is not a number is
     * ignored, so no listener can lift a limit the application set.
     */
    private static function narrowed(int|float $original, mixed $filtered): int|float
    {
        if ((\is_int($filtered) || \is_float($filtered)) && \is_finite((float) $filtered) && $filtered > 0 && $filtered < $original) {
            return \is_int($original) ? \max(1, (int) $filtered) : (float) $filtered;
        }

        return $original;
    }

    /**
     * What an audit record may say about a command: the program's name, how
     * many arguments, which variables were set, where it ran. Never a value.
     * A path's last segment is kept only when it looks like a program name --
     * "/usr/bin/mysql -pS3cret" is a path as far as anything can tell.
     *
     * @return array{program: string, shell: bool, arguments: int, environment: ?string, working_directory: ?string}
     */
    public static function describe(Command|ShellCommand $command): array
    {
        $program = \basename(\str_replace('\\', '/', $command instanceof ShellCommand ? $command->script() : $command->executable()));

        return [
            'program' => \preg_match('/^[A-Za-z0-9_][A-Za-z0-9._+-]*$/D', $program) === 1 ? $program : '[path]',
            'shell' => $command instanceof ShellCommand,
            'arguments' => \count($command->arguments()),
            'environment' => $command->environment() === null ? null : \implode(',', \array_keys($command->environment())),
            'working_directory' => $command->workingDirectory(),
        ];
    }

    /**
     * A bash, by name or by absolute path, and nothing else: ShellCommand
     * passes bash's own options, which another shell would read differently.
     */
    public static function checkShellBinary(string $bash): void
    {
        $name = \strtolower((string) \preg_replace('/\.exe$/iD', '', \basename(\str_replace('\\', '/', $bash))));
        $isPath = \str_contains($bash, '/') || \str_contains($bash, '\\');

        if ($name !== ShellCommand::BASH || \str_contains($bash, "\0") || ($isPath && !Path::isAbsolute($bash)) || (!$isPath && $bash !== $name)) {
            throw CommandException::invalidShellBinary();
        }
    }

    /** The same rules a Command's own limits follow, for the defaults that stand in for them. */
    public static function checkDefaults(float $timeout, int $maxOutput): void
    {
        if (!\is_finite($timeout) || $timeout <= 0.0) {
            throw CommandException::invalidTimeout($timeout);
        }

        if ($maxOutput < 1) {
            throw CommandException::invalidOutputLimit($maxOutput);
        }
    }

    /**
     * Ask, wait, then insist, and return once the process is gone. On Windows
     * there is only insisting: proc_terminate() ends the process whatever
     * signal is named.
     *
     * @param resource $process
     *
     * @return array{running: bool, signaled: bool, exitcode: int} the status that saw it gone
     */
    public static function stop($process, float $grace): array
    {
        \proc_terminate($process, self::SIGTERM);

        $asked = \hrtime(true);
        $killed = false;

        while (($status = \proc_get_status($process))['running']) {
            if (!$killed && (\PHP_OS_FAMILY === 'Windows' || self::since($asked) >= $grace)) {
                \proc_terminate($process, self::SIGKILL);
                $killed = true;
            }

            \usleep(5_000);
        }

        return $status;
    }

    /**
     * The exit code, or null for a process a signal ended. Windows reports
     * crashes as NTSTATUS values far above 255, which say "it did not exit
     * normally" as clearly as a signal does.
     *
     * @param array{signaled: bool, exitcode: int} $status
     */
    public static function exitCodeOf(array $status): ?int
    {
        if ($status['signaled']) {
            return null;
        }

        $code = $status['exitcode'];

        return $code >= 0 && $code <= CommandResult::MAX_EXIT_CODE ? $code : null;
    }

    /** Seconds since an hrtime(true) reading. */
    public static function since(int|float $started): float
    {
        return (\hrtime(true) - $started) / 1e9;
    }

    /** At most $limit bytes from the start of a file, noting whether there was more. */
    public static function readFile(string $path, int $limit, bool &$truncated): string
    {
        $handle = @\fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $content = (string) \stream_get_contents($handle, $limit + 1);
        \fclose($handle);

        if (\strlen($content) > $limit) {
            $truncated = true;

            return \substr($content, 0, $limit);
        }

        return $content;
    }

    public static function temporaryFile(): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'cmd');

        if ($path === false) {
            throw CommandException::couldNotStart('no temporary file could be created for its input and output');
        }

        return $path;
    }

    /** @return array<string, string> */
    private static function environmentFor(Command|ShellCommand $command, bool $windows): array
    {
        $given = $command->environment();

        if ($given !== null) {
            $environment = [];

            foreach ($given as $name => $value) {
                $environment[$name] = $value instanceof Secret ? $value->reveal() : $value;
            }

            return $environment;
        }

        return self::inheritedEnvironment($windows);
    }

    /**
     * Where a bare program name would be found for a command with the default
     * environment, or null -- including for a name no Command would accept.
     */
    public static function locate(string $name): ?string
    {
        if (\preg_match('/^[A-Za-z0-9_][A-Za-z0-9._+-]*$/D', $name) !== 1) {
            return null;
        }

        $windows = \PHP_OS_FAMILY === 'Windows';

        return self::search($name, self::inheritedEnvironment($windows), $windows);
    }

    /** @return array<string, string> */
    private static function inheritedEnvironment(bool $windows): array
    {
        $names = $windows
            ? [...CommandExecutor::INHERITED_ENVIRONMENT, ...CommandExecutor::INHERITED_ON_WINDOWS]
            : CommandExecutor::INHERITED_ENVIRONMENT;

        $environment = [];

        foreach ($names as $name) {
            $value = Env::raw($name);

            if ($value !== null) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /** @param array<string, string> $environment */
    private static function resolve(string $executable, array $environment, bool $windows): string
    {
        if (\str_contains($executable, '/') || \str_contains($executable, '\\')) {
            $found = self::isRunnable($executable, $windows) ? $executable : throw CommandException::executableNotFound(null);
        } else {
            $found = self::search($executable, $environment, $windows)
                ?? throw CommandException::executableNotFound($executable);
        }

        if ($windows && self::isBatchFile($found)) {
            throw CommandException::batchFileRefused();
        }

        return $found;
    }

    private static function isBatchFile(string $path): bool
    {
        return \preg_match('/\.(bat|cmd)$/iD', $path) === 1;
    }

    /** @param array<string, string> $environment */
    private static function search(string $name, array $environment, bool $windows): ?string
    {
        $extensions = [''];

        if ($windows) {
            $pathext = self::variable($environment, 'PATHEXT', true) ?? '.COM;.EXE;.BAT;.CMD';
            $extensions = ['', ...\array_filter(\explode(';', $pathext), static fn(string $e): bool => $e !== '')];
        }

        foreach (\explode(\PATH_SEPARATOR, self::variable($environment, 'PATH', $windows) ?? '') as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $name . $extension;

                if (self::isRunnable($candidate, $windows)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * A variable from the environment the child will have. Names are
     * case-insensitive on Windows, where PATH is usually spelled Path.
     *
     * @param array<string, string> $environment
     */
    private static function variable(array $environment, string $name, bool $ignoreCase): ?string
    {
        foreach ($environment as $key => $value) {
            if ($ignoreCase ? \strcasecmp($key, $name) === 0 : $key === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * PHP on Windows does not call a .bat file executable. It still has to be
     * found, so that it is refused as a batch file rather than reported missing.
     */
    private static function isRunnable(string $path, bool $windows): bool
    {
        return \is_file($path) && (\is_executable($path) || ($windows && self::isBatchFile($path)));
    }
}
