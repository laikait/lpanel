<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\Security\Secret;
use App\Engine\Support\Path;

/**
 * A script file, run by bash, on purpose.
 *
 *     ShellCommand::bash('/var/www/app/scripts/backup.sh', [$database, $target], timeout: 900.0);
 *
 * **The source is a file; the values are arguments.** bash runs the script, and
 * each argument reaches it as $1, $2, ... -- a value is never pasted into shell
 * source, so a `$target` holding `x; rm -rf /` is one odd file name, provided
 * the script quotes its parameters ("$1", not $1). That last part is the
 * script author's, and the one thing this class cannot check.
 *
 * **There is no inline source.** A `ShellCommand::source('tar czf ' . $file)`
 * would be one string concatenation away from injection at every call site, and
 * a fixed string with positional parameters is a script file with extra steps.
 * A pipeline or a redirect belongs in a script, where it is reviewed as code.
 *
 * **Why a separate type rather than a flag on Command.** Choosing a shell is a
 * decision a reviewer should see where it is made. Command refuses shells as
 * its executable, so this is the only way one runs.
 *
 * **What bash is given.** `bash --noprofile --norc -- <script> <arguments>`,
 * with bash found on the command's PATH. The environment is Command's rules plus
 * a refusal of the variables bash reads to run code or change behaviour before
 * the script's first line: BASH_ENV, ENV, SHELLOPTS, BASHOPTS, PS4,
 * BASH_XTRACEFD and exported functions (BASH_FUNC_*). The default environment
 * the executor passes contains none of them.
 *
 * Linux-first: on Windows, `bash` on PATH is usually the WSL launcher, which
 * cannot read a Windows path.
 */
final class ShellCommand
{
    public const BASH = 'bash';

    /** Variables bash acts on before the script runs. */
    public const REFUSED_ENVIRONMENT = ['BASH_ENV', 'ENV', 'SHELLOPTS', 'BASHOPTS', 'PS4', 'BASH_XTRACEFD'];

    private function __construct(
        private readonly string $shell,
        private readonly Command $script,
    ) {}

    /**
     * @param string                            $script      absolute path to the script file
     * @param list<string|Secret>               $arguments   $1, $2, ... in the script
     * @param array<string, string|Secret>|null $environment the whole environment; null for the executor's
     */
    public static function bash(
        string $script,
        array $arguments = [],
        ?string $workingDirectory = null,
        ?array $environment = null,
        ?float $timeout = null,
        ?string $stdin = null,
        ?int $maxOutput = null,
    ): self {
        if (!Path::isAbsolute($script) || \str_contains($script, "\0")) {
            throw CommandException::scriptNotAPath();
        }

        foreach (\array_keys($environment ?? []) as $name) {
            $name = (string) $name;

            if (\in_array($name, self::REFUSED_ENVIRONMENT, true) || \str_starts_with($name, 'BASH_FUNC_')) {
                throw CommandException::shellEnvironmentRefused(\explode('=', $name, 2)[0]);
            }
        }

        // Command validates the rest: the path, the arguments, the environment
        // names and values, the limits -- the same rules, not a copy of them.
        return new self(self::BASH, new Command($script, $arguments, $workingDirectory, $environment, $timeout, $stdin, $maxOutput));
    }

    /** The shell that runs the script: "bash", found on PATH. */
    public function shell(): string
    {
        return $this->shell;
    }

    public function script(): string
    {
        return $this->script->executable();
    }

    /** @return list<string|Secret> */
    public function arguments(): array
    {
        return $this->script->arguments();
    }

    public function workingDirectory(): ?string
    {
        return $this->script->workingDirectory();
    }

    /** @return array<string, string|Secret>|null */
    public function environment(): ?array
    {
        return $this->script->environment();
    }

    public function timeout(): ?float
    {
        return $this->script->timeout();
    }

    public function stdin(): ?string
    {
        return $this->script->stdin();
    }

    public function maxOutput(): ?int
    {
        return $this->script->maxOutput();
    }
}
