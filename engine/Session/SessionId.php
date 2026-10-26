<?php

declare(strict_types=1);

namespace App\Engine\Session;

/**
 * The name a session is stored under, and the only thing the browser holds.
 *
 * A session id is a bearer credential: whoever has it is the user, with no
 * further proof asked for. That single sentence decides everything here.
 *
 * **Entropy first.** 32 bytes from random_bytes(), hex-encoded to 64 characters.
 * Not uniqid(), not a hash of the time and the IP, not an incrementing number --
 * every one of those has been a real vulnerability in a real application,
 * because a session id that can be guessed is a login that can be skipped.
 * random_bytes() throws rather than falling back to a weak source, which is the
 * behaviour worth having: a machine with no entropy should refuse to issue
 * sessions rather than issue predictable ones.
 *
 * **Hex, not base64.** The id ends up in a cookie, in filenames, in a database
 * column and in log lines. Hex is safe in all four without encoding, and the
 * eight bytes saved by base64 are not worth one escaping bug.
 *
 * **Validated on the way in.** The id in a request is attacker-controlled, so
 * isValid() is checked before it reaches a store -- which is what stops a
 * crafted id from being a path traversal in the file store or a stray value in
 * a query. The file store hashes it as well, because one check is a check and
 * two are a design.
 */
final class SessionId
{
    /** 32 bytes = 256 bits, which is the number nobody has to justify. */
    public const BYTES = 32;

    public const LENGTH = self::BYTES * 2;

    /**
     * @throws \Exception when the platform has no usable entropy, which is a
     *                    reason to stop rather than to improvise. PHP 8.2 and
     *                    later narrow this to \Random\RandomException, which
     *                    this cannot name while 8.1 is supported.
     */
    public static function generate(): string
    {
        return \bin2hex(\random_bytes(self::BYTES));
    }

    /**
     * Exactly LENGTH lowercase hex characters and nothing else.
     *
     * Deliberately strict rather than "looks roughly right". Anything that is
     * not an id this framework issued cannot identify a session here, so there
     * is no case in which being lenient helps somebody legitimate.
     */
    public static function isValid(string $id): bool
    {
        return \strlen($id) === self::LENGTH && \ctype_xdigit($id) && \strtolower($id) === $id;
    }

    /**
     * Compare two ids without leaking where they first differ.
     *
     * Timing on a session id lookup is a long way from practical, but this
     * costs one function call and the alternative is explaining why it was
     * fine here and not in Signer.
     */
    public static function matches(string $a, string $b): bool
    {
        return \hash_equals($a, $b);
    }
}
