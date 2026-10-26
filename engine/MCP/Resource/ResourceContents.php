<?php

declare(strict_types=1);

namespace App\Engine\MCP\Resource;

use App\Engine\MCP\McpContractException;
use App\Engine\MCP\PlainData;

/**
 * What a resource read returns, built explicitly: text, JSON of plain data, or
 * bytes. Never an object serialised on the handler's behalf.
 */
final class ResourceContents
{
    private function __construct(
        private readonly string $uri,
        private readonly string $mimeType,
        private readonly ?string $text,
        private readonly ?string $blob,
    ) {}

    public static function text(string $uri, string $text, string $mimeType = 'text/plain'): self
    {
        return new self($uri, self::mime($mimeType), $text, null);
    }

    /** @param array<string, mixed> $data */
    public static function json(string $uri, array $data): self
    {
        PlainData::assert($data, 'contents');

        return new self($uri, 'application/json', (string) \json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION), null);
    }

    public static function blob(string $uri, string $bytes, string $mimeType = 'application/octet-stream'): self
    {
        return new self($uri, self::mime($mimeType), null, \base64_encode($bytes));
    }

    /** @return array{uri: string, mimeType: string, text?: string, blob?: string} */
    public function toArray(): array
    {
        $contents = ['uri' => $this->uri, 'mimeType' => $this->mimeType];

        if ($this->text !== null) {
            $contents['text'] = $this->text;
        } else {
            $contents['blob'] = (string) $this->blob;
        }

        return $contents;
    }

    private static function mime(string $type): string
    {
        return \preg_match('/^[a-z]+\/[a-z0-9.+-]+$/D', $type) === 1
            ? $type
            : throw McpContractException::invalidMimeType($type);
    }
}
