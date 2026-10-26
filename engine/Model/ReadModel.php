<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * A flat, immutable projection of a query result.
 *
 * Heavy backends read far more than they write, and hydrating a full domain
 * model for every row of a list screen is the most common way a fast query
 * turns into a slow page. A read model is the alternative: exactly the columns
 * the screen needs, no change tracking, no relations, no behaviour to protect.
 *
 *     final class CustomerListRecord extends ReadModel
 *     {
 *         public function __construct(
 *             public readonly int $id,
 *             public readonly string $name,
 *             public readonly string $email,
 *         ) {}
 *     }
 *
 * It is not a Model and does not extend one, which is deliberate: nothing can
 * accidentally save it, mark it clean, or attach a relation to it. If a read
 * model starts growing domain behaviour, that is the signal that the code
 * wanted a Model after all.
 *
 * Unlike Model, this IS serialisable. A projection built for output is allowed
 * to be output; a domain entity is not.
 */
abstract class ReadModel implements \JsonSerializable
{
    /** @return array<string, mixed> */
    final public function toArray(): array
    {
        return Attributes::values($this, self::class);
    }

    /** @return array<string, mixed> */
    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
