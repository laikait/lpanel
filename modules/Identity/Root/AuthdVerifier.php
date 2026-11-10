<?php

declare(strict_types=1);

namespace App\Modules\Identity\Root;

use App\Engine\Security\Secret;

/**
 * Asks lpanel-authd over its Unix socket.
 *
 * One JSON line each way:
 *   → {"v":1,"user":"root","password":"…","ip":"203.0.113.9"}
 *   ← {"v":1,"ok":true} | {"v":1,"ok":false,"code":"denied|locked|…","retry_after":60}
 *
 * authd accepts connections only from the panel's own uid (SO_PEERCRED) and
 * keeps its own per-address lockout, so a bug in the panel cannot turn it into
 * a password oracle.
 */
final class AuthdVerifier implements RootPasswordVerifier
{
    public function __construct(
        private readonly string $socket = '/run/lpanel-authd/authd.sock',
        private readonly float $timeout = 6.0,
    ) {}

    public function check(Secret $password, ?string $ip): RootCheck
    {
        $connection = @\stream_socket_client('unix://' . $this->socket, $code, $message, $this->timeout);

        if ($connection === false) {
            return RootCheck::unavailable();
        }

        try {
            \stream_set_timeout($connection, (int) \ceil($this->timeout));
            $request = \json_encode([
                'v' => 1,
                'user' => 'root',
                'password' => $password->reveal(),
                'ip' => $ip ?? '',
            ], \JSON_THROW_ON_ERROR);

            if (@\fwrite($connection, $request . "\n") === false) {
                return RootCheck::unavailable();
            }

            $line = \fgets($connection, 1024);
        } finally {
            \fclose($connection);
        }

        $answer = \is_string($line) ? \json_decode($line, true) : null;

        if (!\is_array($answer) || ($answer['v'] ?? null) !== 1) {
            return RootCheck::unavailable();
        }

        if (($answer['ok'] ?? false) === true) {
            return RootCheck::allowed();
        }

        return match ($answer['code'] ?? '') {
            'locked' => RootCheck::locked((int) ($answer['retry_after'] ?? 60)),
            'unavailable', 'bad_request' => RootCheck::unavailable(),
            default => RootCheck::denied(),
        };
    }
}
