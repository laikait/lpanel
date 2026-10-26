<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * Turns a Message into the bytes a mail server accepts (RFC 5322, MIME).
 *
 * - text only, or html only: one part;
 * - both: multipart/alternative, text first, so a client that cannot show HTML
 *   shows the text;
 * - attachments: multipart/mixed around that.
 *
 * Bodies are quoted-printable, attachments base64, header text that is not
 * plain ASCII is RFC 2047 encoded, and every line ends in CRLF. Bcc is never
 * written: it is in the envelope only.
 */
final class MimeBuilder
{
    private const EOL = "\r\n";

    /**
     * @param ?\DateTimeImmutable $now for tests
     * @param ?string             $boundary for tests: the seed of every boundary
     */
    public static function build(Message $message, ?\DateTimeImmutable $now = null, ?string $boundary = null): Envelope
    {
        $from = $message->from ?? throw MailException::noSender();

        if ($message->recipients() === []) {
            throw MailException::noRecipients();
        }

        if ($message->text === null && $message->html === null && $message->attachments === []) {
            throw MailException::noBody();
        }

        $seed = $boundary ?? \bin2hex(\random_bytes(12));
        $domain = \substr((string) \strrchr($from->email, '@'), 1);
        $messageId = \bin2hex(\random_bytes(16)) . '@' . $domain;

        $headers = [
            'Date' => ($now ?? new \DateTimeImmutable())->format(\DATE_RFC2822),
            'From' => $from->toHeader(),
        ];

        foreach (['To' => $message->to, 'Cc' => $message->cc, 'Reply-To' => $message->replyTo] as $name => $addresses) {
            if ($addresses !== []) {
                $headers[$name] = \implode(', ', \array_map(static fn(Address $a): string => $a->toHeader(), $addresses));
            }
        }

        $headers['Subject'] = self::encodeHeader($message->subject);
        $headers['Message-ID'] = '<' . $messageId . '>';
        $headers['MIME-Version'] = '1.0';

        // Mail header names are case-insensitive, so "subject" is Subject: an
        // exact-case check let a second one through. Message::header() refuses
        // these names already; this is the builder not trusting that alone.
        $written = \array_change_key_case(\array_fill_keys(\array_keys($headers), true));

        foreach ($message->headers as $name => $value) {
            if (!isset($written[\strtolower($name)])) {
                $headers[$name] = self::encodeHeader($value);
                $written[\strtolower($name)] = true;
            }
        }

        [$partHeaders, $body] = self::body($message, $seed);
        $raw = '';

        foreach ([...$headers, ...$partHeaders] as $name => $value) {
            $raw .= $name . ': ' . $value . self::EOL;
        }

        $recipients = \array_values(\array_unique(\array_map(static fn(Address $a): string => $a->email, $message->recipients())));

        return new Envelope($from->email, $recipients, $raw . self::EOL . $body, $messageId, $message->subject);
    }

    /** @return array{array<string, string>, string} the content headers, and the body below them */
    private static function body(Message $message, string $seed): array
    {
        $parts = [];

        if ($message->text !== null) {
            $parts[] = self::textPart('text/plain', $message->text);
        }

        if ($message->html !== null) {
            $parts[] = self::textPart('text/html', $message->html);
        }

        $content = match (\count($parts)) {
            0 => null,
            1 => $parts[0],
            default => self::multipart('alternative', $parts, 'alt-' . $seed),
        };

        if ($message->attachments === []) {
            /** @var array{array<string, string>, string} $content */
            return $content;
        }

        $mixed = $content === null ? [] : [$content];

        foreach ($message->attachments as $attachment) {
            $name = self::encodeParameter($attachment->filename);
            $mixed[] = [
                [
                    'Content-Type' => $attachment->contentType . '; name=' . $name,
                    'Content-Transfer-Encoding' => 'base64',
                    'Content-Disposition' => 'attachment; filename=' . $name,
                ],
                \rtrim(\chunk_split(\base64_encode($attachment->content), 76, self::EOL), self::EOL),
            ];
        }

        return self::multipart('mixed', $mixed, 'mix-' . $seed);
    }

    /** @return array{array<string, string>, string} */
    private static function textPart(string $type, string $content): array
    {
        $normalised = (string) \preg_replace('/\r\n|\r|\n/', self::EOL, $content);

        return [
            ['Content-Type' => $type . '; charset=utf-8', 'Content-Transfer-Encoding' => 'quoted-printable'],
            \quoted_printable_encode($normalised),
        ];
    }

    /**
     * @param list<array{array<string, string>, string}> $parts
     *
     * @return array{array<string, string>, string}
     */
    private static function multipart(string $subtype, array $parts, string $boundary): array
    {
        $body = '';

        foreach ($parts as [$headers, $content]) {
            $body .= '--' . $boundary . self::EOL;

            foreach ($headers as $name => $value) {
                $body .= $name . ': ' . $value . self::EOL;
            }

            $body .= self::EOL . $content . self::EOL;
        }

        return [['Content-Type' => 'multipart/' . $subtype . '; boundary="' . $boundary . '"'], $body . '--' . $boundary . '--' . self::EOL];
    }

    private static function encodeHeader(string $value): string
    {
        if (\preg_match('/^[\x20-\x7e]*$/', $value) === 1 && \strlen($value) <= 900) {
            return $value;
        }

        return \mb_encode_mimeheader($value, 'UTF-8', 'B', self::EOL);
    }

    /** A filename parameter: quoted ASCII, or RFC 2231 for anything else. */
    private static function encodeParameter(string $value): string
    {
        if (\preg_match('/^[\x20-\x7e]*$/', $value) === 1) {
            return '"' . \addcslashes($value, '"\\') . '"';
        }

        return '"' . \mb_encode_mimeheader($value, 'UTF-8', 'B', '') . '"';
    }
}
