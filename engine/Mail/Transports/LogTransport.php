<?php

declare(strict_types=1);

namespace App\Engine\Mail\Transports;

use App\Engine\Logging\Logger;
use App\Engine\Mail\Envelope;
use App\Engine\Mail\Transport;

/**
 * Sends nothing: writes each message to the "mail" log channel instead.
 *
 * The default, so a development machine never mails a real customer. The
 * recipients and subject are logged at info, the whole message at debug.
 * security:check warns when it is still the transport in production. Like
 * any log record it is written only where logging.writers says.
 */
final class LogTransport implements Transport
{
    public const CHANNEL = 'mail';

    public function __construct(private readonly Logger $log) {}

    public function describe(): string
    {
        return 'log (nothing is sent)';
    }

    public function send(Envelope $envelope): void
    {
        $this->log->info('Mail not sent (mail.transport is log): ' . $envelope->subject, [
            'to' => \implode(', ', $envelope->recipients),
            'message_id' => $envelope->messageId,
        ]);
        $this->log->debug('Mail message ' . $envelope->messageId, ['raw' => $envelope->raw]);
    }
}
