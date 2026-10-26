<?php

declare(strict_types=1);

namespace App\Engine\System\Command;

use App\Engine\Support\Path;

/**
 * The programs, and scripts, an executor may run -- and nothing else.
 *
 *     $policy = CommandPolicy::allowlist()
 *         ->allowExecutable('/usr/bin/rsync', arguments: '/^[A-Za-z0-9._\/=-]+$/', workingDirectories: ['/srv/app'])
 *         ->allowExecutable('/usr/bin/systemctl')
 *         ->allowScript('/srv/app/scripts/backup.sh', environment: ['BACKUP_TARGET']);
 *
 *     $executor = new CommandExecutor(policy: $policy);
 *
 * **Exact matches, after resolving links.** A rule names an absolute path, and
 * a command matches it when both resolve, with realpath(), to the same file.
 * Nothing is a prefix of anything: allowing /usr/bin/rsync does not allow
 * /usr/bin/rsync-evil, and allowing a directory's programs is not a thing this
 * can express. A symbolic link to an allowed program is that program; a link
 * named like an allowed program but pointing elsewhere is not.
 *
 * **An executable alone is not enough, so a rule can narrow further:**
 *
 * - `arguments` is a pattern every argument must match completely: the text it
 *   matches must be the whole argument, whether or not the pattern is anchored.
 *   Without one, any arguments.
 * - `workingDirectories` lists the exact directories it may run in. Without it,
 *   any.
 * - `environment` lists the variable names a command may set. A command that
 *   sets none gets the executor's small default environment, which is always
 *   allowed. Without it, any names.
 *
 * **Shell execution is its own rule.** A ShellCommand is checked against the
 * scripts allowed with allowScript(), never against an allowExecutable() of
 * bash: allowing bash would allow every script on the machine.
 *
 * Which user a command runs as is not a rule: the executor never switches
 * users, so every command runs as PHP does.
 *
 * Immutable: each allow method returns a new policy. A rule for a path already
 * allowed replaces it.
 */
final class CommandPolicy
{
    /**
     * @param array<string, array{arguments: ?string, workingDirectories: ?list<string>, environment: ?list<string>}> $executables
     * @param array<string, array{arguments: ?string, workingDirectories: ?list<string>, environment: ?list<string>}> $scripts
     */
    private function __construct(
        private readonly array $executables,
        private readonly array $scripts,
    ) {}

    /** Nothing allowed until something is. */
    public static function allowlist(): self
    {
        return new self([], []);
    }

    /**
     * @param ?string       $arguments          a PCRE pattern each argument must match whole
     * @param ?list<string> $workingDirectories exact absolute directories
     * @param ?list<string> $environment        variable names a command may set
     */
    public function allowExecutable(string $path, ?string $arguments = null, ?array $workingDirectories = null, ?array $environment = null): self
    {
        $executables = $this->executables;
        $executables[self::checkPath($path)] = self::rule($arguments, $workingDirectories, $environment);

        return new self($executables, $this->scripts);
    }

    /**
     * @param ?string       $arguments          a PCRE pattern each argument must match whole
     * @param ?list<string> $workingDirectories exact absolute directories
     * @param ?list<string> $environment        variable names a command may set
     */
    public function allowScript(string $path, ?string $arguments = null, ?array $workingDirectories = null, ?array $environment = null): self
    {
        $scripts = $this->scripts;
        $scripts[self::checkPath($path)] = self::rule($arguments, $workingDirectories, $environment);

        return new self($this->executables, $scripts);
    }

    /**
     * Throws unless the prepared command is allowed.
     *
     * @param string               $program     the resolved executable, or the script for a shell command
     * @param list<string>         $arguments   revealed
     * @param list<string>|null    $environment the names the command set, or null for the default
     *
     * @throws CommandPolicyException
     */
    public function check(bool $script, string $program, array $arguments, ?string $directory, ?array $environment): void
    {
        $rule = self::find($script ? $this->scripts : $this->executables, $program)
            ?? throw ($script ? CommandPolicyException::scriptNotAllowed() : CommandPolicyException::executableNotAllowed());

        if ($rule['arguments'] !== null) {
            foreach ($arguments as $index => $argument) {
                // Whole-argument matches only, without rewriting the author's
                // pattern: the match must be the argument itself. A pattern whose
                // first match is shorter is refused -- the safe direction.
                if (\preg_match($rule['arguments'], $argument, $match) !== 1 || $match[0] !== $argument) {
                    throw CommandPolicyException::argumentNotAllowed($index + 1);
                }
            }
        }

        if ($rule['workingDirectories'] !== null) {
            $allowed = false;

            foreach ($rule['workingDirectories'] as $candidate) {
                $allowed = $allowed || ($directory !== null && self::same($candidate, $directory));
            }

            if (!$allowed) {
                throw CommandPolicyException::workingDirectoryNotAllowed();
            }
        }

        if ($rule['environment'] !== null && $environment !== null) {
            foreach ($environment as $name) {
                if (!\in_array($name, $rule['environment'], true)) {
                    throw CommandPolicyException::environmentNotAllowed($name);
                }
            }
        }
    }

    /**
     * @param array<string, array{arguments: ?string, workingDirectories: ?list<string>, environment: ?list<string>}> $rules
     *
     * @return array{arguments: ?string, workingDirectories: ?list<string>, environment: ?list<string>}|null
     */
    private static function find(array $rules, string $program): ?array
    {
        $real = \realpath($program);

        if ($real === false) {
            return null;
        }

        foreach ($rules as $path => $rule) {
            $allowed = \realpath($path);

            if ($allowed !== false && self::fold($allowed) === self::fold($real)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param ?list<string> $workingDirectories
     * @param ?list<string> $environment
     *
     * @return array{arguments: ?string, workingDirectories: ?list<string>, environment: ?list<string>}
     */
    private static function rule(?string $arguments, ?array $workingDirectories, ?array $environment): array
    {
        if ($arguments !== null && @\preg_match($arguments, '') === false) {
            throw CommandPolicyException::invalidRule('the arguments pattern is not a valid regular expression');
        }

        foreach ($workingDirectories ?? [] as $directory) {
            self::checkPath($directory);
        }

        return [
            'arguments' => $arguments,
            'workingDirectories' => $workingDirectories === null ? null : \array_values($workingDirectories),
            'environment' => $environment === null ? null : \array_values($environment),
        ];
    }

    private static function checkPath(string $path): string
    {
        if (!Path::isAbsolute($path) || \str_contains($path, "\0")) {
            throw CommandPolicyException::invalidRule('paths in the command allowlist are absolute');
        }

        return Path::normalize($path);
    }

    private static function same(string $a, string $b): bool
    {
        $realA = \realpath($a);
        $realB = \realpath($b);

        return $realA !== false && $realB !== false && self::fold($realA) === self::fold($realB);
    }

    private static function fold(string $path): string
    {
        $path = Path::normalize($path);

        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($path) : $path;
    }
}
