<?php

declare(strict_types=1);

namespace App\Engine\Mail;

/**
 * A file sent with a message. Its contents are read when it is attached, so a
 * queued message carries them and does not depend on the file still existing
 * when a worker sends it.
 */
final class Attachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly string $contentType = 'application/octet-stream',
    ) {
        if (\preg_match('/[\r\n"]/', $filename . $contentType) === 1) {
            throw MailException::lineBreak('attachment name or type');
        }
    }

    public static function fromPath(string $path, ?string $filename = null, ?string $contentType = null): self
    {
        $content = \is_file($path) && \is_readable($path) ? \file_get_contents($path) : false;

        if ($content === false) {
            throw MailException::unreadableAttachment($path);
        }

        $type = $contentType;

        if ($type === null && \function_exists('mime_content_type')) {
            $detected = @\mime_content_type($path);
            $type = \is_string($detected) ? $detected : null;
        }

        return new self($filename ?? \basename($path), $content, $type ?? 'application/octet-stream');
    }
}
