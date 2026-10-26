<?php

declare(strict_types=1);

namespace App\Engine\Module;

use App\Engine\Support\Path;

/**
 * What discovery learns about a module without running any of its code.
 *
 * Everything here is a plain scalar, which is the whole point: this is the
 * artefact a discovery cache stores, so it must survive var_export() and come
 * back identical.
 *
 * Three of the fields are answers to filesystem questions -- does the module
 * have an assets/ directory, a Templates/ one, a lang/ one -- asked once, here,
 * rather than by registration on every request. They are facts about the
 * directory in the same way the path is, which is why they belong in the cache
 * and why nothing resolved or declared does.
 */
final class ModuleDefinition
{
    /** The one module every application has, and the only one with a fixed name. */
    public const SHARED = 'Shared';

    /**
     * What a module's directory may be called: a PHP namespace segment that
     * starts with a capital, because a module's directory name is also the
     * namespace segment its classes live under.
     *
     * The capital is not decoration. The id is also the module's configuration
     * namespace (config/Billing.php) and every framework namespace is lower
     * case, so the two can never collide.
     */
    public const NAME_PATTERN = '/^[A-Z][A-Za-z0-9_]*$/';

    /** The directory whose presence publishes a module's assets. */
    public const ASSETS = 'assets';

    /** The directory whose presence registers a module's templates. */
    public const TEMPLATES = 'Templates';

    /** The directory whose presence registers a module's translations. */
    public const LANG = 'lang';

    public function __construct(
        public readonly string $id,
        public readonly ModuleKind $kind,
        public readonly string $path,
        public readonly string $entryFile,
        public readonly string $directory,
        public readonly bool $hasAssets = false,
        public readonly bool $hasTemplates = false,
        public readonly bool $hasLang = false,
    ) {}

    /**
     * The id is the directory name: modules/Billing is "Billing", and
     * modules/Shared is "Shared", the shared module.
     */
    public static function create(
        string $path,
        string $directory,
        bool $hasAssets = false,
        bool $hasTemplates = false,
        bool $hasLang = false,
    ): self {
        $path = Path::normalize($path);

        return new self(
            id: $directory,
            kind: ModuleKind::of($directory),
            path: $path,
            entryFile: $path . '/module.php',
            directory: $directory,
            hasAssets: $hasAssets,
            hasTemplates: $hasTemplates,
            hasLang: $hasLang,
        );
    }

    public function file(string $relative = ''): string
    {
        return $relative === '' ? $this->path : Path::join($this->path, $relative);
    }

    /** The sort key that makes module order a deterministic total order. */
    public function sortKey(): string
    {
        return \sprintf('%d:%s', $this->kind->rank(), $this->directory);
    }

    /** @return array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool, lang: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'path' => $this->path,
            'entryFile' => $this->entryFile,
            'directory' => $this->directory,
            'assets' => $this->hasAssets,
            'templates' => $this->hasTemplates,
            'lang' => $this->hasLang,
        ];
    }

    /** @param array{id: string, kind: string, path: string, entryFile: string, directory: string, assets: bool, templates: bool, lang: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            kind: ModuleKind::from($data['kind']),
            path: $data['path'],
            entryFile: $data['entryFile'],
            directory: $data['directory'],
            hasAssets: $data['assets'],
            hasTemplates: $data['templates'],
            hasLang: $data['lang'],
        );
    }

    /**
     * Whether a value read back from a cache file is a definition this version
     * can use.
     *
     * A cache written by an older version lacks fields, and one edited by hand
     * may have anything. Either way the answer is "scan instead", never a
     * TypeError from inside a generated file nobody is looking at.
     */
    public static function isCachedShape(mixed $data): bool
    {
        if (!\is_array($data)) {
            return false;
        }

        foreach (['id', 'kind', 'path', 'entryFile', 'directory'] as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field])) {
                return false;
            }
        }

        return ModuleKind::tryFrom($data['kind']) !== null
            && \is_bool($data['assets'] ?? null)
            && \is_bool($data['templates'] ?? null)
            && \is_bool($data['lang'] ?? null);
    }
}
