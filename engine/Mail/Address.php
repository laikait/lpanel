<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * An email address and, optionally, the name shown with it.
 */
final class Address
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {
        if (\preg_match('/[\r\n]/', $email . $name) === 1) {
            throw MailException::lineBreak('address or name');
        }

        if (\filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw MailException::invalidAddress($email);
        }
    }

    /** "Ana Silva" <ana@example.com>, with the name encoded when it is not plain ASCII. */
    public function toHeader(): string
    {
        if ($this->name === '') {
            return $this->email;
        }

        if (\preg_match('/^[\x20-\x7e]*$/', $this->name) !== 1) {
            return \mb_encode_mimeheader($this->name, 'UTF-8', 'B', "\r\n") . ' <' . $this->email . '>';
        }

        return '"' . \addcslashes($this->name, '"\\') . '" <' . $this->email . '>';
    }
}
