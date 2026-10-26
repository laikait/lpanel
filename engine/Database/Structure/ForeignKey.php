<?php

declare(strict_types=1);

namespace App\Engine\Database\Structure;

use App\Engine\Database\DatabaseException;

/**
 * A column that must hold a key of another table:
 *
 *     $table->bigInteger('customer_id');
 *     $table->foreign('customer_id')->references('customers')->onDelete('cascade');
 *
 * The column is a bigInteger because id() is one: MySQL refuses a foreign key
 * whose type differs from the column it points at, even by its sign.
 */
final class ForeignKey
{
    /**
     * What happens to this row when the one it points at goes, or changes key.
     *
     * RESTRICT and NO ACTION both refuse. They differ only for constraints
     * checked at commit, which the builder does not declare.
     */
    public const ACTIONS = ['cascade', 'restrict', 'set null', 'no action'];

    private ?string $table = null;

    private string $referenced = 'id';

    private string $onDelete = 'no action';

    private string $onUpdate = 'no action';

    public function __construct(public readonly string $column) {}

    /** The table, and its column, that this one points at. The column is its id unless said. */
    public function references(string $table, string $column = 'id'): self
    {
        $this->table = $table;
        $this->referenced = $column;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $this->action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $this->action($action);

        return $this;
    }

    public function table(): ?string
    {
        return $this->table;
    }

    public function referencedColumn(): string
    {
        return $this->referenced;
    }

    public function deleteAction(): string
    {
        return $this->onDelete;
    }

    public function updateAction(): string
    {
        return $this->onUpdate;
    }

    /** Written into the statement, so it comes from this list and nowhere else. */
    private function action(string $action): string
    {
        $normalised = \strtolower(\trim((string) \preg_replace('/\s+/', ' ', $action)));

        if (!\in_array($normalised, self::ACTIONS, true)) {
            throw DatabaseException::invalidStructure(
                \sprintf('The foreign key on "%s"', $this->column),
                \sprintf('"%s" is not an action. Use one of: %s.', $action, \implode(', ', self::ACTIONS)),
            );
        }

        return $normalised;
    }
}
