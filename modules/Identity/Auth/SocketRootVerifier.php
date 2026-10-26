<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Engine\Security\Secret;

/**
 * Asks lpanel-authd over its Unix socket (ADR-0010): one line of JSON out,
 * one line back. Any failure to get a well-formed answer is "unavailable",
 * never "accepted".
 */
final class SocketRootVerifier implements RootVerifier
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly string $socket) {}

    public function describe(): string
    {
        return 'lpanel-authd at ' . $this->socket;
    }

    public function verify(string $user, Secret $password, string $ip): RootVerdict
    {
        $stream = @\stream_socket_client('unix://' . $this->socket, $errno, $error, self::TIMEOUT_SECONDS);

        if ($stream === false) {
            return RootVerdict::refused(RootVerdict::UNAVAILABLE);
        }

        try {
            \stream_set_timeout($stream, self::TIMEOUT_SECONDS);
            $line = \json_encode(['v' => 1, 'user' => $user, 'password' => $password->reveal(), 'ip' => $ip], \JSON_THROW_ON_ERROR) . "\n";

            if (@\fwrite($stream, $line) !== \strlen($line)) {
                return RootVerdict::refused(RootVerdict::UNAVAILABLE);
            }

            $reply = \fgets($stream, 4096);
        } finally {
            \fclose($stream);
        }

        $answer = \is_string($reply) ? \json_decode($reply, true) : null;

        if (!\is_array($answer) || ($answer['v'] ?? null) !== 1 || !\is_bool($answer['ok'] ?? null)) {
            return RootVerdict::refused(RootVerdict::UNAVAILABLE);
        }

        if ($answer['ok'] === true) {
            return RootVerdict::accepted();
        }

        return RootVerdict::refused(
            \is_string($answer['code'] ?? null) ? $answer['code'] : RootVerdict::UNAVAILABLE,
            \is_int($answer['retry_after'] ?? null) ? $answer['retry_after'] : 0,
        );
    }
}
