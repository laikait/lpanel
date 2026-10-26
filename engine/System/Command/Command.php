<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\Security\Secret;
use App\Engine\Support\Path;

/**
 * One program to run, with its arguments kept apart from it.
 *
 *     new Command('systemctl', ['restart', 'nginx']);
 *
 *     new Command('/usr/bin/rsync', ['-a', $source, $target], timeout: 600.0);
 *
 *     new Command('mysqldump', ['--single-transaction', $database],
 *         environment: ['MYSQL_PWD' => $password]);     // $password is a Secret
 *
 * **There is no shell anywhere in this.** The executable and each argument reach
 * the process as separate strings, so `$target` may hold spaces, quotes, `;` or
 * `$(...)` and it is still one argument that means exactly what it says. That is the whole defence against command
 * injection, and it is why there is no constructor taking a command line, no
 * `shell: true` flag, and no method that joins the parts into a string to run.
 *
 * **A shell is not an executable here.** `new Command('sh', ['-c', $line])` would
 * put the shell back by the side door, so the shells in SHELLS are refused by
 * name, with or without a path or `.exe`. Running a script through bash is
 * ShellCommand, which makes the choice visible at the call site. This is a
 * guard against the obvious mistake, not a sandbox: interpreters and wrappers
 * (`env`, `php -r`, `python -c`) can run code too, and which programs may run
 * at all is what an allowlist decides.
 *
 * It does not defend against an argument that is itself an option: a user-given
 * `--delete` passed to rsync is still an option to rsync. Put `--` before
 * positional values the caller did not write.
 *
 * **The executable is a bare name or an absolute path.** A bare name is looked
 * up on PATH by the executor. A relative path is refused, because it would be
 * resolved against whatever directory PHP happens to be in -- a different one
 * under a web server, the console and a worker.
 *
 * **Null means "the executor decides"** for the working directory, the
 * environment, the timeout and the output limit, so that those defaults are
 * configured once rather than repeated at every call. There is no way to ask
 * for no timeout or unlimited output.
 *
 * **Secrets stay Secrets.** An argument or an environment value may be a
 * Secret; this class keeps it wrapped, and only the executor reveals it, at the
 * moment the process starts. Nothing this class throws quotes a value it was
 * given.
 *
 * Nothing here touches the filesystem: whether the executable or the working
 * directory exists is known only when the command runs, and checking earlier
 * would be checking a different moment.
 */
final class Command
{
    /** Programs whose job is to read their arguments as shell source. */
    public const SHELLS = [
        'sh', 'bash', 'dash', 'ash', 'ksh', 'mksh', 'zsh', 'csh', 'tcsh', 'fish', 'busybox',
        'cmd', 'powershell', 'pwsh',
    ];

    /** A program name found on PATH. */
    private const EXECUTABLE_NAME = '/^[A-Za-z0-9_][A-Za-z0-9._+-]*$/D';

    private const ENVIRONMENT_NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var list<string|Secret> */
    private readonly array $arguments;

    /** @var array<string, string|Secret>|null */
    private readonly ?array $environment;

    /**
     * @param list<string|Secret>                $arguments        each one reaches the process as it is
     * @param ?string                            $workingDirectory absolute; null for the executor's
     * @param array<string, string|Secret>|null  $environment      the process's whole environment; null for the executor's
     * @param ?float                             $timeout          seconds, greater than zero; null for the executor's
     * @param ?string                            $stdin            bytes written to the process's input, then closed
     * @param ?int                               $maxOutput        bytes kept from each of stdout and stderr; null for the executor's
     */
    public function __construct(
        private readonly string $executable,
        array $arguments = [],
        private readonly ?string $workingDirectory = null,
        ?array $environment = null,
        private readonly ?float $timeout = null,
        private readonly ?string $stdin = null,
        private readonly ?int $maxOutput = null,
    ) {
        self::checkExecutable($executable);
        self::checkArguments($arguments);

        if ($workingDirectory !== null) {
            self::checkWorkingDirectory($workingDirectory);
        }

        if ($environment !== null) {
            self::checkEnvironment($environment);
        }

        $this->arguments = $arguments;
        $this->environment = $environment;

        if ($timeout !== null && (!\is_finite($timeout) || $timeout <= 0.0)) {
            throw CommandException::invalidTimeout($timeout);
        }

        if ($maxOutput !== null && $maxOutput < 1) {
            throw CommandException::invalidOutputLimit($maxOutput);
        }
    }

