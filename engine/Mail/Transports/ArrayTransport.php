<?php

declare(strict_types=1);

namespace App\Engine\Mail\Transports;

use App\Engine\Mail\Envelope;
use App\Engine\Mail\Transport;

/**
 * Keeps every message in memory, for tests.
 *
 *     $mail = new ArrayTransport();
 *     $app->container()->instance(Transport::class, $mail);
 *     ...
 *     self::assertSame(['ana@example.com'], $mail->sent()[0]->recipients);
 */
final class ArrayTransport implements Transport
{
    /** @var list<Envelope> */
    private array $sent = [];

    public function describe(): string
    {
        return 'array (kept in memory)';
    }

    public function send(Envelope $envelope): void
    {
        $this->sent[] = $envelope;
    }

    /** @return list<Envelope> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
