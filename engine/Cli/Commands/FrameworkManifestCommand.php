<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Core\Application;
use App\Engine\Update\Manifest;

/**
 * Build framework.json for a release: every file, its hash and whose it is.
 *
 * Run by the release workflow over the tree `git archive` produced, so what
 * .gitattributes leaves out is left out here too. Over a working copy it
 * would also list untracked files, which is why --from exists.
 */
final class FrameworkManifestCommand
{
    public function __construct(private readonly Application $application) {}

    public function __invoke(Output $output, ?string $from = null, ?string $write = null, ?string $release = null): int
    {
        $root = $from === null || $from === '' ? $this->application->basePath() : $from;
        $manifest = Manifest::build($root, $release === null || $release === '' ? Application::VERSION : \ltrim($release, 'v'));

        if ($write === null || $write === '') {
            $output->write($manifest->toJson());

            return 0;
        }

        $manifest->write($write);

        $roles = \array_count_values(\array_column($manifest->files, 'role'));
        $output->success(\sprintf(
            'Wrote %s: %s, %d file(s) -- %d owned, %d seed, %d merged.',
            $write,
            $manifest->version,
            \count($manifest->files),
            $roles[Manifest::OWNED] ?? 0,
            $roles[Manifest::SEED] ?? 0,
            $roles[Manifest::MERGED] ?? 0,
        ));

        return 0;
    }
}
