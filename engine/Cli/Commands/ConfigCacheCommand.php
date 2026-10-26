<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Bootstrap\Bootstrap;
use App\Engine\Cli\Output;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\Env;
use App\Engine\Core\Application;

/**
 * Compile the configuration into one file.
 *
 * A deployment step, and the reason it is a step rather than a default is that
 * a cache with a stale-until-cleared contract should be something somebody
 * asked for. Once it exists it is used, which is why there is no setting to
 * switch it on: the setting would have to be read out of the configuration this
 * builds.
 *
 * The build deliberately re-reads everything from disk rather than writing out
 * the configuration this very process is running with. The running one has the
 * bootstrap's own overrides mixed in and may itself have come from an older
 * cache, and writing that back would compound both. It also runs with a cleared
 * environment log, so the fingerprint that ends up in the file describes the
 * variables this configuration genuinely depends on rather than every variable
 * the process has looked at.
 */
final class ConfigCacheCommand
{
    public function __construct(private readonly Application $application) {}

    public function __invoke(Output $output, bool $clear = false): int
    {
        $file = ConfigCache::file($this->application->basePath());

        if ($clear) {
            if (!\is_file($file)) {
                $output->line('Nothing cached.');

                return 0;
            }

            if (!@\unlink($file)) {
                $output->error('The cache file exists and could not be deleted: ' . $file);

                return 1;
            }

            $output->success('Configuration cache cleared.');

            return 0;
        }

        $built = $this->build();

        if ($built === null) {
            $output->error('The cache could not be written to ' . $file);

            return 1;
        }

        ['items' => $items, 'environment' => $fingerprint] = $built;

        $output->success('Configuration cached.');
        $output->pairs([
            'File' => $file,
            'Top-level keys' => \implode(', ', \array_keys($items)),
            'Environment' => $fingerprint === []
                ? 'no variables were read'
                : \implode(', ', \array_keys($fingerprint)),
        ]);

        $output->line();
        $output->line('Editing a config file will not invalidate this. Run cache:clear when you deploy one.');
        $output->line('Changing any environment variable above will: the cache notices and is ignored.');

        return 0;
    }

    /**
     * Read the configuration from disk and write it to the cache.
     *
     * Public because cache:warm builds the same file, and two copies of "which
     * environment log to clear before reading" would drift apart.
     *
     * @return array{items: array<string, mixed>, environment: array<string, string|null>}|null
     *         null when the file could not be written
     */
    public function build(): ?array
    {
        Env::forget();
        $items = Bootstrap::settings($this->application->basePath(), cached: false);
        $fingerprint = Env::reads();

        if (!ConfigCache::write(ConfigCache::file($this->application->basePath()), $items, $fingerprint)) {
            return null;
        }

        return ['items' => $items, 'environment' => $fingerprint];
    }
}
