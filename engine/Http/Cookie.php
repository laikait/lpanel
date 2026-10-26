<?php

declare(strict_types=1);

namespace App\Engine\Http;

use App\Engine\Security\Encrypter;

/**
 * A cookie to be set on a response.
 *
 * The defaults are the safe ones: HttpOnly on, SameSite=Lax, and Secure left to
 * the caller because forcing it would break plain-HTTP development. A cookie
 * that needs to be readable from JavaScript has to say so explicitly.
 */
final class Cookie
{
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly int $expires = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
    ) {
        if ($name === '' || \strpbrk($name, "=,; \t\r\n\v\f") !== false) {
            throw new \InvalidArgumentException(\sprintf('Invalid cookie name "%s".', $name));
        }

        if (!\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid SameSite value "%s".', $sameSite));
        }

        if ($sameSite === 'None' && !$secure) {
            throw new \InvalidArgumentException('SameSite=None requires the Secure attribute.');
        }
    }

    /**
     * A cookie whose value the browser can neither read nor change.
     *
     *     $response->withCookie(Cookie::encrypted($encrypter, 'cart', $json, \time() + 86400));
     *     $json = $request->decryptedCookie($encrypter, 'cart'); // null when missing or tampered with
     *
     * Encrypted under the cookie's name, so a value cannot be moved from one
     * cookie to another. It is the value that is protected, not the cookie: a
     * browser can still delete it or send an older one it kept, so an
     * expiry inside the value is the caller's to add when that matters.
     *
     * @throws \App\Engine\Security\SecurityException without APP_KEY
     */
    public static function encrypted(
        Encrypter $encrypter,
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        return new self($name, $encrypter->encrypt($value, self::purpose($name)), $expires, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    /** The context an encrypted cookie's value is bound to. */
    public static function purpose(string $name): string
    {
        return 'cookie.' . $name;
    }

    /** A cookie that instructs the browser to drop the existing one. */
    public static function forget(string $name, string $path = '/', string $domain = ''): self
    {
        return new self($name, '', 1, $path, $domain);
    }

    public function toHeaderValue(): string
    {
        $parts = [$this->name . '=' . \rawurlencode($this->value)];

        if ($this->expires > 0) {
            $parts[] = 'Expires=' . \gmdate('D, d M Y H:i:s T', $this->expires);
            $parts[] = 'Max-Age=' . \max(0, $this->expires - \time());
        }

        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return \implode('; ', $parts);
    }
}
