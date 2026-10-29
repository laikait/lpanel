<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Engine\Mail\MailException;
use App\Engine\Mail\Message;
use App\Engine\Mail\MimeBuilder;
use App\Tests\Support\TestCase;

final class MimeBuilderTest extends TestCase
{
    private function base(): Message
    {
        return Message::create()->from('shop@example.com', 'The Shop')->to('ana@example.com', 'Ana Silva')->subject('Your receipt');
    }

    private function build(Message $message): string
    {
        return MimeBuilder::build($message, new \DateTimeImmutable('2026-10-01 12:00:00 UTC'), 'seed')->raw;
    }

    public function test_a_text_message(): void
    {
        $raw = $this->build($this->base()->text("Thank you.\nOrder 12."));

        self::assertStringContainsString("Date: Thu, 01 Oct 2026 12:00:00 +0000\r\n", $raw);
        self::assertStringContainsString("From: \"The Shop\" <shop@example.com>\r\n", $raw);
        self::assertStringContainsString("To: \"Ana Silva\" <ana@example.com>\r\n", $raw);
        self::assertStringContainsString("Subject: Your receipt\r\n", $raw);
        self::assertMatchesRegularExpression('/\r\nMessage-ID: <[0-9a-f]{32}@example\.com>\r\n/', $raw);
        self::assertStringContainsString("MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nThank you.\r\nOrder 12.", $raw);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $raw, 'every line ends in CRLF');
    }

    public function test_text_and_html_are_alternatives_text_first(): void
    {
        $raw = $this->build($this->base()->text('plain')->html('<p>rich</p>'));

        self::assertStringContainsString('Content-Type: multipart/alternative; boundary="alt-seed"', $raw);
        self::assertLessThan(\strpos($raw, 'text/html'), \strpos($raw, 'text/plain'));
        self::assertStringEndsWith("--alt-seed--\r\n", $raw);
    }

    public function test_an_attachment_makes_it_mixed(): void
    {
        $raw = $this->build($this->base()->text('see attached')->attachData('PDFDATA', 'receipt.pdf', 'application/pdf'));

        self::assertStringContainsString('Content-Type: multipart/mixed; boundary="mix-seed"', $raw);
        self::assertStringContainsString("Content-Type: application/pdf; name=\"receipt.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"receipt.pdf\"\r\n\r\n" . \base64_encode('PDFDATA'), $raw);
    }

    public function test_text_that_is_not_ascii_is_encoded(): void
    {
        $raw = $this->build(Message::create()->from('shop@example.com', 'Café Señor')->to('ana@example.com')->subject('Ihre Bestellung – Nr. 12')->text('Grüße'));

        self::assertStringContainsString('From: =?UTF-8?B?', $raw);
        self::assertStringContainsString('Subject: Ihre Bestellung =?UTF-8?B?', $raw);
        self::assertStringContainsString('Gr=C3=BC=C3=9Fe', $raw);
        self::assertSame(1, \preg_match('/^[\x00-\x7f]*$/', $raw), 'the message itself is ASCII');
    }

    public function test_bcc_is_delivered_but_never_written(): void
    {
        $envelope = MimeBuilder::build($this->base()->cc('bo@example.com')->bcc('audit@example.com')->text('x'));

        self::assertSame(['ana@example.com', 'bo@example.com', 'audit@example.com'], $envelope->recipients);
        self::assertStringContainsString("Cc: bo@example.com\r\n", $envelope->raw);
        self::assertStringNotContainsString('audit@example.com', $envelope->raw);
        self::assertSame('shop@example.com', $envelope->sender);
    }

    /** A subject from a form must not become a second header. */
    public function test_a_line_break_in_a_header_is_refused(): void
    {
        $this->expectException(MailException::class);
        Message::create()->subject("Hello\r\nBcc: everyone@example.com");
    }

    public function test_a_line_break_in_a_name_is_refused(): void
    {
        $this->expectException(MailException::class);
        Message::create()->to('ana@example.com', "Ana\nBcc: x@example.com");
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $this->expectException(MailException::class);
        Message::create()->to('not-an-address');
    }

    public function test_a_message_without_recipients_or_body_is_refused(): void
    {
        try {
            MimeBuilder::build(Message::create()->from('shop@example.com')->text('x'));
            self::fail('no recipients was accepted');
        } catch (MailException $e) {
            self::assertStringContainsString('no recipients', $e->getMessage());
        }

        $this->expectException(MailException::class);
        MimeBuilder::build($this->base());
    }

    /**
     * Mail header names have no case, so header('from', ...) was a second
     * From -- the builder only skipped an exact-case match. Every name the
     * builder writes is refused, in any case, and a custom one is kept.
     */
    public function test_a_header_the_builder_writes_cannot_be_set_in_any_case(): void
    {
        foreach (['from', 'FROM', 'Subject', 'bcc', 'Reply-To', 'message-id', 'Content-Type', 'content-x', 'Return-Path'] as $name) {
            try {
                $this->base()->header($name, 'attacker@evil.example');
                self::fail(\sprintf('header("%s") was accepted', $name));
            } catch (MailException $e) {
                self::assertStringContainsString('written by the mailer', $e->getMessage());
            }
        }

        $raw = $this->build($this->base()->text('x')->header('List-Unsubscribe', '<mailto:u@example.com>'));

        self::assertStringContainsString('List-Unsubscribe: <mailto:u@example.com>', $raw);
        self::assertSame(1, \preg_match_all('/^From:/mi', $raw));
    }

    public function test_messages_are_immutable(): void
    {
        $base = $this->base();
        $base->bcc('audit@example.com');

        self::assertSame([], $base->bcc);
    }
}
