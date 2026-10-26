<?php

declare(strict_types=1);

namespace App\Engine\Mail;

use App\Engine\Queue\Job;

/**
 * A message waiting in the queue, sent by whichever worker takes it.
 *
 * The message travels as data; the Mailer -- and so the transport and its
 * credentials -- comes from the worker's own container when it runs.
 */
final class SendMessageJob implements Job
{
    public function __construct(public readonly Message $message) {}

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->message);
    }
}
