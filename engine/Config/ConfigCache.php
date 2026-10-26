<?php

declare(strict_types=1);

namespace App\Engine\Config;

use App\Engine\Support\Path;

/**
 * The whole resolved configuration, written out as one PHP file.
 *
 * What it saves is real but modest: a directory scan, a handful of includes and
 * a recursive merge, on every request. What makes it worth writing down anyway
 * is that the result is a plain array in a file opcache already holds, so
 * reading it costs a single include of compiled bytecode.
 *
 * The well-known failure of this idea is worth stating plainly, because it has
 * cost a lot of people an afternoon. Config files read environment variables.
 * Caching them freezes the values those variables had at build time. Change the
 * variable afterwards and nothing happens -- silently, with no error and no
 * clue -- because the file that read it is no longer being read.
 *
 * So this cache carries a fingerprint: every variable Env was asked for while
 * the configuration was built, and what it answered. On load those are compared
 * against the environment as it is now, and a single difference makes the cache
 * stale and it is ignored. The environment changing is exactly the case that
 * ought to invalidate it, and the comparison is a few dozen string checks.
 *
 * That is more invalidation than the module cache gets, and deliberately: that
 * one would have to stat every module directory to know it was stale, which is
 * the work it exists to avoid. This one only has to read variables it has
 * already written down.
 *
 * Nothing else invalidates it. Editing a config file does not, because
 * detecting that means stat-ing every file, and a deployment that edits
 * configuration is a deployment, which runs cache:clear.
 */
final class ConfigCache
{
    public static function file(string $basePath): string
    {
        return Path::join($basePath, 'system/Cache/config.php');
    }

    /**
     * Read the cache, or null when there is nothing usable there.
     *
     * Never throws. A corrupt or half-written cache means "build the
     * configuration the slow way", not "the application does not start".
     *
     * @return array<string, mixed>|null
     */
    public static function read(string $file): ?array
    {
        if (!\is_file($file)) {
            return null;
        }

        /** @var mixed $data */
        $data = require $file;

        if (!\is_array($data) || !isset($data['items'], $data['env']) || !\is_array($data['items']) || !\is_array($data['env'])) {
            return null;
        }

        /** @var array<string, mixed> $environment */
        $environment = $data['env'];

        foreach ($environment as $name => $value) {
            if (Env::raw((string) $name) !== $value) {
                return null;
            }
        }

        /** @var array<string, mixed> $items */
        $items = $data['items'];

        return $items;
    }

    /**
     * Write it, with the environment it was built from.
     *
     * @param array<string, mixed>      $items
     * @param array<string, string|null> $environment
     */
    public static function write(string $file, array $items, array $environment): bool
    {
        self::assertPlainData($items);

        $directory = \dirname($file);

        if (!\is_dir($directory) && !\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            return false;
        }

        $contents = "<?php\n\n"
            . "// Generated configuration cache. Delete this file, or run cache:clear, to rebuild it.\n"
            . "// It is ignored automatically if any environment variable listed below has changed.\n\n"
            . 'return ' . \var_export(['env' => $environment, 'items' => $items], true) . ";\n";

        // Write and rename, so a concurrent request never includes a file that
        // is halfway written.
        $temporary = $file . '.' . \getmypid() . '.tmp';

        if (\file_put_contents($temporary, $contents, \LOCK_EX) === false) {
            return false;
        }

        if (!\rename($temporary, $file)) {
            @\unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Refuse to cache anything that is not data.
     *
     * A closure or an object in a config file survives being written by
     * var_export() and then fails on the way back in, as a fatal error inside a
     * generated file nobody has read. Better to refuse at the point somebody
     * asked, naming the key, while the person who wrote it is standing there.
     *
     * @param array<array-key, mixed> $items
     */
    public static function assertPlainData(array $items, string $prefix = ''): void
    {
        /** @var mixed $value */
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                self::assertPlainData($value, $path);

                continue;
            }

            if ($value !== null && !\is_scalar($value)) {
                throw ConfigurationException::notPlainData($path, \get_debug_type($value));
            }
        }
    }
}
