<?php

declare(strict_types=1);

namespace App\Engine\Mail\Transports;

use App\Engine\Mail\Envelope;
use App\Engine\Mail\MailException;
use App\Engine\Mail\Transport;
use App\Engine\Security\Secret;

/**
 * SMTP, spoken directly: no library, no extension beyond openssl for TLS.
 *
 *     new SmtpTransport('smtp.example.com', 587, 'tls', 'user', new Secret('…'));
 *
 * encryption is "tls" (STARTTLS, usually port 587), "ssl" (TLS from the first
 * byte, port 465) or "none". With "tls" a server that does not offer STARTTLS
 * is refused rather than talked to in clear, because the next thing sent would
 * be the password. Certificates are verified.
 *
 * One connection per message: an application sends a few at a time, and a
 * worker that holds a connection open between jobs holds one the server will
 * close.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',
        private readonly ?string $username = null,
        private readonly ?Secret $password = null,
        private readonly float $timeout = 30.0,
        private readonly string $localDomain = 'localhost',
    ) {}

    public function describe(): string
    {
        return \sprintf('smtp://%s:%d (%s)', $this->host, $this->port, $this->encryption);
    }

    public function send(Envelope $envelope): void
    {
        $authenticates = $this->username !== null && $this->username !== '';

        // AUTH PLAIN and LOGIN are the password in base64. Over a connection
        // that is not encrypted, everything between here and the mail server
        // can read it -- so encryption "none" with a user name is refused,
        // unless the server is this machine. Before connecting: there is
        // nothing to say to a server this cannot log in to.
        if ($authenticates && $this->encryption !== 'tls' && $this->encryption !== 'ssl' && !$this->isLoopback()) {
            throw MailException::plainTextPassword($this->host);
        }

        $this->connect();

        try {
            $extensions = $this->open();

            if ($authenticates) {
                $this->authenticate($extensions);
            }

            $this->command('MAIL FROM:<' . $envelope->sender . '>', 250);

            foreach ($envelope->recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            $this->command('DATA', 354);
            $this->write(self::dotStuff($envelope->raw) . "\r\n.\r\n");
            $this->expect(250, 'the message');
            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Whether the server answers: connect, the greeting, EHLO, STARTTLS when
     * configured, QUIT. No login and no message -- for a health check, which
     * must not lock an account out by asking too often.
     *
     * @throws MailException naming what failed
     */
    public function probe(): void
    {
        $this->connect();

        try {
            $this->open();
            $this->command('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    /** A line beginning with "." gets another, so it is not read as the end of the message. */
    public static function dotStuff(string $raw): string
    {
        $raw = (string) \preg_replace('/(?<!\r)\n/', "\r\n", $raw);

        return (string) \preg_replace('/^\./m', '..', $raw);
    }

    /**
     * The greeting, EHLO, and STARTTLS with EHLO again when configured.
     *
     * @return list<string> the extensions the server announced
     */
    private function open(): array
    {
        $this->expect(220, 'the greeting');
        $extensions = $this->hello();

        if ($this->encryption === 'tls') {
            if (!\in_array('STARTTLS', $extensions, true)) {
                throw MailException::noTls($this->host);
            }

            $this->command('STARTTLS', 220);

            if (!\is_resource($this->socket) || @\stream_socket_enable_crypto($this->socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw MailException::connection($this->host, $this->port, 'the TLS handshake failed');
            }

            $extensions = $this->hello();
        }

        return $extensions;
    }

    private function connect(): void
    {
        $address = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = \stream_context_create(['ssl' => ['peer_name' => $this->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @\stream_socket_client($address, $errno, $error, $this->timeout, \STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw MailException::connection($this->host, $this->port, \is_string($error) && $error !== '' ? $error : 'error ' . $errno);
        }

        \stream_set_timeout($socket, (int) \ceil($this->timeout));
        $this->socket = $socket;
    }

    private function disconnect(): void
    {
        if (\is_resource($this->socket)) {
            \fclose($this->socket);
        }

        $this->socket = null;
    }

    /** @return list<string> the extensions the server announced, upper-case keywords */
    private function hello(): array
    {
        [, $lines] = $this->command('EHLO ' . $this->localDomain, 250);
        $extensions = [];

        foreach (\array_slice($lines, 1) as $line) {
            $extensions[] = \strtoupper((string) \strtok($line, ' '));

            if (\str_starts_with(\strtoupper($line), 'AUTH ')) {
                foreach (\explode(' ', \strtoupper(\substr($line, 5))) as $mechanism) {
                    $extensions[] = 'AUTH=' . $mechanism;
                }
            }
        }

        return $extensions;
    }

    /** Whether the server is this machine, where nothing on the wire can listen. */
    private function isLoopback(): bool
    {
        $host = \strtolower(\trim($this->host, '[]'));

        return $host === 'localhost' || $host === '::1' || \str_starts_with($host, '127.');
    }

    /** @param list<string> $extensions */
    private function authenticate(array $extensions): void
    {
        $password = $this->password?->reveal() ?? '';

        if (\in_array('AUTH=PLAIN', $extensions, true) || !\in_array('AUTH=LOGIN', $extensions, true)) {
            $this->command('AUTH PLAIN ' . \base64_encode("\0" . $this->username . "\0" . $password), 235, 'AUTH PLAIN');

            return;
        }

        $this->command('AUTH LOGIN', 334);
        $this->command(\base64_encode((string) $this->username), 334, 'the user name');
        $this->command(\base64_encode($password), 235, 'the password');
    }

    /**
     * @param int|list<int> $expected
     *
     * @return array{int, list<string>}
     */
    private function command(string $line, int|array $expected, ?string $describe = null): array
    {
        $this->write($line . "\r\n");

        return $this->expect($expected, $describe ?? \strtok($line, ' ') ?: $line);
    }

    /**
     * Read one reply -- possibly several lines, "250-..." until "250 ..." -- and check its code.
     *
     * @param int|list<int> $expected
     *
     * @return array{int, list<string>}
     */
    private function expect(int|array $expected, string $describe): array
    {
        $lines = [];
        $code = 0;

        while (true) {
            $line = \is_resource($this->socket) ? \fgets($this->socket, 1024) : false;

            if ($line === false) {
                throw MailException::connection($this->host, $this->port, 'the server stopped answering after ' . $describe);
            }

            $code = (int) \substr($line, 0, 3);
            $lines[] = \rtrim(\substr($line, 4), "\r\n");

            if (($line[3] ?? ' ') !== '-') {
                break;
            }
        }

        if (!\in_array($code, (array) $expected, true)) {
            throw MailException::refused($this->host, $describe, $code, $lines[0]);
        }

        return [$code, $lines];
    }

    private function write(string $data): void
    {
        if (!\is_resource($this->socket) || @\fwrite($this->socket, $data) === false) {
            throw MailException::connection($this->host, $this->port, 'the connection was lost');
        }
    }
}
