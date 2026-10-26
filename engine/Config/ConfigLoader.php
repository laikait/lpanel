<?php

declare(strict_types=1);

namespace App\Engine\Config;

/**
 * The config/ directory: PHP files that return arrays.
 *
 * PHP rather than YAML, JSON or INI, because the file is read by PHP, opcache
 * already caches it, a typo is a parse error at the line it happened on, and an
 * editor can complete the constants it references. A format that needs a parser
 * buys nothing here except a parser.
 *
 * The filename is the namespace. config/database.php lands under "database",
 * so the file somebody opens to change a connection is the one named after it.
 * A module is configured the same way: config/Billing.php lands under
 * "Billing", the module's own id, and overrides the defaults that module
 * declares. Module ids start with a capital and framework namespaces do not,
 * so the two never collide. Subdirectories join with a slash. The mapping is that
 * direct on purpose -- a lookup table from file to key is a thing to maintain
 * and to get wrong.
 *
 * Nothing here is required to exist. An application with no config/ directory
 * runs on the framework's defaults and the environment, which is what makes
 * this phase an addition rather than a new obligation.
 */
final class ConfigLoader
{
    public const DIRECTORY = 'config';
    public const EXTENSION = '.php';

    public function __construct(private readonly string $directory) {}

    public function exists(): bool
    {
        return \is_dir($this->directory);
    }

    /**
     * Every configuration file, as namespace => relative path, sorted.
     *
     * Sorted because two files may contribute to the same tree and the result
     * must not depend on the order a filesystem happens to return directory
     * entries in. It is the same rule module loading follows.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($this->directory) + 1));
            $namespace = \substr($relative, 0, -\strlen(self::EXTENSION));

            if ($namespace === '' || \str_starts_with($namespace, '.')) {
                continue;
            }

            $files[$namespace] = $relative;
        }

        \ksort($files);

        return $files;
    }

    /**
     * Read them all.
     *
     * @return array<string, mixed> namespace => values
     */
    public function load(): array
    {
        $items = [];

        foreach ($this->files() as $namespace => $relative) {
            $path = $this->directory . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $relative);

            /** @var mixed $values */
            $values = self::read($path);

            if (!\is_array($values)) {
                throw ConfigurationException::fileReturnedNoArray(
                    self::DIRECTORY . '/' . $relative,
                    \get_debug_type($values),
                );
            }

            // Nested namespaces are single keys containing a slash, not a path
            // through the tree: "reports/daily" is one namespace. Config
            // splits on dots, so this stays a single segment by construction.
            $items[$namespace] = $values;
        }

        return $items;
    }

    /**
     * Include a file with nothing of ours in scope.
     *
     * A static closure, so the file cannot reach $this, and no local variables
     * beyond the path, so it cannot accidentally read one of ours. A config
     * file is data that happens to be written in PHP.
     */
    private static function read(string $path): mixed
    {
        return (static fn(string $file): mixed => require $file)($path);
    }
}
