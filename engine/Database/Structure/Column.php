<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

use App\Engine\Database\DatabaseException;

/**
 * One column of a table being described, with its modifiers chained on:
 *
 *     $table->string('email', 190)->unique();
 *     $table->decimal('total', 12, 2)->default('0.00');
 *     $table->dateTime('paid_at')->nullable();
 *
 * It records and checks; it writes no SQL. What can be refused without
 * knowing the rest of the table is refused here, at the line that asked for
 * it. The rest is refused by Table::check() when the table is compiled.
 */
final class Column
{
    /**
     * An indexed string longer than this cannot be indexed everywhere: MySQL's
     * key limit is 3072 bytes, which is 768 characters of utf8mb4.
     */
    public const MAX_INDEXED_LENGTH = 768;

    private bool $nullable = false;

    private bool $hasDefault = false;

    private string|int|bool|null $default = null;

    private bool $unique = false;

    private bool $index = false;

    private bool $primary = false;

    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {}

    /** It may hold NULL. Columns hold a value unless they say otherwise. */
    public function nullable(): self
    {
        if ($this->type === ColumnType::Id) {
            throw DatabaseException::invalidStructure($this->subject(), 'a generated key cannot be nullable.');
        }

        $this->nullable = true;

        return $this;
    }

    /**
     * What an INSERT that leaves it out writes.
     *
     * A string, an int, a bool or null. A float is refused: a DEFAULT is
     * written into the statement rather than bound, and a float written in
     * decimal is not always the float it was -- give its digits as a string,
     * '0.50', which every database reads into a DECIMAL exactly.
     */
    public function default(mixed $value): self
    {
        if (!$this->type->takesDefault()) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('a %s column cannot have a default on every database.', $this->type->value),
            );
        }

        if (!\is_string($value) && !\is_int($value) && !\is_bool($value) && $value !== null) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('a default must be a string, an int, a bool or null, not %s. Write a decimal as a string, \'0.50\'.', \get_debug_type($value)),
            );
        }

        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    /** No two rows may hold the same value. NULLs do not count as the same, on any database. */
    public function unique(): self
    {
        $this->assertIndexable();
        $this->unique = true;

        return $this;
    }

    /**
     * The table's key, for a table whose key is not a counted id(): a session
     * id, a country code. Never NULL, and one per table.
     */
    public function primary(): self
    {
        if ($this->type === ColumnType::Id) {
            throw DatabaseException::invalidStructure($this->subject(), 'a generated key is the table\'s key already.');
        }

        $this->assertIndexable();
        $this->primary = true;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /** Looked up by often enough to deserve an index of its own. */
    public function index(): self
    {
        $this->assertIndexable();
        $this->index = true;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): string|int|bool|null
    {
        return $this->default;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isIndexed(): bool
    {
        return $this->index;
    }

    /** Refused when asked for, so the error points at the line that asked. */
    public function assertIndexable(): void
    {
        if (!$this->type->indexable()) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('a %s column cannot be indexed on every database. Use a string of %d characters or fewer.', $this->type->value, self::MAX_INDEXED_LENGTH),
            );
        }

        if ($this->type === ColumnType::String && ($this->length ?? 0) > self::MAX_INDEXED_LENGTH) {
            throw DatabaseException::invalidStructure(
                $this->subject(),
                \sprintf('a string of %d characters is too long to index on every database; the limit is %d.', $this->length, self::MAX_INDEXED_LENGTH),
            );
        }
    }

    private function subject(): string
    {
        return \sprintf('The column "%s"', $this->name);
    }
}
