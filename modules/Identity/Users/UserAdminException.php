<?php

declare(strict_types=1);

namespace App\Modules\Identity\Users;

/** A user change that was refused, with the HTTP status it means and a message safe to show. */
final class UserAdminException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function notFound(): self
    {
        return new self('There is no such user.', 404);
    }
}
