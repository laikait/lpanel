<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

use App\Engine\MCP\McpError;

/**
 * An answer: a result for a request's id, or an error.
 *
 * An error to a message whose id could not be read -- unparseable JSON, a
 * request with no usable id -- carries a null id, as JSON-RPC requires.
 */
final class Response
{
    /** @param array<string, mixed>|null $result */
    private function __construct(
        public readonly string|int|null $id,
        public readonly ?array $result,
        public readonly ?McpError $error,
    ) {}

    /** @param array<string, mixed> $result */
    public static function result(string|int $id, array $result): self
    {
        return new self($id, $result, null);
    }

    public static function error(string|int|null $id, McpError $error): self
    {
        return new self($id, null, $error);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $message = ['jsonrpc' => MessageParser::VERSION, 'id' => $this->id];

        if ($this->error !== null) {
            $message['error'] = $this->error->toArray();
        } else {
            $message['result'] = $this->result ?? [];
        }

        return $message;
    }

    /**
     * One line of JSON.
     *
     * An empty result is written as {} rather than [], because a result is an
     * object and PHP cannot tell an empty array from an empty map; a nested
     * empty object is the caller's to write as new \stdClass().
     *
     * @throws ProtocolException when the result cannot be encoded (text that is not UTF-8)
     */
    public function toJson(): string
    {
        $message = $this->toArray();

        if (\array_key_exists('result', $message) && $message['result'] === []) {
            $message['result'] = new \stdClass();
        }

        try {
            return \json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // The result was the problem, so the answer is an internal error, which always encodes.
            return (string) \json_encode(self::error($this->id, McpError::internal())->toArray(), \JSON_UNESCAPED_SLASHES);
        }
    }
}
