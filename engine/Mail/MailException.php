<?php

declare(strict_types=1);

namespace App\Engine\Mail;

use App\Engine\Error\FrameworkException;

/**
 * A message that cannot be built or could not be handed over.
 *
 * Messages name addresses and servers, never a body or a password. An SMTP
 * server's reply is quoted only by its code and first line, which is what a
 * server uses to say why it refused.
 */
final class MailException extends FrameworkException
{
    public static function invalidAddress(string $address): self
    {
        return new self(\sprintf('"%s" is not an email address.', \preg_replace('/[\r\n]+/', ' ', $address)));
    }

    public static function lineBreak(string $what): self
    {
        return new self(\sprintf(
            'The %s contains a line break. Refused: in a header it would let the text add headers of its own -- a Bcc, a second To.',
            $what,
        ));
    }

    public static function plainTextPassword(string $host): self
    {
        return new self(\sprintf(
            'Refusing to send the SMTP password to %s over an unencrypted connection. '
            . 'Set mail.smtp.encryption to tls (STARTTLS) or ssl.',
            $host,
        ));
    }

    public static function reservedHeader(string $name): self
    {
        return new self(\sprintf(
            'The %s header is written by the mailer and cannot be set with header(); use from(), to(), subject() '
            . 'and the others, which check what goes in them.',
            $name,
        ));
    }

    public static function noRecipients(): self
    {
        return new self('The message has no recipients: add one with to(), cc() or bcc().');
    }

    public static function noSender(): self
    {
        return new self('The message has no From address, and mail.from.address is not set. Set MAIL_FROM_ADDRESS.');
    }

    public static function noBody(): self
    {
        return new self('The message has no body: give it text(), html() or view().');
    }

    public static function unreadableAttachment(string $path): self
    {
        return new self(\sprintf('%s cannot be attached: it cannot be read.', $path));
    }

    public static function connection(string $host, int $port, string $why): self
    {
        return new self(\sprintf('Could not connect to the mail server %s:%d: %s.', $host, $port, $why));
    }

    public static function refused(string $host, string $command, int $code, string $reply): self
    {
        return new self(\sprintf('The mail server %s refused %s: %d %s', $host, $command, $code, $reply));
    }

    public static function noTls(string $host): self
    {
        return new self(\sprintf(
            '%s does not offer STARTTLS, and mail.smtp.encryption is "tls": the password would cross the network in clear. '
            . 'Set it to "ssl" for port 465, or "none" only for a server on this machine.',
            $host,
        ));
    }

    public static function sendmail(?int $exitCode): self
    {
        return new self(\sprintf('sendmail exited with %s.', $exitCode === null ? 'no exit code' : (string) $exitCode));
    }

    public static function noQueue(): self
    {
        return new self('Mailer::queue() needs a Queue, and this Mailer was built without one.');
    }

    public static function unknownTransport(string $name): self
    {
        return new self(\sprintf('mail.transport "%s" is not one of smtp, sendmail, log, array.', $name));
    }
}
