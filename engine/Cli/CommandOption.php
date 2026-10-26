<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * A named option, either a flag or one that carries a value.
 *
 * There is no third kind. Symfony's optional-value option -- --colour meaning
 * one thing and --colour=always another -- makes "--colour always" ambiguous,
 * because the parser cannot tell a value from the next positional argument
 * without guessing. Two kinds are unambiguous, and a command that genuinely
 * wants both behaviours can declare two options.
 *
 * A flag is false until it is written, and there is no --no-flag to turn one
 * off. An option whose default is true would need one, which is the reason a
 * flag's default is not configurable.
 */
final class CommandOption
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $requiresValue = false,
        public readonly ?string $shortcut = null,
        public readonly ?string $default = null,
    ) {}

    public static function flag(string $name, string $description = '', ?string $shortcut = null): self
    {
        return new self($name, $description, requiresValue: false, shortcut: $shortcut);
    }

    public static function value(
        string $name,
        string $description = '',
        ?string $shortcut = null,
        ?string $default = null,
    ): self {
        return new self($name, $description, requiresValue: true, shortcut: $shortcut, default: $default);
    }

    /** What the operator types: -d, --dry-run, or --limit=<value>. */
    public function synopsis(): string
    {
        $written = ($this->shortcut !== null ? '-' . $this->shortcut . ', ' : '') . '--' . $this->name;

        return $this->requiresValue ? $written . '=<value>' : $written;
    }

    /** False for a flag, the declared default for a value option. */
    public function defaultValue(): string|bool|null
    {
        return $this->requiresValue ? $this->default : false;
    }
}
