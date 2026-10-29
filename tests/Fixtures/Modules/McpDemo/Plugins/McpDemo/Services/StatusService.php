<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\McpDemo\Plugins\McpDemo\Services;

use App\Engine\Config\Config;
use App\Engine\Core\Application;
use App\Engine\Module\ModuleRegistry;

/**
 * What the demo knows about the application. The MCP tool, the resource and
 * any route or command would all ask this, rather than each other.
 */
final class StatusService
{
    private int $calls = 0;

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly Config $config,
    ) {}

    public function recordCall(): void
    {
        ++$this->calls;
    }

    /** @return array{framework: string, php: string, environment: string, modules: int, tool_calls: int} */
    public function status(): array
    {
        return [
            'framework' => Application::VERSION,
            'php' => \PHP_VERSION,
            'environment' => $this->config->string('app.env', 'production') ?? 'production',
            'modules' => $this->modules->count(),
            'tool_calls' => $this->calls,
        ];
    }
}
