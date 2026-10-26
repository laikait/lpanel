<?php

declare(strict_types=1);

namespace App\Engine\Security;

/**
 * A delivery whose signature checked out.
 *
 * duplicate is true when this id was already received: answer 200 so the
 * sender stops retrying, and do nothing else.
 */
final class Webhook
{
    /** @param array<array-key, mixed> $payload the body, decoded; [] when it is not JSON */
    public function __construct(
        public readonly string $source,
        public readonly ?string $id,
        public readonly ?string $type,
        public readonly array $payload,
        public readonly string $body,
        public readonly bool $duplicate = false,
    ) {}
}
