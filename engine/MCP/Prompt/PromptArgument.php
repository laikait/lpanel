<?php

declare(strict_types=1);

namespace App\Engine\MCP\Prompt;

use App\Engine\MCP\McpContractException;

/** One named, string argument a prompt accepts. */
final class PromptArgument
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly bool $required = false,
    ) {
        if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
            throw McpContractException::invalidPromptArgument($name);
        }
    }

    /** @return array{name: string, description: string, required: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'required' => $this->required];
    }
}
