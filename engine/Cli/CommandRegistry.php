<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * Every command the application knows about.
 *
 * The same shape as the router, and for the same reason: a name is looked up
 * once, exactly, and a name already taken is refused at registration rather
 * than silently overridden. Which module a command came from is recorded on the
 * command itself, so `help` can say where something came from and a duplicate
 * can name both claimants.
 *
 * There is no discovery by scanning for classes. A Commands/ directory that is
 * read by reflection means a file's mere existence changes what the application
 * does, which is the opposite of how routes, services and hooks work here: a
 * module declares its commands in module.php, and the list of what a module
 * contributes stays readable in one file.
 */
final class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function add(Command $command): Command
    {
        $existing = $this->commands[$command->name] ?? null;

        if ($existing !== null) {
            throw ConsoleException::duplicateCommand($command->name, $command->module, $existing->module);
        }

        $this->commands[$command->name] = $command;

        return $command;
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /** @return list<Command> in registration order */
    public function all(): array
    {
        return \array_values($this->commands);
    }

    /** @return list<Command> by name, which groups namespaces together */
    public function sorted(): array
    {
        $commands = $this->commands;
        \ksort($commands);

        return \array_values($commands);
    }

    /** @return list<string> */
    public function names(): array
    {
        return \array_keys($this->commands);
    }

    public function count(): int
    {
        return \count($this->commands);
    }

    /**
     * Names close enough to a typo to be worth suggesting.
     *
     * Suggesting is all this does. Symfony resolves an unambiguous abbreviation
     * and runs it; a command that deletes something should not be reachable by
     * a name its author never wrote.
     *
     * @return list<string>
     */
    public function suggest(string $name): array
    {
        $threshold = \max(2, (int) \floor(\strlen($name) / 3));
        $scored = [];

        foreach ($this->names() as $candidate) {
            $distance = \levenshtein($name, $candidate);

            if ($distance <= $threshold || \str_starts_with($candidate, $name)) {
                $scored[$candidate] = $distance;
            }
        }

        \asort($scored);

        return \array_slice(\array_keys($scored), 0, 3);
    }

    /**
     * Commands keyed by the part before the first colon.
     *
     * Names with no colon come back under '', which help prints first: those
     * are the ones that are about the application as a whole rather than about
     * one of its parts.
     *
     * @return array<string, list<Command>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->sorted() as $command) {
            $groups[$command->group()][] = $command;
        }

        \uksort($groups, static fn(string $a, string $b): int => [$a === '' ? 0 : 1, $a] <=> [$b === '' ? 0 : 1, $b]);

        return $groups;
    }
}
