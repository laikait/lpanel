<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * A JSON response.
 *
 * The payload is kept alongside the encoded body so that a listener on the
 * response filter can inspect what was sent without re-decoding it.
 */
final class JsonResponse extends Response
{
    private const DEFAULT_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private mixed $data;

    /** @param array<string, string> $headers */
    public function __construct(
        mixed $data = null,
        int $status = 200,
        array $headers = [],
        int $flags = self::DEFAULT_FLAGS,
    ) {
        $this->data = $data;

        parent::__construct(
            \json_encode($data, $flags | \JSON_THROW_ON_ERROR),
            $status,
            $headers,
        );

        if (!$this->hasHeader('Content-Type')) {
            $this->headers['content-type'] = 'application/json; charset=UTF-8';
        }
    }

    public function data(): mixed
    {
        return $this->data;
    }
}
