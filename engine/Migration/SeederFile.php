<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Module\ModuleDefinition;

/**
 * A seeder's file, found in a module's Database/Seeders directory.
 *
 * Its name is its order within the module: `countries.php`, or
 * `01_countries.php` where one must run before another. Lower case, digits
 * and underscores, like a migration's.
 */
final class SeederFile
{
    public const DIRECTORY = 'Database/Seeders';

    public const PATTERN = '/^[a-z0-9_]+$/';

    public function __construct(
        public readonly string $module,
        public readonly string $name,
        public readonly string $path,
    ) {}

    /** "Billing:invoices". */
    public function id(): string
    {
        return $this->module . ':' . $this->name;
    }

    /**
     * A module's seeders, in the order they run.
     *
     * @return list<self>
     */
    public static function in(ModuleDefinition $module): array
    {
        $directory = $module->file(self::DIRECTORY);

        if (!\is_dir($directory)) {
            return [];
        }

        $paths = \glob($directory . '/*.php') ?: [];
        \sort($paths, \SORT_STRING);

        $files = [];

        foreach ($paths as $path) {
            $name = \basename($path, '.php');

            if (\preg_match(self::PATTERN, $name) !== 1) {
                throw MigrationException::badSeederName($module->id, \basename($path));
            }

            $files[] = new self($module->id, $name, $path);
        }

        return $files;
    }

    public function load(): Seeder
    {
        // A scope of its own, so the file sees no variables but its own.
        /** @var mixed $seeder */
        $seeder = (static fn(string $path): mixed => require $path)($this->path);

        if (!$seeder instanceof Seeder) {
            throw MigrationException::notASeeder($this->id(), \get_debug_type($seeder));
        }

        return $seeder;
    }
}
