<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cache\Cache;
use App\Engine\Cache\PrunableStore;
use App\Engine\Cli\Output;
use App\Engine\Config\ConfigCache;
use App\Engine\Core\Application;
use App\Engine\Module\ModuleRegistry;
use App\Engine\Support\Path;

/**
 * Empty the caches that have no automatic invalidation.
 *
 * All of them are stale-until-cleared by design: the module discovery cache is
 * a list of directories that only changes when somebody adds a module, the
 * configuration cache is a merge of files that only changes when somebody edits
 * one, Twig's compiled templates are only cached when an application asks, and
 * the application cache holds whatever the application put there. Checking any
 * of them for freshness on every request would cost the filesystem work the
 * cache exists to avoid, so the invalidation story is this command, and
 * deployment runs it -- followed by cache:warm, which builds the two that make
 * up the production boot path.
 *
 * --expired is the gentler half, and only the application cache has one: it
 * removes entries whose TTL has passed and leaves everything else warm, which
 * is a job for a nightly schedule rather than for a deployment. Nothing else
 * here has a TTL to have passed.
 *
 * The configuration cache is the one exception, and only for one thing: it
 * carries the environment variables it was built from and ignores itself when
 * one of them has changed. See ConfigCache for why that case is worth the
 * comparison and the others are not.
 *
 * Only paths under system/Cache are touched, and containment is verified rather
 * than assumed -- a command that deletes things should not take the base path's
 * word for it.
 */
final class CacheClearCommand
{
    public function __construct(
        private readonly Application $application,
        private readonly Cache $cache,
    ) {}

    public function __invoke(Output $output, bool $expired = false): int
    {
        $root = $this->application->basePath('system/Cache');
        $cleared = 0;

        // The application cache goes through its own store rather than by
        // deleting files, because the store is not always files. Asking it is
        // the only version of this that stays correct when somebody configures
        // something else.
        $store = $this->cache->store();

        if ($expired) {
            if (!$store instanceof PrunableStore) {
                $output->line(\sprintf('  %-10s %s expires its own entries', 'cache', $store->describe()));

                return 0;
            }

            $removed = $store->prune();

            $output->success(\sprintf('%d expired entr%s removed.', $removed, $removed === 1 ? 'y' : 'ies'));

            return 0;
        }

        $output->line(\sprintf('  %-10s %s', 'cache', $store->describe()));
        $this->cache->clear();

        foreach ([
            'config' => ConfigCache::file($this->application->basePath()),
            'modules' => ModuleRegistry::cacheFile($this->application->basePath()),
            'templates' => Path::join($root, 'templates'),
            'data' => Path::join($root, 'data'),
        ] as $label => $path) {
            if (!\file_exists($path)) {
                $output->line(\sprintf('  %-10s nothing cached', $label));

                continue;
            }

            if (!Path::within($root, $path)) {
                $output->warning(\sprintf('  %-10s skipped: %s is not inside system/Cache', $label, $path));

                continue;
            }

            $removed = \is_dir($path) ? $this->removeDirectory($path) : (int) @\unlink($path);
            $cleared += $removed;

            $output->line(\sprintf('  %-10s cleared (%d file%s)', $label, $removed, $removed === 1 ? '' : 's'));
        }

        $output->success($cleared === 0 ? 'Nothing to clear.' : 'Caches cleared.');

        return 0;
    }

    /** @return int the number of files removed */
    private function removeDirectory(string $directory): int
    {
        $removed = 0;

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @\rmdir($entry->getPathname());

                continue;
            }

            $removed += (int) @\unlink($entry->getPathname());
        }

        @\rmdir($directory);

        return $removed;
    }
}
