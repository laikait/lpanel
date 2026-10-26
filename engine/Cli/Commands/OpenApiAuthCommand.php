<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Cli\ConsoleKernel;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\OpenApi\OpenApiState;

/**
 * Whether the API docs page and description need a login.
 *
 *     php laika openapi:auth --enable                 // anybody signed in
 *     php laika openapi:auth --enable --can=api.docs  // only with that capability
 *     php laika openapi:auth --disable                // anyone
 *     php laika openapi:auth                          // what is set, and who decided
 *
 * It sets the auth or can part of openapi.meta and leaves the rest -- a
 * rate_limit, say -- as configured. A capability nothing declares is refused
 * here, because it would refuse everybody at /docs and look like a fault.
 */
final class OpenApiAuthCommand
{
    public function __construct(
        private readonly OpenApiState $state,
        private readonly Config $config,
        private readonly AccessRegistry $access,
    ) {}

    public function __invoke(Output $output, bool $enable = false, bool $disable = false, ?string $can = null): int
    {
        if ($enable && $disable) {
            $output->error('--enable and --disable together: choose one.');

            return ConsoleKernel::USAGE;
        }

        if ($can !== null && !$enable) {
            $output->error('--can goes with --enable.');

            return ConsoleKernel::USAGE;
        }

        if ($enable || $disable) {
            $can = $can === null || \trim($can) === '' ? null : \trim($can);

            if ($can !== null && !$this->access->hasPermission($can)) {
                $output->error(AuthException::undeclaredCapability($can, 'php laika openapi:auth --can')->getMessage());

                return ConsoleKernel::FAILURE;
            }

            try {
                $this->state->auth($enable, $can);
            } catch (\RuntimeException $e) {
                $output->error($e->getMessage());

                return ConsoleKernel::FAILURE;
            }

            $output->success($enable ? 'The API docs now need ' . ($can !== null ? 'the capability ' . $can : 'a login') . '.' : 'The API docs no longer need a login.');
        }

        OpenApiDocsCommand::describe($output, $this->config, $this->state);

        return ConsoleKernel::SUCCESS;
    }
}
