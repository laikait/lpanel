<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\ConnectionManager;
use App\Engine\Module\ModuleRegistry;

/**
 * Runs each module's seeders: in module order, as migrations run, and each
 * module's files in the order their names sort.
 *
 * Every seeder is loaded before any runs, so a broken file stops the run
 * before it starts. Each runs in a transaction of its own -- rows, unlike
 * tables, every database can take back -- so a failing seeder leaves nothing
 * of itself; the ones before it have committed.
 */
final class SeedRunner
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param string|null $module only this enabled module's seeders
     * @param (\Closure(SeederFile, int): void)|null $ran told after each seeder: the file, the nanoseconds
     *
     * @return int how many ran
     */
    public function seed(?string $connection = null, ?string $module = null, ?\Closure $ran = null): int
    {
        $db = $this->connections->connection($connection);

        /** @var list<array{SeederFile, Seeder}> $seeders */
        $seeders = [];

        foreach ($this->files($module) as $file) {
            $seeders[] = [$file, $file->load()];
        }

        foreach ($seeders as [$file, $seeder]) {
            $started = \hrtime(true);

            try {
                $db->transaction(static fn() => $seeder->run($db));
            } catch (\Throwable $e) {
                throw MigrationException::seedFailed($file->id(), $db->driver(), $e);
            }

            if ($ran !== null) {
                $ran($file, (int) (\hrtime(true) - $started));
            }
        }

        return \count($seeders);
    }

    /** @return list<SeederFile> */
    private function files(?string $module): array
    {
        if ($module !== null) {
            $definition = $this->modules->isEnabled($module) ? $this->modules->definition($module) : null;

            if ($definition === null) {
                throw MigrationException::unknownModule($module, $this->modules->ids());
            }

            return SeederFile::in($definition);
        }

        $files = [];

        foreach ($this->modules->definitions() as $definition) {
            \array_push($files, ...SeederFile::in($definition));
        }

        return $files;
    }
}
