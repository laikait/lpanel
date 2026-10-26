<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\System\SystemException;

/**
 * A command could not be run, or what came back from running one did not make
 * sense.
 *
 * **Thrown only when nothing ran.** A command that cannot be run as written, an
 * executable that is not there, a host that forbids processes: in each of these
 * no process exists and there is no output to keep. A process that ran and
 * failed, or ran too long, is a CommandResult -- its output is the explanation,
 * and an exception would lose it.
 *
 * **No message quotes a value the caller passed as an argument or an
 * environment variable**, and the executable only up to its first space. The
 * classic mistake this class exists to catch is `new Command('mysql -u root
 * -pS3cret')`, and repeating that string in an exception would put the password
 * into every log the exception reaches. Positions and names are enough to find
 * the line.
 *
 * Subclasses name the cases worth catching on their own: CommandNotFoundException
 * (the program or script is not there), and -- only from CommandResult::orFail(),
 * for callers who want a failure as an exception -- CommandFailedException and
 * CommandTimeoutException. Allowlist refusals are CommandPolicyException, which
 * is not one of these: a refusal is a security event, not a broken command.
 */
class CommandException extends SystemException
{
    public static function emptyExecutable(): self
    {
        return new self('A command needs an executable: a name found on PATH, such as "rsync", or an absolute path.');
    }

    public static function executableWithArguments(string $firstWord): self
    {
        return new self(\sprintf(
            'The executable "%s…" contains whitespace. Pass the executable alone and each argument '
            . 'separately: new Command(\'%1$s\', [\'…\']). Nothing here is split on spaces or run through a shell.',
            $firstWord,
        ));
    }

    public static function invalidExecutableName(): self
    {
        return new self(
            'The executable is not a program name. A name found on PATH is letters, digits, dot, dash, plus '
            . 'and underscore, and does not start with a dash; shell syntax such as ; | & $ or quotes has no '
            . 'meaning here. Pass an absolute path for anything else.',
        );
    }

    public static function shellAsExecutable(): self
    {
        return new self(
            'The executable is a shell. A shell reads its arguments as source, which undoes keeping them apart. '
            . 'Run the program directly, or run a script file with ShellCommand::bash(), which passes '
            . 'arguments as $1, $2, ... rather than as source.',
        );
    }

    public static function invalidConcurrency(int $slots): self
    {
        return new self(\sprintf('A concurrency limit of %d is not a limit. It is at least 1.', $slots));
    }

    public static function invalidShellBinary(): self
    {
        return new self(
            'The shell binary is not a bash. It is "bash", found on PATH, or an absolute path to a file named bash; '
            . 'ShellCommand passes bash\'s own options, which another shell would read differently.',
        );
    }

    public static function scriptNotFound(): CommandNotFoundException
    {
        return new CommandNotFoundException('The script path does not name a readable file. Check that it exists and is a file.');
    }

    public static function scriptNotAPath(): self
    {
        return new self(
            'A script is an absolute path to a file. bash would look a bare name up on PATH and in the current '
            . 'directory, so the script that ran would depend on where it was run from.',
        );
    }

    public static function shellEnvironmentRefused(string $name): self
    {
        return new self(\sprintf(
            'Environment variable %s is refused for a shell command: bash reads it to run code or change '
            . 'behaviour before the script starts.',
            $name,
        ));
    }

    public static function relativeExecutablePath(): self
    {
        return new self(
            'The executable is a relative path, which would be resolved against whatever directory PHP '
            . 'happens to be running in -- not the same under a web server, the console and a worker. '
            . 'Use an absolute path, or a bare name found on PATH.',
        );
    }

    public static function nulByte(string $what): self
    {
        return new self(\sprintf(
            '%s contains a NUL byte. The operating system ends a string there, so the process would '
            . 'receive something shorter than what was passed.',
            $what,
        ));
    }

    public static function argumentsNotAList(): self
    {
        return new self('A command\'s arguments are a list, in order. Keys would be ignored, so they are refused.');
    }

