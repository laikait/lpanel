<?php

declare(strict_types=1);

namespace App\Engine\Cli;

/**
 * The command line, parsed against what a command declared it accepts.
 *
 * Parsing needs the declaration. "--limit 50" is one option carrying a value if
 * the command declared limit as a value option, and a flag followed by a
 * positional argument if it declared it as a flag; no amount of looking at the
 * tokens alone settles that. So there is no generic argv parser here that
 * commands then interpret -- the definition is an input to parsing, which is
 * also what makes an unknown option an error rather than something silently
 * ignored.
 *
 * What is accepted:
 *
 *     --flag              a declared flag, true once written
 *     --limit=50          a value, attached
 *     --limit 50          a value, separate
 *     -l 50  -l50         the same by shortcut
 *     -abc                bundled flags
 *     --                  everything after this is a positional argument
 *
 * What is not: abbreviation (`--lim` for `--limit`), because the abbreviation
 * that is unique today becomes ambiguous the day somebody adds an option, and a
 * script written against it breaks at a distance.
 */
final class Input
{
    /**
     * @param array<string, string|null>      $arguments
     * @param array<string, string|bool|null> $options
     * @param list<string>                    $tokens
     */
    private function __construct(
        public readonly Command $command,
        private readonly array $arguments,
        private readonly array $options,
        private readonly array $tokens,
    ) {}

    /** @param list<string> $tokens everything after the command name */
    public static function parse(Command $command, array $tokens): self
    {
        $options = [];

        foreach ($command->options() as $option) {
            $options[$option->name] = $option->defaultValue();
        }

        $positional = [];
        $literal = false;
        $count = \count($tokens);

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($literal) {
                $positional[] = $token;

                continue;
            }

            if ($token === '--') {
                $literal = true;

                continue;
            }

            // A lone "-" is conventionally a filename meaning stdin, so it is
            // an argument rather than an empty option.
            if ($token === '-' || $token === '' || !\str_starts_with($token, '-')) {
                $positional[] = $token;

                continue;
            }

            $index = \str_starts_with($token, '--')
                ? self::readLong($command, $token, $tokens, $index, $options)
                : self::readShort($command, $token, $tokens, $index, $options);
        }

        return new self($command, self::bindArguments($command, $positional), $options, $tokens);
    }

    // ---- reading ----------------------------------------------------------

    /**
     * @param list<string>                    $tokens
     * @param array<string, string|bool|null> $options
     */
    private static function readLong(
        Command $command,
        string $token,
        array $tokens,
        int $index,
        array &$options,
    ): int {
        $body = \substr($token, 2);
        $value = null;

        if (\str_contains($body, '=')) {
            [$body, $value] = \explode('=', $body, 2);
        }

        $option = $command->optionNamed($body)
            ?? throw ConsoleException::unknownOption($command->name, $body, $command->optionNames());

        if (!$option->requiresValue) {
            if ($value !== null) {
                throw ConsoleException::optionTakesNoValue($command->name, $option->name);
            }

            $options[$option->name] = true;

            return $index;
        }

        if ($value !== null) {
            $options[$option->name] = $value;

            return $index;
        }

        $options[$option->name] = self::nextValue($command, $option->name, $tokens, $index);

        return $index + 1;
    }

    /**
     * @param list<string>                    $tokens
     * @param array<string, string|bool|null> $options
     */
    private static function readShort(
        Command $command,
        string $token,
        array $tokens,
        int $index,
        array &$options,
    ): int {
        $letters = \substr($token, 1);
        $length = \strlen($letters);

        for ($position = 0; $position < $length; ++$position) {
            $letter = $letters[$position];

            $option = $command->optionForShortcut($letter)
                ?? throw ConsoleException::unknownOption($command->name, $letter, $command->optionNames());

            if (!$option->requiresValue) {
                $options[$option->name] = true;

                continue;
            }

            // -l50 and -l=50 both attach; -l on its own takes the next token.
            $rest = \substr($letters, $position + 1);

            if ($rest !== '') {
                $options[$option->name] = \str_starts_with($rest, '=') ? \substr($rest, 1) : $rest;

                return $index;
            }

            $options[$option->name] = self::nextValue($command, $option->name, $tokens, $index);

            return $index + 1;
        }

        return $index;
    }

    /**
     * The token after an option that needs a value.
     *
     * Something that looks like another option is refused rather than swallowed:
     * "--limit --dry-run" is a forgotten value, and reading --dry-run as the
     * value would hide the mistake behind a nonsensical limit.
     *
     * @param list<string> $tokens
     */
    private static function nextValue(Command $command, string $option, array $tokens, int $index): string
    {
        $next = $tokens[$index + 1] ?? null;

        if ($next === null || $next === '' || (\str_starts_with($next, '-') && $next !== '-')) {
            throw ConsoleException::optionNeedsValue($command->name, $option);
        }

        return $next;
    }

    /**
     * @param list<string> $positional
     *
     * @return array<string, string|null>
     */
    private static function bindArguments(Command $command, array $positional): array
    {
        $declared = $command->arguments();

        if (\count($positional) > \count($declared)) {
            throw ConsoleException::tooManyArguments($command->name, \count($declared), \count($positional));
        }

        $arguments = [];

        foreach ($declared as $position => $argument) {
            if (\array_key_exists($position, $positional)) {
                $arguments[$argument->name] = $positional[$position];

                continue;
            }

            if ($argument->required) {
                throw ConsoleException::missingArgument($command->name, $argument->name);
            }

            $arguments[$argument->name] = $argument->default;
        }

        return $arguments;
    }

    // ---- reading the result -----------------------------------------------

    public function argument(string $name): ?string
    {
        return $this->arguments[$name] ?? null;
    }

    public function option(string $name): string|bool|null
    {
        return $this->options[$name] ?? null;
    }

    /** Whether a flag was written, or a value option was given a value. */
    public function given(string $name): bool
    {
        $value = $this->options[$name] ?? null;

        return $value !== null && $value !== false;
    }

    /** @return array<string, string|null> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /** @return array<string, string|bool|null> */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Everything by name, which is what the handler's parameters bind against.
     *
     * Arguments and options cannot collide here: Command refuses a declaration
     * that would make them.
     *
     * @return array<string, string|bool|null>
     */
    public function parameters(): array
    {
        return [...$this->arguments, ...$this->options];
    }

    /** @return list<string> the tokens exactly as they arrived */
    public function tokens(): array
    {
        return $this->tokens;
    }
}
