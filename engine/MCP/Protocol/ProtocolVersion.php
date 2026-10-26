<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

/**
 * Which MCP protocol revision a session speaks.
 *
 * The client proposes one in `initialize`. If this server supports it, that is
 * the answer; otherwise the answer is the newest this server supports, and the
 * client decides whether it can continue -- which is the negotiation the MCP
 * specification describes. There is no guessing between revisions.
 */
final class ProtocolVersion
{
    /** Newest first. */
    public const SUPPORTED = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const LATEST = self::SUPPORTED[0];

    public static function negotiate(mixed $requested): string
    {
        return \is_string($requested) && \in_array($requested, self::SUPPORTED, true) ? $requested : self::LATEST;
    }

    public static function isSupported(string $version): bool
    {
        return \in_array($version, self::SUPPORTED, true);
    }
}
