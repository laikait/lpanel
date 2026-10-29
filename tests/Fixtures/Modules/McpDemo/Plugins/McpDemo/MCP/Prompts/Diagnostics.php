<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\MCP\Prompts;

use App\Engine\MCP\McpContext;
use App\Engine\MCP\Prompt\Prompt;
use App\Engine\MCP\Prompt\PromptArgument;
use App\Engine\MCP\Prompt\PromptMessage;
use App\Engine\MCP\Prompt\PromptResult;

/** demo.diagnostics: a conversation starter, filled in from its arguments. */
final class Diagnostics implements Prompt
{
    public function arguments(): array
    {
        return [
            new PromptArgument('symptom', 'What is going wrong, in a sentence.', required: true),
            new PromptArgument('since', 'When it started, if known.'),
        ];
    }

    public function get(array $arguments, McpContext $context): PromptResult
    {
        $since = isset($arguments['since']) ? ' It started ' . $arguments['since'] . '.' : '';

        return new PromptResult([
            PromptMessage::user(\sprintf('Our application has a problem: %s.%s', $arguments['symptom'], $since)),
            PromptMessage::assistant('Let me read status://app first, then work through the likely causes one at a time.'),
        ], 'Diagnose an application problem');
    }
}
