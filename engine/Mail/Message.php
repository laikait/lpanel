<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * An email, before it is sent.
 *
 *     $mailer->send(Message::create()
 *         ->to('ana@example.com', 'Ana')
 *         ->subject('Your receipt')
 *         ->view('emails/receipt', ['order' => $order])
 *         ->attach('/path/to/receipt.pdf'));
 *
 * Immutable: every method returns a new message, so a base message --
 * from, reply-to, a footer -- can be built once and sent to many.
 *
 * **view()** names a template rendered when the message is sent: the HTML from
 * `<name>`, and a plain-text alternative from `<name>.text` when that exists.
 * Its data is kept with the message, so a queued message renders in the
 * worker; give it plain values (ids, arrays, strings), not objects with a
 * database handle.
 *
 * Every address, name and subject is checked for line breaks as it is set.
 * That is what stops form input put in a subject from adding a Bcc header.
 */
final class Message
{
    /**
     * @param list<Address>          $to
     * @param list<Address>          $cc
     * @param list<Address>          $bcc
     * @param list<Address>          $replyTo
     * @param list<Attachment>       $attachments
     * @param array<string, string>  $headers
     * @param array<string, mixed>   $viewData
     */
    private function __construct(
        public readonly ?Address $from = null,
        public readonly array $to = [],
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $replyTo = [],
        public readonly string $subject = '',
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $view = null,
        public readonly array $viewData = [],
        public readonly array $attachments = [],
        public readonly array $headers = [],
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function from(string $email, string $name = ''): self
    {
        return $this->with(from: new Address($email, $name));
    }

    public function to(string $email, string $name = ''): self
    {
        return $this->with(to: [...$this->to, new Address($email, $name)]);
    }

    public function cc(string $email, string $name = ''): self
    {
        return $this->with(cc: [...$this->cc, new Address($email, $name)]);
    }

    public function bcc(string $email, string $name = ''): self
    {
        return $this->with(bcc: [...$this->bcc, new Address($email, $name)]);
    }

    public function replyTo(string $email, string $name = ''): self
    {
        return $this->with(replyTo: [...$this->replyTo, new Address($email, $name)]);
    }

    public function subject(string $subject): self
    {
        if (\preg_match('/[\r\n]/', $subject) === 1) {
            throw MailException::lineBreak('subject');
        }

        return $this->with(subject: $subject);
    }

    public function text(string $text): self
    {
        return $this->with(text: $text);
    }

    public function html(string $html): self
    {
        return $this->with(html: $html);
    }

    /** @param array<string, mixed> $data */
    public function view(string $template, array $data = []): self
    {
        return $this->with(view: $template, viewData: $data);
    }

    public function attach(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        return $this->with(attachments: [...$this->attachments, Attachment::fromPath($path, $filename, $contentType)]);
    }

    public function attachData(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        return $this->with(attachments: [...$this->attachments, new Attachment($filename, $content, $contentType)]);
    }

    /**
     * Headers the builder writes, compared without case as mail does.
     *
     * "from" is From to every mail server, so a header() of that name was a
     * second From -- the builder only skipped an exact-case match. The
     * addresses, the subject and the MIME structure have their own methods,
     * which validate what goes in them; this one does not, so it may not
     * touch them.
     */
    private const RESERVED_HEADERS = [
        'from', 'sender', 'to', 'cc', 'bcc', 'reply-to', 'subject', 'date', 'message-id', 'mime-version',
        'return-path', 'content-type', 'content-transfer-encoding', 'content-disposition',
    ];

    /** A header of your own, such as List-Unsubscribe. The ones the builder writes cannot be replaced. */
    public function header(string $name, string $value): self
    {
        if (\preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 || \preg_match('/[\r\n]/', $value) === 1) {
            throw MailException::lineBreak('header ' . \preg_replace('/[^\x20-\x7e]/', '?', $name));
        }

        if (\in_array(\strtolower($name), self::RESERVED_HEADERS, true) || \str_starts_with(\strtolower($name), 'content-')) {
            throw MailException::reservedHeader($name);
        }

        return $this->with(headers: [...$this->headers, $name => $value]);
    }

    /** The message with its view rendered into html and text, and the view cleared. */
    public function rendered(string $html, ?string $text): self
    {
        return $this->with(html: $html, text: $text ?? $this->text, view: '', viewData: []);
    }

    public function withDefaultFrom(?Address $from): self
    {
        return $this->from === null && $from !== null ? $this->with(from: $from) : $this;
    }

    /** @return list<Address> to, cc and bcc: everyone the message is delivered to */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /**
     * @param list<Address>|null         $to
     * @param list<Address>|null         $cc
     * @param list<Address>|null         $bcc
     * @param list<Address>|null         $replyTo
     * @param array<string, mixed>|null  $viewData
     * @param list<Attachment>|null      $attachments
     * @param array<string, string>|null $headers
     */
    private function with(
        ?Address $from = null,
        ?array $to = null,
        ?array $cc = null,
        ?array $bcc = null,
        ?array $replyTo = null,
        ?string $subject = null,
        ?string $text = null,
        ?string $html = null,
        ?string $view = null,
        ?array $viewData = null,
        ?array $attachments = null,
        ?array $headers = null,
    ): self {
        return new self(
            $from ?? $this->from,
            $to ?? $this->to,
            $cc ?? $this->cc,
            $bcc ?? $this->bcc,
            $replyTo ?? $this->replyTo,
            $subject ?? $this->subject,
            $text ?? $this->text,
            $html ?? $this->html,
            $view === '' ? null : ($view ?? $this->view),
            $viewData ?? $this->viewData,
            $attachments ?? $this->attachments,
            $headers ?? $this->headers,
        );
    }
}
