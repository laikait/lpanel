<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Module\ModuleDefinition;

/**
 * A migration's file, found in a module's Database/Migrations directory.
 *
 * Its name is its order: `YYYY_MM_DD_HHMMSS_what_it_does.php`, written by hand
 * -- there is no generator -- and checked here. A file that breaks the rule
 * stops the command rather than being skipped, because a skipped migration is a
 * table that silently never exists.
 */
final class MigrationFile
{
    public const DIRECTORY = 'Database/Migrations';

    public const PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/';

    public function __construct(
        public readonly string $module,
        public readonly string $name,
        public readonly string $path,
        private readonly ?Migration $supplied = null,
    ) {}

    /**
     * One of the framework's own migrations, which is an object the bootstrap
     * builds rather than a file -- it takes settings a file could not see, such
     * as the table's configured name.
     */
    public static function supplied(string $module, string $name, Migration $migration): self
    {
        return new self($module, $name, '', $migration);
    }

    /** "Billing:2026_09_19_120000_create_invoices": unique across the application. */
    public function id(): string
    {
        return $this->module . ':' . $this->name;
    }

    /**
     * A module's migrations, in the order they run.
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
                throw MigrationException::badName($module->id, \basename($path));
            }

            $files[] = new self($module->id, $name, $path);
        }

        return $files;
    }

    public function load(): Migration
    {
        if ($this->supplied !== null) {
            return $this->supplied;
        }

        // A scope of its own, so the file sees no variables but its own.
        /** @var mixed $migration */
        $migration = (static fn(string $path): mixed => require $path)($this->path);

        if (!$migration instanceof Migration) {
            throw MigrationException::notAMigration($this->id(), \get_debug_type($migration));
        }

        return $migration;
    }
}
