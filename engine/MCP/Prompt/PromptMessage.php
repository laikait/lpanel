<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

/** One message of a prompt: text, from the user or the assistant. */
final class PromptMessage
{
    private function __construct(
        public readonly string $role,
        public readonly string $text,
    ) {}

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    public static function assistant(string $text): self
    {
        return new self('assistant', $text);
    }

    /** @return array{role: string, content: array{type: string, text: string}} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => ['type' => 'text', 'text' => $this->text]];
    }
}
