<?php

declare(strict_types=1);

namespace App\Engine\System\Security;

/**
 * The subject of a system authorization decision: which capability, on what.
 *
 * It is what a module's `authorization.decision` listener receives, so that it
 * can narrow a decision by target -- this operator may restart nginx but not
 * php-fpm -- in the one direction the auth model allows, which is refusing:
 *
 *     $module->filter('authorization.decision', static function (bool $allowed, Capability $capability, Identity $who, mixed $subject): bool {
 *         return $subject instanceof SystemOperation
 *             && $subject->capability === SystemCapability::ServiceRestart
 *             && $subject->target !== 'nginx.service' ? false : $allowed;
 *     });
 */
final class SystemOperation
{
    public function __construct(
        public readonly SystemCapability $capability,
        /** The service unit, path, job id or program the operation is on; null when it has none. */
        public readonly ?string $target = null,
    ) {}
}
