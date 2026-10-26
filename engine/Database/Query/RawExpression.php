<?php

declare(strict_types=1);

namespace App\Engine\Database\Query;

/**
 * SQL written by hand, marked as such.
 *
 *     ->select('id', new RawExpression('COUNT(*) AS total'))
 *     ->where(new RawExpression('LOWER(email) = LOWER(?)', [$email]))
 *
 * The one place a builder writes a caller's text into the statement, so the one
 * place injection can come back in: the SQL here is trusted exactly as far as
 * the code that wrote it. Values still go in the bindings, never in the string,
 * and nothing that arrived in a request belongs in the SQL.
 *
 * A raw condition is written in parentheses, so an OR inside it stays inside it.
 */
final class RawExpression
{
    /** @var list<mixed> */
    public readonly array $bindings;

    /** @param list<mixed> $bindings */
    public function __construct(
        public readonly string $sql,
        array $bindings = [],
    ) {
        $this->bindings = \array_values($bindings);
    }
}
