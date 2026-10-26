<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\MCP\Capability;
use App\Engine\MCP\McpConfig;
use App\Engine\MCP\McpRegistry;

/**
 * Every MCP capability the modules declared, and how MCP is served.
 *
 * The operator's view of what an MCP client could reach: which tools,
 * resources and prompts exist, which module owns each, and what a caller needs
 * to use it. Read it the way route:list is read -- the rows that say "any
 * user" are the ones to look at twice.
 *
 * This is also the production manifest's answer. The capabilities come from
 * module.php, which runs on every boot anyway, so there is no separate file to
 * build or go stale: what this lists is what is served.
 */
final class McpListCommand
{
    public function __construct(
        private readonly McpRegistry $registry,
        private readonly McpConfig $config,
    ) {}

    public function __invoke(Output $output, ?string $module = null): int
    {
        $output->pairs([
            'MCP' => $this->config->enabled ? 'enabled' : 'disabled (mcp.enabled)',
            'STDIO' => $this->config->stdioEnabled() ? 'php laika mcp:stdio --user=<login>' : 'off',
            'HTTP' => $this->config->httpEnabled() ? 'POST ' . $this->config->path : 'off',
            'Guests' => $this->config->allowGuests ? 'allowed' : 'refused',
        ]);
        $output->line();

        $capabilities = $this->registry->everything();

        if ($module !== null) {
            $capabilities = \array_values(\array_filter(
                $capabilities,
                static fn(Capability $capability): bool => $capability->module === $module,
            ));
        }

        if ($capabilities === []) {
            $output->line($module === null ? 'No module declares an MCP capability.' : \sprintf('No MCP capability belongs to "%s".', $module));

            return 0;
        }

        $output->table(
            ['KIND', 'NAME', 'MODULE', 'ACCESS', 'HANDLER'],
            \array_map(
                fn(Capability $capability): array => [
                    $capability->kind->value,
                    $capability->name,
                    $capability->module,
                    $this->access($capability),
                    $capability->handler,
                ],
                $capabilities,
            ),
        );

        return 0;
    }

    private function access(Capability $capability): string
    {
        if ($capability->permission !== null) {
            return $capability->permission;
        }

        return $this->config->allowGuests ? 'anyone' : 'any user';
    }
}
