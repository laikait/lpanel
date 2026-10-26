<?php

declare(strict_types=1);

namespace App\Engine\Mail;

use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Queue\Queue;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\ThemeRegistry;

/**
 * Sends email.
 *
 *     public function __construct(private readonly Mailer $mailer) {}
 *
 *     $this->mailer->send($message);     // now, in this request
 *     $this->mailer->queue($message);    // by a queue worker, later
 *
 * Which transport is configured -- SMTP, sendmail, the log -- is
 * mail.transport's business, not the caller's. A message without a From gets
 * mail.from.
 *
 * **Queue it when a request is waiting.** Talking to an SMTP server takes
 * hundreds of milliseconds and can time out; a password reset page should not.
 * queue() serialises the message -- with its view's data, unrendered -- and a
 * worker sends it with the same code.
 *
 * Extension points, all receiving the Message just before it is built:
 *
 *   mail.message  filter  return a changed Message -- a Bcc added, a subject prefixed
 *   mail.allowed  filter  return false to not send it (a staging server, an unsubscribed address)
 *   mail.sent     hook    the Envelope, after the transport accepted it
 */
final class Mailer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?TemplateManager $templates = null,
        private readonly ?Address $from = null,
        private readonly ?FilterEngine $filters = null,
        private readonly ?HookEngine $hooks = null,
        private readonly ?Queue $queue = null,
        /** Switched off while a view renders; see render(). */
        private readonly ?ThemeRegistry $themes = null,
    ) {}

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * @return ?string the Message-ID, or null when the mail.allowed filter said no
     *
     * @throws MailException
     */
    public function send(Message $message): ?string
    {
        $message = $this->render($message->withDefaultFrom($this->from));

        if ($this->filters !== null) {
            /** @var Message $message */
            $message = $this->filters->apply('mail.message', $message);

            if ($this->filters->apply('mail.allowed', true, $message) !== true) {
                return null;
            }
        }

        $envelope = MimeBuilder::build($message);
        $this->transport->send($envelope);
        $this->hooks?->do('mail.sent', $envelope);

        return $envelope->messageId;
    }

    /**
     * Send it from a queue worker. The message is checked now -- a missing
     * recipient fails here, not an hour later in a worker.
     *
     * @return string the job id
     */
    public function queue(Message $message, ?string $queue = null, int $delay = 0): string
    {
        if ($message->recipients() === []) {
            throw MailException::noRecipients();
        }

        if ($this->queue === null) {
            throw MailException::noQueue();
        }

        return $this->queue->push(new SendMessageJob($message), $queue, $delay);
    }

    /**
     * Render the view, with no theme, whatever page is sending it.
     *
     * An email is rendered when it is sent: in this request when the queue is
     * "sync", in a worker otherwise. A worker has no request and so no theme,
     * so letting the request's theme apply here would make the same password
     * reset look different depending on the queue driver. Emails therefore
     * always come from templates/emails/ (or a module's templates), and the
     * theme the page was using is given back afterwards for the rest of it.
     */
    private function render(Message $message): Message
    {
        if ($message->view === null || $this->templates === null) {
            return $message;
        }

        $previous = $this->themes?->active();
        $this->themes?->use(null);

        try {
            $html = $this->templates->render($message->view, $message->viewData);
            $text = $this->templates->exists($message->view . '.text')
                ? $this->templates->render($message->view . '.text', $message->viewData)
                : null;
        } finally {
            $this->themes?->use($previous);
        }

        return $message->rendered($html, $text);
    }
}
