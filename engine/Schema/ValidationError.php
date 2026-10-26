<?php

declare(strict_types=1);

namespace App\Engine\Schema;

/**
 * One thing wrong with one value, and where it is.
 *
 * The path is what makes this usable on nested data: "address.postcode" and
 * "contacts.2.email" tell a client exactly which box to highlight. An error
 * that only says "validation failed" costs someone an afternoon.
 */
final class ValidationError
{
    public function __construct(
        public readonly string $path,
        public readonly string $message,
    ) {}

    /** Prefix this error's path, used when a nested schema reports upwards. */
    public function under(string $prefix): self
    {
        return new self($prefix === '' ? $this->path : $prefix . '.' . $this->path, $this->message);
    }

    public function __toString(): string
    {
        return $this->path . ': ' . $this->message;
    }
}
