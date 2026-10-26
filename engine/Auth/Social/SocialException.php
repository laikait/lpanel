<?php

declare(strict_types=1);

namespace App\Engine\Auth\Social;

use App\Engine\Error\FrameworkException;

/**
 * Something went wrong signing in with another site.
 *
 * isClientError() separates what a visitor can cause -- a stale or replayed
 * link, a cancelled consent screen -- from what a developer must fix: a
 * provider that is not configured, keys that do not verify.
 */
final class SocialException extends FrameworkException
{
    private bool $client = false;

    private bool $unknownKey = false;

    private static function client(string $message): self
    {
        $e = new self($message);
        $e->client = true;

        return $e;
    }

    /** A 400 for the visitor, rather than a 500 for the developer. */
    public function isClientError(): bool
    {
        return $this->client;
    }

    /** @param list<string> $configured */
    public static function unknownProvider(string $name, array $configured): self
    {
        return self::client(\sprintf(
            'There is no "%s" sign-in. Configured under auth.social.providers: %s.',
            $name,
            $configured === [] ? '(none)' : \implode(', ', $configured),
        ));
    }

    public static function stateMismatch(): self
    {
        return self::client('This sign-in link is stale or was already used. Start again.');
    }

    public static function denied(string $error, string $description): self
    {
        return self::client(\sprintf('The provider did not sign you in: %s%s', $error, $description === '' ? '.' : ' (' . $description . ').'));
    }

    public static function misconfigured(string $provider, string $what): self
    {
        return new self(\sprintf('The "%s" sign-in is misconfigured: %s', $provider, $what));
    }

    public static function providerFailed(string $provider, string $step, int $status, string $detail): self
    {
        return new self(\sprintf('The "%s" sign-in failed %s: HTTP %d%s', $provider, $step, $status, $detail === '' ? '.' : ' (' . $detail . ').'));
    }

    public static function invalidToken(string $why): self
    {
        return new self('The id_token was refused: ' . $why);
    }

    public static function unknownKey(string $kid): self
    {
        $e = new self(\sprintf('The id_token was signed with a key the provider does not publish%s.', $kid === '' ? '' : ' ("' . $kid . '")'));
        $e->unknownKey = true;

        return $e;
    }

    /** The token names a key that is not in the set: worth fetching the set again, once. */
    public function isUnknownKey(): bool
    {
        return $this->unknownKey;
    }

    public static function claim(string $claim, string $why): self
    {
        return new self(\sprintf('The id_token was refused: its "%s" %s', $claim, $why));
    }
}
