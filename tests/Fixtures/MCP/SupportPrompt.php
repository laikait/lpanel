<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\MCP;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Prompt\Prompt;
use App\Engine\MCP\Prompt\PromptArgument;
use App\Engine\MCP\Prompt\PromptMessage;
use App\Engine\MCP\Prompt\PromptResult;

/** A prompt with a required and an optional argument, and a way to fail. */
final class SupportPrompt implements Prompt
{
    public function arguments(): array
    {
        return [
            new PromptArgument('customer', 'The customer id.', required: true),
            new PromptArgument('tone', 'How formal to be.'),
        ];
    }

    public function get(array $arguments, McpContext $context): PromptResult
    {
        if ($arguments['customer'] === 'explode') {
            throw new \RuntimeException('template store at /var/lib/app/prompts is unreadable, token S3cret');
        }

        return new PromptResult([
            PromptMessage::user(\sprintf('Help customer %s with their open invoices.', $arguments['customer'])),
            PromptMessage::assistant('Tone: ' . ($arguments['tone'] ?? 'neutral')),
        ], 'Support conversation starter');
    }
}
