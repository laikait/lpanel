<?php

declare(strict_types=1);

namespace App\Engine\Http;

final class RedirectResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(string $location, int $status = 302, array $headers = [])
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException(\sprintf('%d is not a redirect status code.', $status));
        }

        parent::__construct('', $status, $headers);

        $this->headers['location'] = Headers::sanitizeValue($location);
    }

    public function location(): string
    {
        return $this->headers['location'] ?? '';
    }
}
