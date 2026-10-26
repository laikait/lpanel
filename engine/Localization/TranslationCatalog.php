<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Support\Path;

/**
 * Where translations live: the application's lang/ and every module's.
 *
 * The root directory has no namespace, so its keys are bare: 'updated'. A
 * module's directory is registered under the module's id -- 'Shared',
 * 'Billing' -- so its keys are qualified by it: 'Billing.invoice_created',
 * exactly as its templates are '@Billing/...'. Names are unique because
 * module ids are, and a module that is removed or disabled is never
 * registered, so its keys simply stop resolving.
 *
 * Which locales a directory has is whatever <locale>.php files are in it,
 * read once per directory the first time it is asked about. There is no list
 * of supported languages to keep in step with the files: the files are the
 * list.
 */
final class TranslationCatalog
{
    /** The namespace of the application's own lang/ directory. */
    public const ROOT = '';

    /** @var array<string, string> namespace => directory */
    private array $directories = [];

    /** @var array<string, list<string>> "namespace|subdirectory" => locales, filled on first use */
    private array $locales = [];

    public function __construct(?string $root = null)
    {
        if ($root !== null) {
            $this->directories[self::ROOT] = Path::normalize($root);
        }
    }

    /** @throws LocalizationException when $namespace is already registered */
    public function add(string $namespace, string $directory): void
    {
        if ($namespace === self::ROOT || isset($this->directories[$namespace])) {
            throw LocalizationException::duplicateNamespace($namespace);
        }

        $this->directories[$namespace] = Path::normalize($directory);
        $this->locales = \array_filter(
            $this->locales,
            static fn(string $key): bool => !\str_starts_with($key, $namespace . '|'),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    public function has(string $namespace): bool
    {
        return isset($this->directories[$namespace]);
    }

    /** @return list<string> module namespaces, without the root */
    public function namespaces(): array
    {
        $namespaces = \array_values(\array_filter(
            \array_keys($this->directories),
            static fn(string $namespace): bool => $namespace !== self::ROOT,
        ));
        \sort($namespaces);

        return $namespaces;
    }

    public function directory(string $namespace = self::ROOT): ?string
    {
        return $this->directories[$namespace] ?? null;
    }

    /**
     * The locales of $namespace, or of its sub-directory $subdirectory
     * ('admin' for lang/admin/<locale>.php).
     *
     * @return list<string>
     */
    public function locales(string $namespace = self::ROOT, string $subdirectory = ''): array
    {
        $key = $namespace . '|' . $subdirectory;

        if (isset($this->locales[$key])) {
            return $this->locales[$key];
        }

        $directory = $this->path($namespace, $subdirectory);

        return $this->locales[$key] = $this->scan($directory);
    }

    public function hasLocale(string $locale, string $namespace = self::ROOT, string $subdirectory = ''): bool
    {
        return \in_array($locale, $this->locales($namespace, $subdirectory), true);
    }

    /** Whether $subdirectory is a well-formed relative path to a directory inside $namespace's. */
    public function hasDirectory(string $subdirectory, string $namespace = self::ROOT): bool
    {
        return $subdirectory !== '' && $this->path($namespace, $subdirectory) !== null;
    }

    /**
     * The file for $locale in $namespace, or null when there is none.
     *
     * The name is built only from a locale this catalog found on disk and a
     * sub-directory that passed the pattern in path(), never from the argument
     * as given, so no input can make it point anywhere else.
     */
    public function file(string $namespace, string $locale, string $subdirectory = ''): ?string
    {
        $directory = $this->path($namespace, $subdirectory);

        if ($directory === null || !$this->hasLocale($locale, $namespace, $subdirectory)) {
            return null;
        }

        return Path::join($directory, $locale . '.php');
    }

    /** The directory of $namespace plus $subdirectory, or null when unknown, malformed or missing. */
    private function path(string $namespace, string $subdirectory): ?string
    {
        $directory = $this->directories[$namespace] ?? null;

        if ($directory === null) {
            return null;
        }

        if ($subdirectory === '') {
            return $directory;
        }

        // Plain names joined by single slashes: no "..", no ".", no empty
        // segment, no backslash, no NUL, nothing a path could climb with.
        if (\preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $subdirectory) !== 1) {
            return null;
        }

        $path = Path::join($directory, $subdirectory);

        return \is_dir($path) ? $path : null;
    }

    /**
     * Which namespace a key belongs to, and the key within it.
     *
     *     'Billing.invoice_created' → ['Billing', 'invoice_created']
     *     'Shared.welcome'          → ['Shared', 'welcome']
     *     'updated'                 → ['', 'updated']
     *
     * A dotted key whose prefix is no registered module is a root key.
     *
     * @return array{string, string}
     */
    public function split(string $key): array
    {
        $segments = \explode('.', $key, 2);

        if (\count($segments) === 2 && $segments[0] !== self::ROOT && $this->has($segments[0])) {
            return [$segments[0], $segments[1]];
        }

        return [self::ROOT, $key];
    }

    /** @return list<string> */
    private function scan(?string $directory): array
    {
        if ($directory === null || !\is_dir($directory)) {
            return [];
        }

        $locales = [];

        foreach (\scandir($directory) ?: [] as $entry) {
            if (!\str_ends_with($entry, '.php')) {
                continue;
            }

            $locale = \substr($entry, 0, -4);

            // Only a file named exactly as its canonical tag, so that
            // countries.php, a backup or pt_BR.php is never taken for one.
            if (Locale::normalize($locale) === $locale && \is_file(Path::join($directory, $entry))) {
                $locales[] = $locale;
            }
        }

        \sort($locales);

        return $locales;
    }
}
