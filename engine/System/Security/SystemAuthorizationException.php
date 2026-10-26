<?php

declare(strict_types=1);

namespace App\Engine\System\Security;

use App\Engine\System\SystemException;

/**
 * An identity may not perform a system operation.
 *
 * Not an HttpException: engine/System does not know it is being asked over
 * HTTP, and the same refusal reaches a console command or an MCP tool. Whoever
 * is on the other side turns it into their own 401 or 403.
 */
final class SystemAuthorizationException extends SystemException
{
    public function __construct(
        string $message,
        public readonly SystemOperation $operation,
        public readonly bool $guest,
    ) {
        parent::__construct($message);
    }

    public static function refused(SystemOperation $operation, bool $guest): self
    {
        $target = $operation->target === null ? '' : \sprintf(' on "%s"', $operation->target);

        return new self(
            $guest
                ? \sprintf('Authentication is required for %s%s.', $operation->capability->value, $target)
                : \sprintf('This identity may not %s%s.', $operation->capability->value, $target),
            $operation,
            $guest,
        );
    }
}
