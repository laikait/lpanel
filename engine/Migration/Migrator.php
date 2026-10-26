<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\Capability;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\DatabaseException;
use App\Engine\Database\Structure\Tables;
use App\Engine\Module\ModuleRegistry;

/**
 * Runs each module's migrations, in order, once.
 *
 * **Order.** Modules in the order they register -- shared first, and every
 * module after the modules it requires() -- and each module's files in the
 * order their names sort. A migration that points a foreign key at another
 * module's table runs after it because its module requires that module, which
 * it already must to use anything of it.
 *
 * **Once.** What ran is recorded (see MigrationRepository). A migration and its
 * record are one transaction where the database can roll structure back
 * (Capability::TransactionalDdl), so a failure leaves neither. MySQL cannot,
 * and says so when it fails.
 *
 * **One run at a time.** A run holds a lock in the database itself for its
 * whole length, so two deployments starting together do not both run the same
 * migration. A lock file would stop two processes on one machine, not two
 * machines.
 */
final class Migrator
{
    /** What the framework's own migrations are recorded under, as a module's are under its id. */
    public const FRAMEWORK = 'framework';

    /** The lock every run takes, whichever connection it runs on. */
    public const LOCK = 'laika_migrations';

    /**
     * @param list<MigrationFile> $framework the framework's own migrations, for
     *                                        what it stores itself; they run first
     */
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ModuleRegistry $modules,
        private readonly string $table = 'migrations',
        private readonly int $lockWait = 10,
        private readonly array $framework = [],
    ) {}

    /**
     * Every migration of every enabled module, in the order they run, after
     * the framework's own.
     *
     * @return list<MigrationFile>
     */
    public function files(): array
    {
        $files = $this->framework;

        foreach ($this->modules->definitions() as $module) {
            \array_push($files, ...MigrationFile::in($module));
        }

        return $files;
    }

    /**
     * Every migration found, whether it ran and in which batch, and every
     * migration recorded whose file is gone. Changes nothing.
     *
     * @return list<array{id: string, batch: int|null, file: bool}>
     */
    public function status(?string $connection = null): array
    {
        $ran = $this->repository($this->connections->connection($connection))->ran();
        $status = [];

        foreach ($this->files() as $file) {
            $status[] = ['id' => $file->id(), 'batch' => $ran[$file->id()]['batch'] ?? null, 'file' => true];
            unset($ran[$file->id()]);
        }

        foreach ($ran as $id => $record) {
            $status[] = ['id' => $id, 'batch' => $record['batch'], 'file' => false];
        }

        return $status;
    }

    /**
     * Run every pending migration, as one batch.
     *
     * Pretending runs nothing and records nothing: each pending migration is
     * written for this connection's database, and $ran is handed the statements
     * it would have sent.
     *
     * @param (\Closure(MigrationFile, list<array{sql: string, raw: bool}>, int): void)|null $ran
     *        told after each migration: the file, its statements, the nanoseconds
     *
     * @return int how many ran
     */
    public function migrate(?string $connection = null, bool $pretend = false, ?\Closure $ran = null): int
    {
        $db = $this->connections->connection($connection);
        $repository = $this->repository($db);

        if ($pretend) {
            $pending = $this->pending($repository->ran());

            foreach ($pending as [$file, $migration]) {
                $tables = $db->tables(pretend: true);
                $migration->up($tables);

                if ($ran !== null) {
                    $ran($file, $tables->statements(), 0);
                }
            }

            return \count($pending);
        }

        return $this->locked($db, function () use ($db, $repository, $ran): int {
            if (!$repository->exists()) {
                $repository->create();
            }

            $recorded = $repository->ran();
            $pending = $this->pending($recorded);
            $batch = \max([0, ...\array_column($recorded, 'batch')]) + 1;
            $position = \max([0, ...\array_column($recorded, 'position')]);

            foreach ($pending as [$file, $migration]) {
                $this->apply($db, $file, static function (Tables $tables) use ($migration, $repository, $file, $batch, &$position): void {
                    $migration->up($tables);
                    $repository->record($file, $batch, ++$position);
                }, $ran);
            }

            return \count($pending);
        });
    }

    /**
     * Undo the last $batches batches, newest migration first.
     *
     * Everything is checked before anything is undone: every migration in the
     * range must still have its file, and must be Reversible. One that is not
     * refuses the whole rollback, rather than leaving it half done.
     *
     * @param (\Closure(MigrationFile, list<array{sql: string, raw: bool}>, int): void)|null $undone
     *
     * @return int how many were undone
     */
    public function rollback(?string $connection = null, int $batches = 1, ?\Closure $undone = null): int
    {
        if ($batches < 1) {
            throw new \InvalidArgumentException(\sprintf('Roll back at least one batch, not %d.', $batches));
        }

        $db = $this->connections->connection($connection);
        $repository = $this->repository($db);

        return $this->locked($db, function () use ($db, $repository, $batches, $undone): int {
            $recorded = $repository->ran();
            $last = \array_unique(\array_column($recorded, 'batch'));
            \rsort($last);
            $last = \array_slice($last, 0, $batches);

            $targets = \array_filter($recorded, static fn(array $record): bool => \in_array($record['batch'], $last, true));
            \uasort($targets, static fn(array $a, array $b): int => $b['position'] <=> $a['position']);

            $files = [];

            foreach ($this->files() as $file) {
                $files[$file->id()] = $file;
            }

            $missing = \array_values(\array_diff(\array_keys($targets), \array_keys($files)));

            if ($missing !== []) {
                throw MigrationException::missingFiles($missing);
            }

            /** @var list<array{MigrationFile, Reversible}> $plan */
            $plan = [];
            $irreversible = [];

            foreach (\array_keys($targets) as $id) {
                $migration = $files[$id]->load();

                if ($migration instanceof Reversible) {
                    $plan[] = [$files[$id], $migration];
                } else {
                    $irreversible[] = $id;
                }
            }

            if ($irreversible !== []) {
                throw MigrationException::irreversible($irreversible);
            }

            foreach ($plan as [$file, $migration]) {
                $this->apply($db, $file, static function (Tables $tables) use ($migration, $repository, $file): void {
                    $migration->down($tables);
                    $repository->forget($file->id());
                }, $undone);
            }

            return \count($plan);
        });
    }

    /**
     * The migrations not yet recorded, each loaded -- all of them before any
     * runs, so that a broken file stops the run before it starts.
     *
     * @param array<string, mixed> $recorded
     *
     * @return list<array{MigrationFile, Migration}>
     */
    private function pending(array $recorded): array
    {
        $pending = [];

        foreach ($this->files() as $file) {
            if (!isset($recorded[$file->id()])) {
                $pending[] = [$file, $file->load()];
            }
        }

        return $pending;
    }

    /**
     * One migration's work, in a transaction where the database can undo a
     * change of structure.
     *
     * @param \Closure(Tables): void $work
     * @param (\Closure(MigrationFile, list<array{sql: string, raw: bool}>, int): void)|null $report
     */
    private function apply(Connection $db, MigrationFile $file, \Closure $work, ?\Closure $report): void
    {
        $transactional = $db->supports(Capability::TransactionalDdl);
        $tables = $db->tables();
        $started = \hrtime(true);

        try {
            if ($transactional) {
                $db->transaction(static fn() => $work($tables));
            } else {
                $work($tables);
            }
        } catch (\Throwable $e) {
            throw MigrationException::failed($file->id(), $db->driver(), $transactional, $e);
        }

        if ($report !== null) {
            $report($file, $tables->statements(), (int) (\hrtime(true) - $started));
        }
    }

    /**
     * Run $work holding the migration lock, waiting up to $lockWait seconds
     * for another run to finish with it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function locked(Connection $db, \Closure $work): mixed
    {
        $acquire = $db->grammar()->compileAcquireLock(self::LOCK);

        if ($acquire === null) {
            return $work();
        }

        $deadline = \microtime(true) + $this->lockWait;

        while ((int) $db->scalar($acquire['sql'], $acquire['bindings']) !== 1) {
            if (\microtime(true) >= $deadline) {
                throw MigrationException::locked($db->name(), $this->lockWait);
            }

            \usleep(250_000);
        }

        try {
            return $work();
        } finally {
            $release = $db->grammar()->compileReleaseLock(self::LOCK);

            try {
                if ($release !== null) {
                    $db->execute($release['sql'], $release['bindings']);
                }
            } catch (DatabaseException) {
                // A lock this session cannot give back is given back by the
                // session ending, which the process is about to do.
            }
        }
    }

    private function repository(Connection $db): MigrationRepository
    {
        return new MigrationRepository($db, $this->table);
    }
}
