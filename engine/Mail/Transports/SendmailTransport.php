<?php

declare(strict_types=1);

namespace App\Engine\Mail\Transports;

use App\Engine\Mail\Envelope;
use App\Engine\Mail\MailException;
use App\Engine\Mail\Transport;
use App\Engine\System\Command\Command;
use App\Engine\System\Command\CommandExecutor;

/**
 * The machine's own sendmail (Postfix, Exim, msmtp...), given the message on
 * its standard input.
 *
 * Run through CommandExecutor, so system.commands and the audit log apply as
 * to any other program -- and the recipients are passed as arguments after
 * "--", never through a shell, so an address cannot become an option.
 */
final class SendmailTransport implements Transport
{
    public const PATH = '/usr/sbin/sendmail';

    public function __construct(
        private readonly CommandExecutor $executor,
        private readonly string $path = self::PATH,
    ) {}

    public function describe(): string
    {
        return 'sendmail (' . $this->path . ')';
    }

    public function send(Envelope $envelope): void
    {
        $result = $this->executor->run(new Command(
            $this->path,
            ['-i', '-f', $envelope->sender, '--', ...$envelope->recipients],
            stdin: $envelope->raw,
        ));

        if (!$result->successful()) {
            throw MailException::sendmail($result->exitCode());
        }
    }
}
