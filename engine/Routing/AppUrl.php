<?php

declare(strict_types=1);

namespace App\Engine\Routing;

/**
 * Absolute URLs to named routes, from APP_URL -- never from the request.
 *
 *     $appUrl->route('password.reset', ['token' => $token]);
 *     // https://shop.example/account/reset?token=…
 *
 * For a link that leaves the page it was made on: in an email, as an OAuth
 * redirect_uri. **The Host header is not used**, because it is whatever the
 * client sent: a password reset requested with "Host: evil.example" would
 * otherwise mail the victim a link to the attacker, token included.
 *
 * APP_URL is the application's public address, with its subdirectory if it
 * has one: https://example.com or https://example.com/shop.
 */
final class AppUrl
{
    private readonly ?string $url;

    public function __construct(?string $url, private readonly Router $router)
    {
        $url = $url === null ? '' : \rtrim(\trim($url), '/');
        $this->url = $url === '' ? null : $url;

        if ($this->url !== null && \preg_match('#^https?://[^/?\#\s]+(/[^?\#\s]*)?$#i', $this->url) !== 1) {
            throw RoutingException::invalidAppUrl($this->url);
        }
    }

    public function isConfigured(): bool
    {
        return $this->url !== null;
    }

    /**
     * @param array<string, string|int|float> $parameters
     *
     * @throws RoutingException without APP_URL, or for an unknown route
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->to($this->router->url($name, $parameters));
    }

    /**
     * An absolute URL for a path the router made (so starting with its base path).
     *
     * @throws RoutingException without APP_URL
     */
    public function to(string $path): string
    {
        if ($this->url === null) {
            throw RoutingException::noAppUrl();
        }

        $base = $this->router->basePath();

        if ($base !== '' && \str_starts_with($path, $base) && \in_array(\substr($path, \strlen($base), 1), ['', '/', '?'], true)) {
            $path = \substr($path, \strlen($base));
        }

        return $this->url . ($path === '' || $path === '/' ? '/' : (\str_starts_with($path, '/') ? $path : '/' . $path));
    }
}
