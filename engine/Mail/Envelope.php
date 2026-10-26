<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * A message ready to hand over: who it is from, who receives it, and the bytes.
 *
 * The recipients are separate from the bytes because Bcc addresses are
 * delivered to but never written into the message.
 */
final class Envelope
{
    /**
     * @param list<string> $recipients
     */
    public function __construct(
        public readonly string $sender,
        public readonly array $recipients,
        public readonly string $raw,
        public readonly string $messageId,
        public readonly string $subject = '',
    ) {}
}
