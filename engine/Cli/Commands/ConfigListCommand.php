<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Config\ConfigCache;
use App\Engine\Config\ConfigLoader;
use App\Engine\Config\DotEnv;
use App\Engine\Core\Application;

/**
 * What the configuration actually resolved to.
 *
 * Configuration is assembled from four places -- the framework's defaults, the
 * files in config/, the environment those files read, and whatever the
 * bootstrap was handed -- and the question worth answering at three in the
 * morning is never "what does the file say", it is "what is this process
 * running with". That is this command.
 *
 * Values are flattened to the dotted keys Config::get() takes, so anything
 * printed here can be pasted straight into a call.
 *
 * A handful of key names are replaced with [hidden]. There is no flag to turn
 * that off: a flag like that exists to be used, and the place it gets used is a
 * terminal somebody is sharing their screen from. Whoever needs the password
 * can read the file it is in.
 */
final class ConfigListCommand
{
    /** Exact key names, not substrings. See Logging\Context for why that distinction matters. */
    public const HIDDEN = ['password', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'dsn'];

    public const MASK = '[hidden]';

    public function __construct(
        private readonly Config $config,
        private readonly Application $application,
    ) {}

    public function __invoke(Output $output, ?string $prefix = null, bool $sources = false): int
    {
        if ($sources) {
            $this->describeSources($output);
            $output->line();
        }

        $rows = [];

        foreach ($this->flatten($this->config->all()) as $key => $value) {
            if ($prefix !== null && !\str_starts_with($key, $prefix)) {
                continue;
            }

            $rows[] = [$key, $value];
        }

        if ($rows === []) {
            $output->warning($prefix === null
                ? 'There is no configuration at all, which should be impossible.'
                : \sprintf('No configuration key starts with "%s".', $prefix));

            return 1;
        }

        $output->table(['KEY', 'VALUE'], $rows);

        return 0;
    }

    private function describeSources(Output $output): void
    {
        $loader = new ConfigLoader($this->application->basePath(ConfigLoader::DIRECTORY));
        $files = $loader->files();
        $cache = ConfigCache::file($this->application->basePath());

        $output->pairs([
            'Files' => $files === []
                ? 'none (config/ is empty or absent)'
                : \implode(', ', \array_map(
                    static fn(string $relative): string => ConfigLoader::DIRECTORY . '/' . $relative,
                    $files,
                )),
            '.env' => \is_file($this->application->basePath(DotEnv::FILE))
                ? 'present, filling gaps in the environment'
                : 'none',
            'Cache' => \is_file($cache)
                ? 'in use -- run cache:clear after editing a file'
                : 'not built (config:cache builds it)',
        ]);
    }

    /**
     * Dotted keys, deepest last, with the values rendered as something a
     * terminal can show.
     *
     * @param array<array-key, mixed> $items
     *
     * @return array<string, string>
     */
    private function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];

        /** @var mixed $value */
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (\in_array(\strtolower((string) $key), self::HIDDEN, true)) {
                $flat[$path] = $value === null ? 'null' : self::MASK;

                continue;
            }

            if (\is_array($value)) {
                // An empty array has to print as itself; recursing produces no
                // row at all and the key appears to be missing.
                $flat += $value === [] ? [$path => '[]'] : $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = $this->render($value);
        }

        return $flat;
    }

    private function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => '[' . \get_debug_type($value) . ']',
        };
    }
}
