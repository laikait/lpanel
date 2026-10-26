<?php

declare(strict_types=1);

namespace App\Engine\Routing;

use App\Engine\Security\SignedUrl;

/**
 * Signed links to named routes.
 *
 *     $link = $urls->signed('newsletter.unsubscribe', ['id' => 7], '+30 days');
 *
 * The route declares meta(['signed' => true]); a request without a valid
 * signature is refused with 403, an expired one with 410, before the handler
 * runs. See Security\SignedUrl for what is signed.
 */
final class UrlSigner
{
    public function __construct(
        private readonly Router $router,
        private readonly SignedUrl $signed,
    ) {}

    /**
     * @param array<string, string|int|float>      $parameters
     * @param \DateTimeInterface|string|int|null    $expires   a moment, "+7 days", seconds from now, or null for never
     */
    public function signed(string $name, array $parameters = [], \DateTimeInterface|string|int|null $expires = null): string
    {
        return $this->signed->sign($this->router->url($name, $parameters), self::expiresAt($expires));
    }

    private static function expiresAt(\DateTimeInterface|string|int|null $expires): ?int
    {
        return match (true) {
            $expires === null => null,
            $expires instanceof \DateTimeInterface => $expires->getTimestamp(),
            \is_int($expires) => \time() + $expires,
            default => (new \DateTimeImmutable($expires))->getTimestamp(),
        };
    }
}
