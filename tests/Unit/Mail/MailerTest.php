<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Engine\Core\Application;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Mail\Envelope;
use App\Engine\Mail\Mailer;
use App\Engine\Mail\MailException;
use App\Engine\Mail\Message;
use App\Engine\Mail\Transport;
use App\Engine\Mail\Transports\ArrayTransport;
use App\Engine\Mail\Transports\LogTransport;
use App\Engine\Mail\Transports\SendmailTransport;
use App\Engine\System\Command\CommandExecutor;
use App\Engine\Template\TemplateManager;
use App\Engine\Template\ThemeRegistry;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * The Mailer as an application gets it from the container: templates, the
 * queue, hooks and filters are the real ones, and the transport is in memory.
 */
final class MailerTest extends TestCase
{
    private string $templates = '';

    private Application $app;

    protected function setUp(): void
    {
        $this->templates = \sys_get_temp_dir() . '/lphp-mail-' . \bin2hex(\random_bytes(4));
        \mkdir($this->templates . '/emails', 0o755, true);
        \file_put_contents($this->templates . '/emails/receipt.twig', '<p>Order {{ order }} for {{ name }}</p>');
        \file_put_contents($this->templates . '/emails/receipt.text.twig', 'Order {{ order }} for {{ name }}');
        \file_put_contents($this->templates . '/emails/html-only.twig', '<p>{{ order }}</p>');

        $this->app = $this->shippedApplication([
            'mail' => ['transport' => 'array', 'from' => ['address' => 'shop@example.com', 'name' => 'The Shop']],
            'queue' => ['store' => 'sync'],
        ])->boot();
        $this->app->container()->get(TemplateManager::class)->registry()->add(null, $this->templates, 1000);
    }

    protected function tearDown(): void
    {
        foreach (['emails/receipt.twig', 'emails/receipt.text.twig', 'emails/html-only.twig'] as $file) {
            @\unlink($this->templates . '/' . $file);
        }

        @\rmdir($this->templates . '/emails');
        @\rmdir($this->templates);

        parent::tearDown();
    }

    private function mailer(): Mailer
    {
        return $this->app->container()->get(Mailer::class);
    }

