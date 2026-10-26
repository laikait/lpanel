<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * One command: a name, a handler, and the input it accepts.
 *
 * This is a description, not a base class. There is nothing to extend and no
 * handle() method to implement, because a command handler is the same thing a
 * route handler is -- an invokable class, a [class, method] pair, or a closure,
 * resolved through the container with its dependencies injected. That is the
 * whole reason there is no Command base class in this framework: the moment one
 * exists, it acquires $this->argument(), then $this->output, then $this->info(),
 * and a command stops being an ordinary object that happens to be reachable
 * from a shell.
 *
 * Arguments and options are declared rather than parsed out of a signature
 * string. "{user : the id} {--queue=}" is a small language embedded in a
 * docblock, invisible to static analysis and checked at runtime; a method call
 * per argument is longer to write, and is checked by the IDE as it is written.
 *
 *     $commands->add('customer:sync', SyncCustomers::class)
 *         ->describe('Pull customer records from the upstream system.')
 *         ->argument('since', 'Only records changed on or after this date.', required: false)
 *         ->flag('dry-run', 'Report what would change without writing.', shortcut: 'd')
 *         ->option('limit', 'Stop after this many records.', default: '100');
 */
final class Command
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)*$/';

    public const OPTION_PATTERN = '/^[a-z][a-z0-9-]*$/';

    private string $description = '';

    /** @var list<string> */
    private array $notes = [];

    /** @var list<CommandArgument> */
    private array $arguments = [];

    /** @var list<CommandOption> */
    private array $options = [];

    public function __construct(
        public readonly string $name,
        public readonly mixed $handler,
        public readonly ?string $module = null,
    ) {
        if (\preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw ConsoleException::invalidName($name);
        }
    }

    // ---- declaration ------------------------------------------------------

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** A paragraph shown under the synopsis by `help <command>`. */
    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function argument(
        string $name,
        string $description = '',
        bool $required = true,
        ?string $default = null,
    ): self {
        $this->assertNameIsFree($name);

        if ($required && $this->hasOptionalArgument()) {
            throw ConsoleException::requiredAfterOptional($this->name, $name);
        }

        $this->arguments[] = new CommandArgument($name, $description, $required, $default);

        return $this;
    }

    /** An option that is false until it is written. */
    public function flag(string $name, string $description = '', ?string $shortcut = null): self
    {
        return $this->addOption(CommandOption::flag($name, $description, $shortcut));
    }

    /** An option that carries a value: --limit=50, --limit 50, -l 50. */
    public function option(
        string $name,
        string $description = '',
        ?string $shortcut = null,
        ?string $default = null,
    ): self {
        return $this->addOption(CommandOption::value($name, $description, $shortcut, $default));
    }

    // ---- introspection ----------------------------------------------------

    public function description(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /** @return list<CommandArgument> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return list<CommandOption> */
    public function options(): array
    {
        return $this->options;
    }

    public function argumentNamed(string $name): ?CommandArgument
    {
        foreach ($this->arguments as $argument) {
            if ($argument->name === $name) {
                return $argument;
            }
        }

        return null;
    }

    public function optionNamed(string $name): ?CommandOption
    {
        foreach ($this->options as $option) {
            if ($option->name === $name) {
                return $option;
            }
        }

        return null;
    }

    public function optionForShortcut(string $shortcut): ?CommandOption
    {
        foreach ($this->options as $option) {
            if ($option->shortcut === $shortcut) {
                return $option;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function optionNames(): array
    {
        return \array_map(static fn(CommandOption $option): string => $option->name, $this->options);
    }

    /** Everything before the first colon, or '' for a name that has none. */
    public function group(): string
    {
        $colon = \strpos($this->name, ':');

        return $colon === false ? '' : \substr($this->name, 0, $colon);
    }

    /** The one-line form: name, then arguments, then a marker for the options. */
    public function synopsis(): string
    {
        $parts = [$this->name];

        foreach ($this->arguments as $argument) {
            $parts[] = $argument->synopsis();
        }

        if ($this->options !== []) {
            $parts[] = '[options]';
        }

        return \implode(' ', $parts);
    }

    private function addOption(CommandOption $option): self
    {
        if (\preg_match(self::OPTION_PATTERN, $option->name) !== 1) {
            throw ConsoleException::invalidOptionName($this->name, $option->name);
        }

        if ($option->shortcut !== null && \preg_match('/^[A-Za-z]$/', $option->shortcut) !== 1) {
            throw ConsoleException::invalidShortcut($this->name, $option->shortcut);
        }

        $this->assertNameIsFree($option->name);

        $this->options[] = $option;

        return $this;
    }

    private function hasOptionalArgument(): bool
    {
        foreach ($this->arguments as $argument) {
            if (!$argument->required) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arguments and options share one namespace.
     *
     * They both bind to a handler parameter by name, so an argument and an
     * option called "limit" would be two values competing for one parameter.
     * Better to refuse the declaration than to pick a winner.
     */
    private function assertNameIsFree(string $name): void
    {
        if ($this->argumentNamed($name) !== null || $this->optionNamed($name) !== null) {
            throw ConsoleException::duplicateInputName($this->name, $name);
        }
    }
}
