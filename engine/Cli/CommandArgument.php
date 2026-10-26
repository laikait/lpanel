<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * A positional argument.
 *
 * Positional, therefore ordered, therefore a declaration of order: required
 * arguments come first and the command rejects any other arrangement when it is
 * declared, not when somebody finally runs it.
 */
final class CommandArgument
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = true,
        public readonly ?string $default = null,
    ) {}

    /** How it appears in a synopsis: <name> when required, [name] when not. */
    public function synopsis(): string
    {
        return $this->required ? '<' . $this->name . '>' : '[' . $this->name . ']';
    }
}
