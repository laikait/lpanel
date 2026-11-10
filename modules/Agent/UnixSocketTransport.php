<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * The agent's Unix socket, /run/lpa/lpa.sock: root-owned, group lpanel, 0660.
 * One request per connection.
 */
final class UnixSocketTransport implements Transport
{
    /** The longest answer read; facts and results are far smaller. */
    private const MAX_ANSWER = 4 * 1024 * 1024;

    public function __construct(private readonly string $socket) {}

    public function exchange(string $line, float $timeout): string
    {
        $connection = @\stream_socket_client('unix://' . $this->socket, $code, $message, \min($timeout, 5.0));

        if ($connection === false) {
            throw new AgentUnavailable(\sprintf('The agent is not answering on %s (%s).', $this->socket, $message !== '' ? $message : 'no socket'));
        }

        try {
            $seconds = (int) \floor($timeout);
            \stream_set_timeout($connection, $seconds, (int) (($timeout - $seconds) * 1_000_000));

            if (@\fwrite($connection, $line) !== \strlen($line)) {
                throw new AgentUnavailable('The request could not be sent to the agent.');
            }

            $answer = \stream_get_line($connection, self::MAX_ANSWER, "\n");

            if ($answer === false || $answer === '') {
                $timedOut = \stream_get_meta_data($connection)['timed_out'];

                throw new AgentUnavailable($timedOut
                    ? \sprintf('The agent did not answer within %.0f seconds.', $timeout)
                    : 'The agent closed the connection without answering. Is the panel running as the user lpa expects?');
            }

            return $answer;
        } finally {
            \fclose($connection);
        }
    }
}
