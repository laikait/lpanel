<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Engine\Mail\MailException;
use App\Engine\Mail\Message;
use App\Engine\Mail\MimeBuilder;
use App\Engine\Mail\Transports\SmtpTransport;
use App\Engine\Security\Secret;
use App\Tests\Support\TestCase;

/**
 * Against a scripted SMTP server on this machine
 * (tests/Fixtures/Mail/smtp-server.php): nothing is ever mailed.
 */
final class SmtpTransportTest extends TestCase
{
    private string $directory = '';

    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/lphp-smtp-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
            \proc_close($this->process);
        }

        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->directory);

        parent::tearDown();
    }

    /** Start the fixture server; return its port. */
    private function server(string $mode = 'plain'): int
    {
        $script = \dirname(__DIR__, 2) . '/Fixtures/Mail/smtp-server.php';
        $this->process = \proc_open(
            [\PHP_BINARY, $script, $this->directory . '/port', $this->directory . '/log', $mode],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        ) ?: null;

        for ($i = 0; $i < 200 && !\is_file($this->directory . '/port'); ++$i) {
            \usleep(10_000);
        }

        $port = (int) @\file_get_contents($this->directory . '/port');
        self::assertGreaterThan(0, $port, 'the fixture server did not start');

        return $port;
    }

    /** @return list<string> */
    private function dialogue(): array
    {
        return \file($this->directory . '/log', \FILE_IGNORE_NEW_LINES) ?: [];
    }

    private function envelope(string $text = "Hello.\n.Line starting with a dot"): \App\Engine\Mail\Envelope
    {
        return MimeBuilder::build(Message::create()
            ->from('shop@example.com')->to('ana@example.com')->bcc('audit@example.com')
            ->subject('Test')->text($text));
    }

    public function test_a_message_is_delivered_with_auth_plain(): void
    {
        $port = $this->server();

        (new SmtpTransport('127.0.0.1', $port, 'none', 'shop', new Secret('s3cret'), 5))->send($this->envelope());

        $dialogue = $this->dialogue();
        self::assertSame('EHLO localhost', $dialogue[0]);
        self::assertSame('AUTH PLAIN ' . \base64_encode("\0shop\0s3cret"), $dialogue[1]);
        self::assertContains('MAIL FROM:<shop@example.com>', $dialogue);
        self::assertContains('RCPT TO:<ana@example.com>', $dialogue);
        self::assertContains('RCPT TO:<audit@example.com>', $dialogue, 'Bcc is delivered');
        self::assertContains('DATA| Subject: Test', $dialogue);
        self::assertContains('DATA| ..Line starting with a dot', $dialogue, 'a leading dot is doubled');
        self::assertContains('DATA| .', $dialogue);
        self::assertSame('QUIT', $dialogue[\count($dialogue) - 1]);
    }

    public function test_auth_login_when_that_is_all_the_server_offers(): void
    {
        $port = $this->server('login-only');

        (new SmtpTransport('127.0.0.1', $port, 'none', 'shop', new Secret('s3cret'), 5))->send($this->envelope());

        $dialogue = $this->dialogue();
        self::assertSame('AUTH LOGIN', $dialogue[1]);
        self::assertSame(\base64_encode('shop'), $dialogue[2]);
        self::assertSame(\base64_encode('s3cret'), $dialogue[3]);
    }

    /** The next thing sent would be the password, in clear. */
    public function test_tls_is_required_when_asked_for_and_not_offered(): void
    {
        $port = $this->server();

        try {
            (new SmtpTransport('127.0.0.1', $port, 'tls', 'shop', new Secret('s3cret'), 5))->send($this->envelope());
            self::fail('a server without STARTTLS was given the password');
        } catch (MailException $e) {
            self::assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }

        foreach ($this->dialogue() as $line) {
            self::assertStringNotContainsString('AUTH', $line);
        }
    }

    public function test_a_refused_login_says_so_without_the_password(): void
    {
        $port = $this->server('bad-auth');

        try {
            (new SmtpTransport('127.0.0.1', $port, 'none', 'shop', new Secret('s3cret'), 5))->send($this->envelope());
            self::fail('a refused login was ignored');
        } catch (MailException $e) {
            self::assertStringContainsString('535', $e->getMessage());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
            self::assertStringNotContainsString(\base64_encode("\0shop\0s3cret"), $e->getMessage());
        }
    }

    public function test_a_refused_recipient_stops_the_message(): void
    {
        $port = $this->server('bad-rcpt');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('550');
        (new SmtpTransport('127.0.0.1', $port, 'none', null, null, 5))->send($this->envelope());
    }

    /**
     * AUTH over a connection that is not encrypted is the password in base64
     * for everything on the way to read. Refused before a connection is even
     * opened -- except to this machine, where nothing is on the way.
     */
    public function test_a_password_is_never_sent_unencrypted_to_another_machine(): void
    {
        try {
            (new SmtpTransport('smtp.example.com', 25, 'none', 'shop', new Secret('s3cret'), 1))->send($this->envelope());
            self::fail('the password would have been sent in the clear');
        } catch (MailException $e) {
            self::assertStringContainsString('unencrypted', $e->getMessage());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    public function test_no_server_is_a_connection_error(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Could not connect');
        (new SmtpTransport('127.0.0.1', 1, 'none', null, null, 2))->send($this->envelope());
    }
}
