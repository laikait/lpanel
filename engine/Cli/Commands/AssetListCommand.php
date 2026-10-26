<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Asset\AssetManager;
use App\Engine\Asset\AssetRegistry;
use App\Engine\Asset\AssetSource;
use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Support\Path;

/**
 * What is published, and where each namespace points.
 *
 * The filesystem root is shown because this command's whole job is to answer
 * "why is my asset 404ing", and the answer is nearly always that the directory
 * is not where the developer thought. A URL never carries this, which is the
 * distinction the asset layer is built on -- but somebody running a console
 * command on their own machine has already read the directory listing.
 */
final class AssetListCommand
{
    public function __construct(
        private readonly AssetRegistry $assets,
        private readonly Application $application,
    ) {}

    public function __invoke(Output $output): int
    {
        $sources = $this->assets->all();

        if ($sources === []) {
            $output->line('No asset directories are published.');

            return 0;
        }

        $output->table(
            ['URL PREFIX', 'KIND', 'NAME', 'DIRECTORY', 'PRESENT'],
            \array_map(
                fn(AssetSource $source): array => [
                    '/' . AssetManager::PREFIX . '/' . $source->key(),
                    $source->kind->value,
                    $source->name ?? '-',
                    Path::relativeTo($this->application->basePath(), $source->root),
                    $source->exists() ? 'yes' : 'no',
                ],
                $sources,
            ),
        );

        return 0;
    }
}