    public static function invalidArgument(int $position, string $type): self
    {
        return new self(\sprintf(
            'Argument %d is a %s. An argument is a string, or a Secret for a value that must not be logged.',
            $position,
            $type,
        ));
    }

    public static function relativeWorkingDirectory(string $directory): self
    {
        return new self(\sprintf(
            'The working directory "%s" is not absolute. A relative one depends on where PHP happens to be running.',
            $directory,
        ));
    }

    public static function emptyWorkingDirectory(): self
    {
        return new self('The working directory is empty. Pass an absolute path, or null to leave it to the executor.');
    }

    public static function invalidEnvironmentName(string $name): self
    {
        return new self(\sprintf(
            'Environment variable name "%s" is invalid. A name is letters, digits and underscore, and does not start with a digit.',
            $name,
        ));
    }

    public static function invalidEnvironmentValue(string $name, string $type): self
    {
        return new self(\sprintf(
            'Environment variable %s is a %s. A value is a string, or a Secret for one that must not be logged.',
            $name,
            $type,
        ));
    }

    public static function invalidTimeout(float $seconds): self
    {
        return new self(\sprintf(
            'A timeout of %s seconds is not a timeout. It is a finite number of seconds greater than zero; '
            . 'null leaves it to the executor, and there is no way to run without one.',
            \var_export($seconds, true),
        ));
    }

    /**
     * A bare name is quoted: the Command constructor has already refused one
     * with whitespace or shell syntax in it. A path is not, because a path may
     * contain spaces and "/usr/bin/mysql -pS3cret" is a path as far as anything
     * can tell.
     */
    public static function executableNotFound(?string $bareName): CommandNotFoundException
    {
        if ($bareName !== null) {
            return new CommandNotFoundException(\sprintf(
                'No executable named "%s" was found on the PATH the command runs with. Install it, pass '
                . 'an absolute path, or give the command an environment whose PATH includes its directory.',
                $bareName,
            ));
        }

        return new CommandNotFoundException(
            'The executable path does not name a file this process may execute. Check that it exists, is a '
            . 'file rather than a directory, and has execute permission.',
        );
    }

    public static function batchFileRefused(): self
    {
        return new self(
            'The executable is a Windows batch file. Windows runs .bat and .cmd files through cmd.exe, which '
            . 'interprets the arguments as shell syntax -- the very thing a Command exists to avoid. Run the '
            . 'program the script calls directly, or use explicit shell execution when it exists.',
        );
    }

    public static function workingDirectoryNotFound(string $directory): self
    {
        return new self(\sprintf('The working directory "%s" does not exist or is not a directory.', $directory));
    }

    public static function processesDisabled(): self
    {
        return new self(
            'proc_open() is not available in this PHP, so no command can run. Shared hosts often list it in '
            . 'disable_functions; it has to be enabled in php.ini for system operations to work.',
        );
    }

    public static function couldNotStart(string $why): self
    {
        return new self(\sprintf('The command could not be started: %s.', $why));
    }

    public static function invalidOutputLimit(int $bytes): self
    {
        return new self(\sprintf(
            'An output limit of %d bytes is not a limit. It is at least 1; null leaves it to the executor.',
            $bytes,
        ));
    }

    public static function exitCodeOutOfRange(int $exitCode): self
    {
        return new self(\sprintf(
            'Exit code %d is not one a process can return. An exit code is 0 to %d; a process that '
            . 'was killed or timed out has none, which is null.',
            $exitCode,
            CommandResult::MAX_EXIT_CODE,
        ));
    }

    public static function invalidDuration(float $seconds): self
    {
        return new self(\sprintf(
            'A command cannot take %s seconds. The duration is a finite number of seconds, zero or more.',
            \var_export($seconds, true),
        ));
    }

    public static function timedOutWithExitCode(int $exitCode): self
    {
        return new self(\sprintf(
            'A command that timed out cannot also have exited with %d. The executor stops it, so it '
            . 'has no exit code of its own.',
            $exitCode,
        ));
    }
}
