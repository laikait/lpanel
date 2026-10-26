<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

use App\Engine\MCP\McpContractException;

/** The messages a prompt resolves to, in order. */
final class PromptResult
{
    /** @param list<PromptMessage> $messages */
    public function __construct(
        private readonly array $messages,
        private readonly string $description = '',
    ) {
        if ($messages === []) {
            throw McpContractException::emptyPrompt();
        }
    }

    /** @return array{description: string, messages: list<array{role: string, content: array{type: string, text: string}}>} */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'messages' => \array_map(static fn(PromptMessage $message): array => $message->toArray(), $this->messages),
        ];
    }
}
