<?php

declare(strict_types=1);

namespace App\Engine\Data;

/**
 * One condition: a field, an operator, and the value it is compared against.
 *
 * Plain data with no knowledge of how it will be applied. The same criterion
 * filters an array in memory and becomes a placeholder in a prepared statement,
 * which is what lets a repository be tested without a database and deployed
 * against one.
 */
final class Criterion
{
    public function __construct(
        public readonly string $field,
        public readonly Operator $operator,
        public readonly mixed $value = null,
    ) {
        if ($operator->expectsList() && !\is_array($value)) {
            throw DataException::operatorNeedsAList($field, $operator);
        }

        if ($operator->expectsList() && $value === []) {
            throw DataException::emptyList($field, $operator);
        }

        if (($operator === Operator::Seek) !== ($value instanceof Seek)) {
            throw new \InvalidArgumentException('A Seek criterion takes a Seek value, and only it does.');
        }
    }

    /** A criterion for rows after $seek's boundary. */
    public static function seek(Seek $seek): self
    {
        return new self($seek->label(), Operator::Seek, $seek);
    }

    /**
     * Whether a whole row matches, which is what a source asks.
     *
     * Every operator but Seek reads one field; Seek compares several.
     *
     * @param array<string, mixed> $row
     */
    public function matchesRow(array $row): bool
    {
        if ($this->value instanceof Seek) {
            return $this->value->after($row);
        }

        return $this->matches($row[$this->field] ?? null);
    }

    /** Whether a value satisfies this criterion. Used by in-memory sources. */
    public function matches(mixed $value): bool
    {
        $value = self::canonical($value);
        $expected = self::canonical($this->value);

        return match ($this->operator) {
            Operator::Eq => $value === $expected,
            Operator::NotEq => $value !== $expected,
            Operator::Lt => $this->comparable($value) && $value < $this->value,
            Operator::Lte => $this->comparable($value) && $value <= $this->value,
            Operator::Gt => $this->comparable($value) && $value > $this->value,
            Operator::Gte => $this->comparable($value) && $value >= $this->value,
            Operator::In => \is_array($expected) && \in_array($value, \array_map(self::canonical(...), $expected), true),
            Operator::NotIn => \is_array($expected) && !\in_array($value, \array_map(self::canonical(...), $expected), true),
            Operator::Like => $this->like($value),
            Operator::IsNull => $value === null,
            Operator::IsNotNull => $value !== null,
            Operator::Seek => throw new \LogicException('A Seek compares a whole row; use matchesRow().'),
        };
    }

    /**
     * Null never takes part in an ordering comparison.
     *
     * PHP would happily decide null < 5, and so would a careless port of this
     * to SQL, where the same comparison is unknown rather than true. Refusing
     * it here keeps the two backends from disagreeing.
     */
    private function comparable(mixed $value): bool
    {
        return $value !== null && $this->value !== null;
    }

    /**
     * The one place equality is deliberately loosened: true is 1 and false is 0.
     *
     * No relational database has a boolean the way PHP does -- it is a
     * TINYINT, an INTEGER or a BOOLEAN that compares equal to one of them -- so
     * a row read back from storage holds 1 where the model holds true. Without
     * this, whereIs('active', true) would match in a database and not match the
     * identical rows held in memory, which would make an in-memory test worse
     * than no test at all.
     *
     * It is bounded to that one pair. Everything else stays strict, so '5' is
     * still not 5 and null is still not false.
     */
    private static function canonical(mixed $value): mixed
    {
        return \is_bool($value) ? (int) $value : $value;
    }

    /** SQL LIKE semantics: % for any run of characters, _ for one. */
    private function like(mixed $value): bool
    {
        if (!\is_string($value) || !\is_string($this->value)) {
            return false;
        }

        $pattern = '/^' . \strtr(
            \preg_quote($this->value, '/'),
            ['%' => '.*', '_' => '.'],
        ) . '$/iu';

        return \preg_match($pattern, $value) === 1;
    }

    /** @return array{field: string, operator: string, value: mixed} */
    public function describe(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator->value,
            'value' => $this->value instanceof Seek ? $this->value->values : $this->value,
        ];
    }
}
