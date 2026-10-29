<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Model;

use App\Engine\Model\Model;

/**
 * A shared domain model.
 *
 * A user is referenced by more than one capability, which is the only thing
 * that earns a place in shared. A model used by exactly one module belongs to
 * that module, however general its name sounds -- shared is not a dumping
 * ground, and the moment it becomes one every module depends on every other.
 */
final class User extends Model
{
    public function __construct(
        private int $id,
        private string $username,
        private string $email,
    ) {}

    public function identity(): int
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): string
    {
        return $this->email;
    }
}
