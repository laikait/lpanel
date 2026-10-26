<?php

declare(strict_types=1);

namespace App\Engine\Cli;

use App\Engine\Error\FrameworkException;

/**
 * Everything the console refuses to do.
 *
 * Two different audiences are served here and the distinction is carried in
 * $usage rather than in the message text. A usage error is the operator's
 * mistake -- a missing argument, an option that does not exist -- and deserves
 * the command's synopsis and exit code 2. Everything else is a declaration
 * mistake by whoever wrote the module, and deserves a stack trace in debug.
 *
 * Printing a synopsis at somebody who mistyped a class name helps nobody, which
 * is why the two are told apart at the point the exception is created rather
 * than guessed at the point it is caught.
 */
final class ConsoleException extends FrameworkException
{
    private function __construct(
        string $message,
        public readonly bool $usage,
        public readonly ?string $command = null,
        public readonly bool $unknown = false,
    ) {
        parent::__construct($message);
    }

    // ---- declaration-time mistakes ---------------------------------------

    public static function duplicateCommand(string $name, ?string $owner, ?string $existingOwner): self
    {
        return new self(
            \sprintf(
                'Command "%s" is already registered by %s and cannot be reused by %s. Command names must be unique.',
                $name,
                $existingOwner ?? 'an unknown module',
                $owner ?? 'an unknown module',
            ),
            usage: false,
        );
    }

    public static function invalidName(string $name): self
    {
        return new self(
            \sprintf(
                'Command name "%s" is invalid. A name is lowercase words joined by colons, such as "customer:sync".',
                $name,
            ),
            usage: false,
        );
    }

    public static function invalidOptionName(string $command, string $name): self
    {
        return new self(
            \sprintf(
                'Option "%s" declared by command "%s" is invalid. '
                . 'An option name is lowercase, may contain dashes, and is written as --%s on the command line.',
                $name,
                $command,
                $name,
            ),
            usage: false,
        );
    }

    public static function invalidShortcut(string $command, string $shortcut): self
    {
        return new self(
            \sprintf(
                'Shortcut "-%s" declared by command "%s" is invalid. A shortcut is exactly one letter.',
                $shortcut,
                $command,
            ),
            usage: false,
        );
    }

    public static function duplicateInputName(string $command, string $name): self
    {
        return new self(
            \sprintf(
                'Command "%s" already has an argument or option called "%s". '
                . 'Both bind to a handler parameter by name, so the two would collide.',
                $command,
                $name,
            ),
            usage: false,
        );
    }

    public static function requiredAfterOptional(string $command, string $name): self
    {
        return new self(
            \sprintf(
                'Argument "%s" of command "%s" is required but follows an optional one. '
                . 'Positional arguments are matched in order, so everything after an optional argument '
                . 'must be optional too.',
                $name,
                $command,
            ),
            usage: false,
        );
    }

    public static function unexpectedResult(string $command, string $type): self
    {
        return new self(
            \sprintf(
                'Command "%s" returned %s. A command returns an int exit code, a string to print, or nothing. '
                . 'Returning a bool is not accepted: whether true means success or failure is a guess, '
                . 'and an exit code should never be guessed.',
                $command,
                $type,
            ),
            usage: false,
        );
    }

    // ---- operator mistakes ------------------------------------------------

    /** @param list<string> $suggestions */
    public static function unknownCommand(string $name, array $suggestions = []): self
    {
        $message = \sprintf('Unknown command "%s".', $name);

        if ($suggestions !== []) {
            $message .= \sprintf(' Did you mean %s?', self::listOf($suggestions));
        }

        return new self($message, usage: true, unknown: true);
    }

    /** @param list<string> $known */
    public static function unknownOption(string $command, string $option, array $known = []): self
    {
        $written = \strlen($option) === 1 ? '-' . $option : '--' . $option;

        $message = \sprintf('Command "%s" has no option %s.', $command, $written);

        if ($known !== []) {
            $message .= \sprintf(' It accepts %s.', self::listOf(\array_map(
                static fn(string $name): string => '--' . $name,
                $known,
            )));
        }

        return new self($message, usage: true, command: $command);
    }

    public static function optionNeedsValue(string $command, string $option): self
    {
        return new self(
            \sprintf('Option --%s of command "%s" needs a value, written --%s=<value>.', $option, $command, $option),
            usage: true,
            command: $command,
        );
    }

    public static function optionTakesNoValue(string $command, string $option): self
    {
        return new self(
            \sprintf('Option --%s of command "%s" is a flag and takes no value.', $option, $command),
            usage: true,
            command: $command,
        );
    }

    public static function missingArgument(string $command, string $argument): self
    {
        return new self(
            \sprintf('Command "%s" needs the <%s> argument.', $command, $argument),
            usage: true,
            command: $command,
        );
    }

    public static function tooManyArguments(string $command, int $expected, int $given): self
    {
        return new self(
            \sprintf(
                'Command "%s" takes %d argument%s, but %d were given.',
                $command,
                $expected,
                $expected === 1 ? '' : 's',
                $given,
            ),
            usage: true,
            command: $command,
        );
    }

    public static function valueRejected(string $command, string $name, string $expected, string $value): self
    {
        return new self(
            \sprintf('"%s" is not %s, which is what "%s" of command "%s" needs.', $value, $expected, $name, $command),
            usage: true,
            command: $command,
        );
    }

    /** @param list<string> $items */
    private static function listOf(array $items): string
    {
        if (\count($items) === 1) {
            return '"' . $items[0] . '"';
        }

        $quoted = \array_map(static fn(string $item): string => '"' . $item . '"', $items);
        $last = \array_pop($quoted);

        return \implode(', ', $quoted) . ' or ' . $last;
    }
}
