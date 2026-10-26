<?php

declare(strict_types=1);

namespace App\Engine\Config;

/**
 * Dot-notation configuration store.
 *
 * The store itself, and nothing else: where values come from is ConfigLoader,
 * DotEnv and Env's business, and this class never learns which of them supplied
 * what. That separation is what let the Configuration phase add files, a .env
 * and a cache without touching a single call site -- get(), has(), set() and
 * merge() are the same four methods they were at phase zero.
 *
 * On top of them sit the typed readers, and they do not coerce. A value that is
 * present but of the wrong type is a mistake somebody just made in a file they
 * have open, and saying so beats a defensive fallback that turns
 * "retention_days" => "30" into zero and keeps the logs forever. The
 * environment is the one place a setting legitimately arrives as text, and Env
 * parses it there, once.
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $value = $this->items;

        foreach (\explode('.', $key) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();

        return $this->get($key, $missing) !== $missing;
    }

    public function set(string $key, mixed $value): void
    {
        if ($key === '') {
            return;
        }

        $segments = \explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !\is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    // ---- typed retrieval ----------------------------------------------------

    public function string(string $key, ?string $default = null): ?string
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return \is_string($value) ? $value : throw ConfigurationException::wrongType($key, 'string', $value);
    }

    public function int(string $key, ?int $default = null): ?int
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return \is_int($value) ? $value : throw ConfigurationException::wrongType($key, 'int', $value);
    }

    public function float(string $key, ?float $default = null): ?float
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        // An int where a float is wanted is the one widening PHP does without
        // losing anything, and writing 30 instead of 30.0 is not a mistake.
        if (\is_int($value)) {
            return (float) $value;
        }

        return \is_float($value) ? $value : throw ConfigurationException::wrongType($key, 'float', $value);
    }

    public function bool(string $key, bool $default = false): bool
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return \is_bool($value) ? $value : throw ConfigurationException::wrongType($key, 'bool', $value);
    }

    /**
     * @param array<array-key, mixed> $default
     *
     * @return array<array-key, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        /** @var mixed $value */
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return \is_array($value) ? $value : throw ConfigurationException::wrongType($key, 'array', $value);
    }

    /**
     * A list of strings, which is what most plural settings are: which writers
     * to attach, which keys to redact, which proxies to trust.
     *
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function strings(string $key, array $default = []): array
    {
        $value = $this->array($key, $default);

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw ConfigurationException::notAListOfStrings($key);
            }
        }

        /** @var list<string> */
        return \array_values($value);
    }

    // ---- writing -------------------------------------------------------------

    /**
     * Merge a block of values under a namespace, recursively, without
     * clobbering sibling keys.
     *
     * @param array<string, mixed> $values
     */
    public function merge(string $namespace, array $values): void
    {
        /** @var mixed $existing */
        $existing = $this->get($namespace);

        $this->set(
            $namespace,
            \is_array($existing) ? self::mergeArrays($existing, $values) : $values,
        );
    }

    /**
     * Merge a block of values under a namespace, keeping anything already
     * configured.
     *
     * This is what a module's config() declaration is: defaults, not decisions.
     * A module ships sensible values for its own settings and the application
     * overrides the ones it cares about, in config/Billing.php, named
     * after the module.
     *
     * The direction matters and the two are easy to confuse. Modules register
     * long after the files are read, so merging their values the ordinary way
     * would have every module quietly overwrite whatever the application had
     * configured for it -- and the symptom is a config file that appears to do
     * nothing.
     *
     * @param array<string, mixed> $values
     */
    public function defaults(string $namespace, array $values): void
    {
        /** @var mixed $existing */
        $existing = $this->get($namespace);

        $this->set(
            $namespace,
            \is_array($existing) ? self::mergeArrays($values, $existing) : $existing ?? $values,
        );
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overrides
     *
     * @return array<array-key, mixed>
     */
    private static function mergeArrays(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = self::mergeArrays($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
