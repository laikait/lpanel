<?php

declare(strict_types=1);

namespace App\Engine\Schema;

/**
 * Everything wrong with one piece of data.
 *
 * Validation collects rather than throws, and it does not stop at the first
 * problem. A client that submits a form with four bad fields should be told
 * about four bad fields, not made to fix them one round trip at a time.
 *
 * @implements \IteratorAggregate<int, ValidationError>
 */
final class ValidationResult implements \Countable, \IteratorAggregate
{
    /** @param list<ValidationError> $errors */
    private function __construct(private readonly array $errors) {}

    /** @param list<ValidationError> $errors */
    public static function of(array $errors): self
    {
        return new self($errors);
    }

    public static function valid(): self
    {
        return new self([]);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<ValidationError> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function count(): int
    {
        return \count($this->errors);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->errors);
    }

    public function first(): ?ValidationError
    {
        return $this->errors[0] ?? null;
    }

    /**
     * The errors as path => messages, which is the shape an API response wants.
     *
     * @return array<string, list<string>>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->errors as $error) {
            $messages[$error->path][] = $error->message;
        }

        return $messages;
    }

    public function has(string $path): bool
    {
        foreach ($this->errors as $error) {
            if ($error->path === $path) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->errors as $error) {
            $paths[$error->path] = true;
        }

        return \array_keys($paths);
    }

    public function merge(self $other): self
    {
        return new self([...$this->errors, ...$other->errors]);
    }

    /** Re-report these errors as belonging under a parent path. */
    public function under(string $prefix): self
    {
        return new self(\array_map(
            static fn(ValidationError $error): ValidationError => $error->under($prefix),
            $this->errors,
        ));
    }

    public function summary(): string
    {
        return \implode('; ', \array_map(
            static fn(ValidationError $error): string => (string) $error,
            $this->errors,
        ));
    }
}
