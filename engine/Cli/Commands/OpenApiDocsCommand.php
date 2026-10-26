<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\OpenApi\OpenApiException;
use App\Engine\OpenApi\OpenApiRoutes;
use App\Engine\OpenApi\OpenApiState;

/**
 * Switch the API docs page, and the description it reads, on or off.
 *
 *     php laika openapi:docs --enable                 // /docs and /openapi.json
 *     php laika openapi:docs --enable --path=/api-docs
 *     php laika openapi:docs --disable
 *     php laika openapi:docs                          // what is on, and who decided
 *
 * Both together, because the page is nothing without the description. The
 * switch is written to system/Runtime/openapi.json (see OpenApiState) and
 * wins over config/openapi.php until it is switched again. One machine at a
 * time, like `down`.
 *
 * In production, switching the page on with no login is allowed -- and said,
 * on stderr, with the command that fixes it. security:check fails it too.
 */
final class OpenApiDocsCommand
{
    public function __construct(
        private readonly OpenApiState $state,
        private readonly Config $config,
    ) {}

    public function __invoke(Output $output, bool $enable = false, bool $disable = false, ?string $path = null, ?string $spec = null): int
    {
        if ($enable && $disable) {
            $output->error('--enable and --disable together: choose one.');

            return ConsoleKernel::USAGE;
        }

        if (!$this->state->isReadable()) {
            $output->warning(\sprintf('%s cannot be read, so it is ignored. Switching again rewrites it.', $this->state->path()));
        }

        if ($enable || $disable) {
            $path = OpenApiRoutes::path($path);
            $spec = OpenApiRoutes::path($spec);

            if ($enable && $path !== null && $path === $spec) {
                $output->error(OpenApiException::docsOverRoute($path)->getMessage());

                return ConsoleKernel::FAILURE;
            }

            try {
                $this->state->docs($enable, $path, $spec);
            } catch (\RuntimeException $e) {
                $output->error($e->getMessage());

                return ConsoleKernel::FAILURE;
            }

            $output->success($enable ? 'The API docs are on.' : 'The API docs are off.');
        }

        self::describe($output, $this->config, $this->state);

        return ConsoleKernel::SUCCESS;
    }

    /** The effective switches, after the file, and where each came from. Shared with openapi:auth. */
    public static function describe(Output $output, Config $config, OpenApiState $state): void
    {
        $current = $state->read();
        $openapi = OpenApiState::overlay($config->all(), $current)['openapi'] ?? [];
        $openapi = \is_array($openapi) ? $openapi : [];

        $spec = OpenApiRoutes::path(\is_string($openapi['route'] ?? null) ? $openapi['route'] : null);
        $docs = OpenApiRoutes::path(\is_string($openapi['docs'] ?? null) ? $openapi['docs'] : null);
        $meta = \is_array($openapi['meta'] ?? null) ? $openapi['meta'] : [];
        $can = \is_string($meta['can'] ?? null) ? $meta['can'] : null;
        $guarded = OpenApiRoutes::isGuarded($meta);
        $runtime = $config->bool('openapi.runtime', true);

        $from = static fn(string $key): string => !$runtime
            ? 'config (openapi.runtime is false, so the switches are ignored)'
            : (isset($current[$key]) ? 'php laika openapi:' . $key : 'config');

        $output->pairs([
            'Docs page' => $docs ?? 'off',
            'Description' => $spec ?? 'off',
            'Access' => $guarded ? ($can !== null ? 'the capability ' . $can : 'a login') : 'anyone',
            'Docs set by' => $from('docs'),
            'Access set by' => $from('auth'),
        ]);

        if ($spec !== null && !$guarded && $config->string('app.env', 'production') === 'production') {
            $output->warning('Anyone can read the API description in production. Require a login with: php laika openapi:auth --enable');
        }
    }
}
