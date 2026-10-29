<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Model;

use App\Engine\Model\Model;

/**
 * A module-owned domain model.
 *
 * It lives in the module that owns the capability, not in a global Models
 * directory, and it is an ordinary object: typed properties, a constructor that
 * refuses bad state, and behaviour rather than setters. Nothing here knows
 * about a table, a request or a repository.
 */
final class Customer extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $email,
        private ?int $ownerId = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('A customer needs a name.');
        }
    }

    public function identity(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    /** Set once, by the data layer, when the customer is first persisted. */
    public function assignIdentity(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('This customer already has an identity.');
        }

        $this->id = $id;
    }

    public function rename(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A customer needs a name.');
        }

        $this->name = $name;
    }
}
