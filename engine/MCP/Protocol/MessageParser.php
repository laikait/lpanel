<?php

declare(strict_types=1);

namespace App\Engine\MCP\Protocol;

/**
 * One JSON-RPC message, turned into a Request or a Notification -- or refused.
 *
 * Strict, because everything past here trusts the shape:
 *
 * - **At most $maxBytes**, checked before decoding, and at most 64 levels deep.
 * - **An object** with `"jsonrpc": "2.0"` and a non-empty string `method`.
 * - **`id` is a string or an integer**, never null, a float or a boolean. MCP
 *   forbids null ids, and a float id cannot be echoed back exactly.
 * - **`params`, when present, is an object.** MCP's parameters are named.
 * - **No batches.** A JSON array of messages is refused: current MCP versions
 *   removed JSON-RPC batching, and one message is one unit of authorization,
 *   validation and audit.
 *
 * Messages a client sends as answers (an id and a result, no method) are
 * refused as well: this server sends no requests of its own.
 */
final class MessageParser
{
    public const VERSION = '2.0';

    public const DEFAULT_MAX_BYTES = 1_048_576;

    private const DEPTH = 64;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES) {}

    /** @throws ProtocolException */
    public function parse(string $json): Request|Notification
    {
        if (\strlen($json) > $this->maxBytes) {
            throw ProtocolException::tooLarge($this->maxBytes);
        }

        try {
            $message = \json_decode($json, false, self::DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw ProtocolException::parseError();
        }

        if (\is_array($message)) {
            throw ProtocolException::invalidRequest('batches are not supported; send one message at a time');
        }

        if (!$message instanceof \stdClass) {
            throw ProtocolException::invalidRequest('a message is a JSON object');
        }

        $id = self::id($message);

        if (($message->jsonrpc ?? null) !== self::VERSION) {
            throw ProtocolException::invalidRequest('"jsonrpc" must be "2.0"', $id);
        }

        if (!isset($message->method)) {
            throw ProtocolException::invalidRequest('a message needs a "method"; this server accepts no responses', $id);
        }

        if (!\is_string($message->method) || $message->method === '') {
            throw ProtocolException::invalidRequest('"method" is a non-empty string', $id);
        }

        $params = self::params($message, $id);

        // id() has already refused an id that is present and unusable, so null
        // here means there was none.
        return $id === null
            ? new Notification($message->method, $params)
            : new Request($id, $message->method, $params);
    }

    /** The id, if the message has a usable one; refuses an unusable one. */
    private static function id(\stdClass $message): string|int|null
    {
        if (!\property_exists($message, 'id')) {
            return null;
        }

        $id = $message->id;

        if (\is_string($id) || \is_int($id)) {
            return $id;
        }

        throw ProtocolException::invalidRequest('"id" is a string or an integer, never null');
    }

    /** @return array<string, mixed> */
    private static function params(\stdClass $message, string|int|null $id): array
    {
        if (!\property_exists($message, 'params')) {
            return [];
        }

        if (!$message->params instanceof \stdClass) {
            throw $id === null
                ? ProtocolException::invalidRequest('"params" is an object')
                : ProtocolException::invalidParams('"params" is an object of named parameters', $id);
        }

        /** @var array<string, mixed> $params */
        $params = self::toArray($message->params);

        return $params;
    }

    /** Objects become maps, all the way down. */
    private static function toArray(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = \get_object_vars($value);
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::toArray($item);
            }
        }

        return $value;
    }
}
