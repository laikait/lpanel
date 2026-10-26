<?php

declare(strict_types=1);

namespace App\Engine\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown by get() for an identifier that is neither bound nor autowirable.
 *
 * Implements the PSR-11 interface rather than extending FrameworkException,
 * because PSR-11 interop is the whole reason the container exists in this shape.
 */
final class EntryNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public static function for(string $id): self
    {
        return new self(\sprintf(
            'Container entry "%s" is not bound and is not an existing class, so it cannot be resolved.',
            $id,
        ));
    }
}