    private function sent(): ArrayTransport
    {
        $transport = $this->app->container()->get(Transport::class);
        self::assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    public function test_a_view_is_rendered_into_html_and_text_with_the_default_from(): void
    {
        $id = $this->mailer()->send(Message::create()->to('ana@example.com')->subject('Receipt')->view('emails/receipt', ['order' => 12, 'name' => 'Ana']));

        $envelope = $this->sent()->sent()[0];
        self::assertSame($id, $envelope->messageId);
        self::assertSame('shop@example.com', $envelope->sender);
        self::assertStringContainsString('From: "The Shop" <shop@example.com>', $envelope->raw);
        self::assertStringContainsString('multipart/alternative', $envelope->raw);
        self::assertStringContainsString('Order 12 for Ana', $envelope->raw);
        self::assertStringContainsString('<p>Order 12 for Ana</p>', $envelope->raw);
    }

    /**
     * An email never follows a theme. A queued message renders in a worker,
     * which has no request and so no theme; letting the sending page's theme
     * apply would make "sync" and a real queue send different emails.
     */
    public function test_an_email_renders_without_the_active_theme_and_gives_it_back(): void
    {
        $theme = 'zzmail' . \bin2hex(\random_bytes(4));
        $root = $this->basePath('templates/' . $theme);
        \mkdir($root . '/emails', 0o755, true);
        \file_put_contents($root . '/emails/receipt.twig', '<p>THEMED</p>');
        \file_put_contents($root . '/emails/receipt.text.twig', 'THEMED');

        try {
            $themes = $this->app->container()->get(ThemeRegistry::class);
            self::assertInstanceOf(ThemeRegistry::class, $themes);
            $themes->register($theme);
            $themes->use($theme);

            $message = Message::create()->to('ana@example.com')->view('emails/receipt', ['order' => 3, 'name' => 'Ana']);

            $this->mailer()->send($message);
            $this->mailer()->queue($message);   // the "sync" queue: rendered inline, in this request

            foreach ($this->sent()->sent() as $envelope) {
                self::assertStringContainsString('<p>Order 3 for Ana</p>', $envelope->raw, 'the HTML came from templates/emails/');
                self::assertStringContainsString("\r\nOrder 3 for Ana", $envelope->raw, 'and so did the text part');
                self::assertStringNotContainsString('THEMED', $envelope->raw);
            }

            self::assertCount(2, $this->sent()->sent());
            self::assertSame($theme, $themes->active(), 'the page keeps its theme for the rest of its render');
        } finally {
            @\unlink($root . '/emails/receipt.twig');
            @\unlink($root . '/emails/receipt.text.twig');
            @\rmdir($root . '/emails');
            @\rmdir($root);
        }
    }

    public function test_a_view_without_a_text_template_is_html_alone(): void
    {
        $this->mailer()->send(Message::create()->to('ana@example.com')->view('emails/html-only', ['order' => 7]));

        $raw = $this->sent()->sent()[0]->raw;
        self::assertStringContainsString('Content-Type: text/html; charset=utf-8', $raw);
        self::assertStringNotContainsString('multipart', $raw);
    }

    public function test_the_sending_filter_can_change_or_cancel_and_the_hook_hears_it(): void
    {
        $filters = $this->app->container()->get(FilterEngine::class);
        $filters->add('mail.message', static fn(Message $m): Message => $m->bcc('audit@example.com'));
        $filters->add('mail.allowed', static fn(bool $allowed, Message $m): bool => $allowed && !\str_contains($m->subject, 'cancel'));
        $heard = [];
        $this->app->container()->get(HookEngine::class)->add('mail.sent', static function (Envelope $envelope) use (&$heard): void {
            $heard[] = $envelope->subject;
        });

        self::assertNull($this->mailer()->send(Message::create()->to('ana@example.com')->subject('please cancel')->text('x')));
        $this->mailer()->send(Message::create()->to('ana@example.com')->subject('keep')->text('x'));

        self::assertCount(1, $this->sent()->sent());
        self::assertContains('audit@example.com', $this->sent()->sent()[0]->recipients);
        self::assertSame(['keep'], $heard);
    }

    /** queue() serialises the message, view data and all, and the worker sends it with the same code. */
    public function test_a_queued_message_is_sent_by_the_job(): void
    {
        $this->mailer()->queue(Message::create()->to('ana@example.com')->view('emails/receipt', ['order' => 3, 'name' => 'Bo']));

        self::assertCount(1, $this->sent()->sent());
        self::assertStringContainsString('Order 3 for Bo', $this->sent()->sent()[0]->raw);
    }

    public function test_a_message_with_no_recipients_is_refused_before_it_is_queued(): void
    {
        $this->expectException(MailException::class);
        $this->mailer()->queue(Message::create()->text('x'));
    }

    public function test_the_log_transport_is_the_default_and_sends_nothing(): void
    {
        $app = $this->shippedApplication(['mail' => ['from' => ['address' => 'shop@example.com']]])->boot();

        self::assertInstanceOf(LogTransport::class, $app->container()->get(Transport::class));
    }

    public function test_an_unknown_transport_is_refused(): void
    {
        $app = $this->shippedApplication(['mail' => ['transport' => 'pigeon']])->boot();

        $this->expectException(MailException::class);
        $app->container()->get(Transport::class);
    }

    #[RequiresOperatingSystemFamily('Linux')]
    public function test_sendmail_gets_the_message_on_stdin_and_the_recipients_after_a_double_dash(): void
    {
        $script = $this->templates . '/sendmail';
        \file_put_contents($script, '#!' . \PHP_BINARY . " -n\n<?php file_put_contents(" . \var_export($this->templates . '/sendmail.log', true)
            . ', json_encode(["argv" => array_slice($argv, 1), "stdin" => stream_get_contents(STDIN)]));');
        \chmod($script, 0o755);

        $transport = new SendmailTransport(new CommandExecutor(), $script);
        (new Mailer($transport))->send(Message::create()->from('shop@example.com')->to('ana@example.com')->subject('Hi')->text('Body'));

        $call = \json_decode((string) \file_get_contents($this->templates . '/sendmail.log'), true);
        self::assertIsArray($call);
        self::assertSame(['-i', '-f', 'shop@example.com', '--', 'ana@example.com'], $call['argv']);
        self::assertStringContainsString("Subject: Hi\r\n", (string) $call['stdin']);

        \unlink($script);
        \unlink($this->templates . '/sendmail.log');
    }
}
