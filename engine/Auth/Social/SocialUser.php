<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

/**
 * Who another site says the visitor is.
 *
 * **id is the only thing that identifies them**, together with the provider:
 * it never changes and is never reused. An email address can be changed,
 * recycled, or -- on some providers -- typed in without proof, which is why
 * emailVerified exists and why an account is linked by email only when the
 * provider vouches for it.
 */
final class SocialUser
{
    /** @param array<string, mixed> $raw everything the provider returned, for what this does not cover */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $email = null,
        public readonly bool $emailVerified = false,
        public readonly string $name = '',
        public readonly ?string $avatar = null,
        public readonly array $raw = [],
    ) {}
}
