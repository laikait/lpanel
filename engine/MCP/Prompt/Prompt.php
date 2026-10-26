<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

use App\Engine\MCP\McpContext;

/**
 * A reusable message template an MCP client may fetch, implemented by a module.
 *
 *     final class CustomerSupport implements Prompt
 *     {
 *         public function arguments(): array
 *         {
 *             return [new PromptArgument('customer', 'The customer id.', required: true)];
 *         }
 *
 *         public function get(array $arguments, McpContext $context): PromptResult
 *         {
 *             return new PromptResult([
 *                 PromptMessage::user("Help customer {$arguments['customer']} with their open invoices."),
 *             ]);
 *         }
 *     }
 *
 * **A prompt composes text and does nothing else.** Fetching one must never
 * change anything or run an operation: a client may fetch prompts to show a
 * menu. What the model then does with the text goes through tools, which are
 * authorized and audited on their own.
 */
interface Prompt
{
    /** @return list<PromptArgument> */
    public function arguments(): array;

    /** @param array<string, string> $arguments checked against arguments() */
    public function get(array $arguments, McpContext $context): PromptResult;
}