    public function executable(): string
    {
        return $this->executable;
    }

    /** @return list<string|Secret> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function workingDirectory(): ?string
    {
        return $this->workingDirectory;
    }

    /** @return array<string, string|Secret>|null */
    public function environment(): ?array
    {
        return $this->environment;
    }

    public function timeout(): ?float
    {
        return $this->timeout;
    }

    public function stdin(): ?string
    {
        return $this->stdin;
    }

    public function maxOutput(): ?int
    {
        return $this->maxOutput;
    }

    private static function checkExecutable(string $executable): void
    {
        if ($executable === '') {
            throw CommandException::emptyExecutable();
        }

        if (\str_contains($executable, "\0")) {
            throw CommandException::nulByte('The executable');
        }

        // A path: it may contain spaces ("C:\Program Files\..."), but it may not
        // depend on the current directory.
        if (\str_contains($executable, '/') || \str_contains($executable, '\\')) {
            if (!Path::isAbsolute($executable)) {
                throw CommandException::relativeExecutablePath();
            }
        } elseif (\preg_match('/\s/', $executable) === 1) {
            throw CommandException::executableWithArguments((string) \preg_replace('/\s.*$/s', '', \ltrim($executable)));
        } elseif (\preg_match(self::EXECUTABLE_NAME, $executable) !== 1) {
            throw CommandException::invalidExecutableName();
        }

        if (self::isShell($executable)) {
            throw CommandException::shellAsExecutable();
        }
    }

    /** "/bin/bash", "bash" and "C:\Windows\System32\cmd.exe" are all shells. */
    private static function isShell(string $executable): bool
    {
        $name = \strtolower((string) \preg_replace('/\.exe$/iD', '', \basename(\str_replace('\\', '/', $executable))));

        return \in_array($name, self::SHELLS, true);
    }

    /**
     * The declared type is a promise PHP does not keep for array elements, so
     * each one is checked.
     *
     * @param list<string|Secret> $arguments
     */
    private static function checkArguments(array $arguments): void
    {
        if (!\array_is_list($arguments)) {
            throw CommandException::argumentsNotAList();
        }

        foreach ($arguments as $index => $argument) {
            if (!\is_string($argument) && !$argument instanceof Secret) {
                throw CommandException::invalidArgument($index + 1, \get_debug_type($argument));
            }

            if (\str_contains(\is_string($argument) ? $argument : $argument->reveal(), "\0")) {
                throw CommandException::nulByte(\sprintf('Argument %d', $index + 1));
            }
        }
    }

    private static function checkWorkingDirectory(string $directory): void
    {
        if ($directory === '') {
            throw CommandException::emptyWorkingDirectory();
        }

        if (\str_contains($directory, "\0")) {
            throw CommandException::nulByte('The working directory');
        }

        if (!Path::isAbsolute($directory)) {
            throw CommandException::relativeWorkingDirectory($directory);
        }
    }

    /** @param array<string, string|Secret> $environment */
    private static function checkEnvironment(array $environment): void
    {
        foreach ($environment as $name => $value) {
            $name = (string) $name;

            if (\preg_match(self::ENVIRONMENT_NAME, $name) !== 1) {
                // ['MYSQL_PWD=s3cret' => …] is the likely mistake; the name ends at '='.
                throw CommandException::invalidEnvironmentName(\explode('=', $name, 2)[0]);
            }

            if (!\is_string($value) && !$value instanceof Secret) {
                throw CommandException::invalidEnvironmentValue($name, \get_debug_type($value));
            }

            if (\str_contains(\is_string($value) ? $value : $value->reveal(), "\0")) {
                throw CommandException::nulByte(\sprintf('Environment variable %s', $name));
            }
        }
    }
}
