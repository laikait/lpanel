<?php

declare(strict_types=1);

namespace App\Engine\MCP\Tool;

use App\Engine\MCP\PlainData;

/**
 * What a tool hands back, built explicitly.
 *
 *     ToolResult::text('Invoice 42 was voided.');
 *     ToolResult::structured(['id' => 42, 'status' => 'void']);
 *     ToolResult::error('Invoice 42 is already paid and cannot be voided.');
 *
 * **Nothing is serialised for you.** Structured content is plain data, checked
 * all the way down; a model or any other object is refused, because what an
 * object serialises to is whatever its properties are.
 *
 * **error() is for the tool's own failures** -- the ones worth telling the
 * model, which may try something else. MCP reports those inside the result
 * with isError set, not as protocol errors. A request that was malformed or
 * refused is a protocol error instead, thrown as an McpException.
 */
final class ToolResult
{
    /**
     * @param list<array{type: string, text: string}> $content
     * @param array<string, mixed>|null               $structured
     */
    private function __construct(
        private readonly array $content,
        private readonly ?array $structured,
        private readonly bool $isError,
    ) {}

    public static function text(string $text): self
    {
        return new self([['type' => 'text', 'text' => $text]], null, false);
    }

    /**
     * Structured content, and a text rendering of it for clients that read
     * only text: JSON, unless a summary is given.
     *
     * @param array<string, mixed> $data
     */
    public static function structured(array $data, ?string $summary = null): self
    {
        PlainData::assert($data, 'structuredContent');

        $text = $summary ?? (string) \json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);

        return new self([['type' => 'text', 'text' => $text]], $data, false);
    }

    /** A failure the tool itself reports, in words the client may see. */
    public static function error(string $message): self
    {
        return new self([['type' => 'text', 'text' => $message]], null, true);
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    /** @return array{content: list<array{type: string, text: string}>, structuredContent?: array<string, mixed>, isError: bool} */
    public function toArray(): array
    {
        $result = ['content' => $this->content];

        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        $result['isError'] = $this->isError;

        return $result;
    }
}
