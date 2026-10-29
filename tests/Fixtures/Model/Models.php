<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Model;

use App\Engine\Model\Model;
use App\Engine\Model\ReadModel;

/**
 * Models written the way the framework intends them to be written: ordinary
 * objects with typed properties, a constructor that can refuse bad state, and
 * domain methods rather than setters.
 */
final class Customer extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        private string $email,
        private ?int $ownerId = null,
        private bool $active = true,
        private float $balance = 0.0,
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function balance(): float
    {
        return $this->balance;
    }

    // Domain behaviour, not setters. Change tracking picks these up because it
    // reads the properties rather than intercepting the writes.
    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function assignIdentity(int $id): void
    {
        $this->id = $id;
    }
}

final class User extends Model
{
    public function __construct(
        private int $id,
        private string $username,
    ) {}

    public function identity(): int
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }
}

final class Invoice extends Model
{
    public function __construct(
        private int $id,
        private int $customerId,
        private float $total,
    ) {}

    public function identity(): int
    {
        return $this->id;
    }

    public function customerId(): int
    {
        return $this->customerId;
    }

    public function total(): float
    {
        return $this->total;
    }
}

/** Identity is a string here, to prove int is not assumed anywhere. */
final class Country extends Model
{
    public function __construct(
        private string $code,
        private string $label,
    ) {}

    public function identity(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }
}

/** A model that never gets an identity, e.g. an unsaved draft. */
final class Draft extends Model
{
    public function __construct(private string $subject) {}

    public function identity(): null
    {
        return null;
    }

    public function subject(): string
    {
        return $this->subject;
    }
}

/** No constructor at all: hydration should still work. */
final class Marker extends Model
{
    public int $number = 7;

    public function identity(): int
    {
        return $this->number;
    }
}

/** A typed property that is never initialised, which attributes() must skip. */
final class Partial extends Model
{
    public string $filled = 'yes';

    public string $unset;

    public function identity(): null
    {
        return null;
    }
}

/** Inherits an attribute, to prove the whole hierarchy is read. */
abstract class Document extends Model
{
    public function __construct(protected int $id, protected string $reference) {}

    public function identity(): int
    {
        return $this->id;
    }
}

final class Contract extends Document
{
    public function __construct(int $id, string $reference, private string $status)
    {
        parent::__construct($id, $reference);
    }

    public function status(): string
    {
        return $this->status;
    }
}

final class Untyped extends Model
{
    /** @param mixed $anything */
    public function __construct(private int $id, private $anything) {}

    public function identity(): int
    {
        return $this->id;
    }

    public function anything(): mixed
    {
        return $this->anything;
    }
}

final class WithObject extends Model
{
    public function __construct(
        private int $id,
        private \DateTimeImmutable $createdAt,
    ) {}

    public function identity(): int
    {
        return $this->id;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

abstract class NeverInstantiable extends Model {}

final class CustomerListRecord extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}

    public static function from(Customer $customer): self
    {
        $id = $customer->identity();

        return new self($id ?? 0, $customer->name(), $customer->email());
    }
}
