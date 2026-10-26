<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Error\FrameworkException;

/**
 * Security refused something, or was asked to do something it cannot do safely.
 *
 * Note what is NOT here: a failed CSRF check, a request over its size limit, a
 * client past its rate limit. Those are HttpExceptions, because they are
 * answers to a request -- 403, 413, 429 -- and the client is entitled to one.
 * Turning them into this class would make a routine rejection look like a
 * framework fault and would lose the status code on the way.
 *
 * What is here is the other kind: a mistake in how the application is put
 * together, thrown where somebody can still fix it.
 */
final class SecurityException extends FrameworkException
{
    public static function keyTooShort(int $length, int $minimum): self
    {
        return new self(\sprintf(
            'APP_KEY is %d bytes; at least %d are needed. Generate one with: php laika security:key',
            $length,
            $minimum,
        ));
    }

    public static function keyNotSet(): self
    {
        return new self(
            'This operation needs APP_KEY and it is not set. Generate one with: '
            . 'php laika security:key, then put it in the environment.',
        );
    }

    public static function unreadableKey(): self
    {
        return new self(
            'APP_KEY is set but is not valid base64. It is a key, not a passphrase: '
            . 'generate one with php laika security:key rather than typing one.',
        );
    }

    public static function noSodium(): self
    {
        return new self(
            'Encryption needs the sodium extension, which PHP bundles but this installation has switched off. '
            . 'Enable extension=sodium in php.ini.',
        );
    }

    public static function unusableLimit(string $limit): self
    {
        return new self(\sprintf(
            'Rate limit "%s" cannot be read. Write it as <attempts>/<window>, e.g. "60/1m", '
            . '"1000/1h" or "5/30s".',
            $limit,
        ));
    }

    /** @param list<string> $problems */
    public static function rejectedUpload(array $problems): self
    {
        return new self('The upload was refused: ' . \implode(' ', $problems));
    }

    public static function unwritableDestination(string $path): self
    {
        return new self(\sprintf(
            'Uploads cannot be stored in %s: the directory does not exist and could not be created.',
            $path,
        ));
    }

    /** @param list<string> $configured */
    public static function unknownWebhook(string $source, array $configured): self
    {
        return new self(\sprintf(
            'There is no "%s" webhook. Configured under security.webhooks: %s.',
            $source,
            $configured === [] ? '(none)' : \implode(', ', $configured),
        ));
    }

    public static function webhookWithoutSecret(string $source): self
    {
        return new self(\sprintf('The "%s" webhook has no secret, so nothing it receives can be verified.', $source));
    }

    public static function webhookWithoutReplayProtection(string $source): self
    {
        return new self(\sprintf(
            'The "%s" webhook cannot be received safely: the cache does not remember a delivery from one request '
            . 'to the next, so a replayed delivery would be accepted as new. Set CACHE_STORE=file (or database).',
            $source,
        ));
    }

    public static function unknownWebhookFormat(string $source, string $format): self
    {
        return new self(\sprintf(
            'The "%s" webhook has format "%s"; use stripe, github, shopify, standard or hmac (with an HMAC algorithm such as sha256, sha1 or sha512).',
            $source,
            $format,
        ));
    }
}
