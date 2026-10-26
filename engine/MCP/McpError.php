<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * An error as a client receives it: a code, a message, and optional data.
 *
 * The one shape every failure leaves the server in, whatever caused it -- a
 * malformed message, a refused authorization, a tool that threw. Nothing else
 * ever reaches the client, so the rules live here:
 *
 * - **The message is written by the framework or the module**, never copied
 *   from an exception it caught. An internal failure is "Internal error", and
 *   the exception goes to the error log, where the `mcp` channel's line for
 *   the same call names the capability and the exception's class.
 * - **Data is plain data**: scalars, null and arrays of them, so it can be
 *   encoded and cannot carry an object that serialises its internals.
 *
 * Immutable.
 */
final class McpError
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public readonly McpErrorCode $code,
        public readonly string $message,
        public readonly ?array $data = null,
    ) {
        if (\trim($message) === '') {
            throw McpContractException::emptyErrorMessage();
        }

        if ($data !== null) {
            PlainData::assert($data, 'data');
        }
    }

    public static function internal(): self
    {
        return new self(McpErrorCode::InternalError, 'Internal error');
    }

    /** @return array{code: int, message: string, data?: array<string, mixed>} the JSON-RPC error object */
    public function toArray(): array
    {
        $error = ['code' => $this->code->value, 'message' => $this->message];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
