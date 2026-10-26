<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * Hands a built message to something that delivers it.
 */
interface Transport
{
    /**
     * @throws MailException when it was not accepted
     */
    public function send(Envelope $envelope): void;

    /** For messages and security:check: "smtp://mail.example.com:587", "log". */
    public function describe(): string;
}
