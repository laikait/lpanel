<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\DatabaseException;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\MigrationFile;
use App\Engine\Migration\Migrator;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleRegistry;
use App\Tests\Support\TestCase;

/**
 * The runner, on SQLite: order, once-only, batches, rollback, and every way a
 * run is refused before it changes anything.
 *
 * The fixture modules (tests/Fixtures/Modules/Migrations) are loaded by a real
 * application, so the order is the one the module system resolves: Billing
 * requires Customers and runs after it, although "Billing" sorts first and its
 * migration is dated earlier than Customers' second.
 */
final class MigratorTest extends TestCase
{
    private const FIXTURES = 'tests/Fixtures/Modules/Migrations/Plugins';

    private ?string $scratch = null;

    protected function tearDown(): void
    {
        if ($this->scratch !== null) {
            $this->remove($this->scratch);
        }

        parent::tearDown();
    }

    /** The fixture modules, on an in-memory database, through the real bootstrap. */
    private function migrator(): Migrator
    {
        return $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', self::FIXTURES]],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot()->container()->get(Migrator::class);
    }

    /** @return list<string> */
    private static function tablesIn(ConnectionManager $connections): array
    {
        return \array_values(\array_map(
            static fn(array $row): string => (string) $row['name'],
            $connections->connection()->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'laika_mig_%' ORDER BY name"),
        ));
    }

    /**
     * A module of one's own, written to a scratch directory, with these
     * migration files.
     *
     * @param array<string, string> $files name => PHP source
     */
    private function scratchMigrator(array $files): Migrator
    {
        $this->scratch = \str_replace('\\', '/', \sys_get_temp_dir()) . '/migrations-' . \bin2hex(\random_bytes(6));
        $directory = $this->scratch . '/Scratch/' . MigrationFile::DIRECTORY;
        \mkdir($directory, 0o777, true);

        foreach ($files as $name => $source) {
            \file_put_contents($directory . '/' . $name, $source);
        }

        $modules = new ModuleRegistry();
        $modules->add(ModuleDefinition::create($this->scratch . '/Scratch', 'Scratch'));

        return new Migrator(
            new ConnectionManager([ConnectionConfig::of('default', 'sqlite::memory:')]),
            $modules,
        );
    }

    private static function migration(string $up, ?string $down = null): string
    {
        return '<?php use App\Engine\Database\Structure\Table; use App\Engine\Database\Structure\Tables; '
            . 'use App\Engine\Migration\Migration; use App\Engine\Migration\Reversible; '
            . 'return new class implements ' . ($down === null ? 'Migration' : 'Reversible') . ' { '
            . 'public function up(Tables $tables): void { ' . $up . ' } '
            . ($down === null ? '' : 'public function down(Tables $tables): void { ' . $down . ' } ')
            . '};';
    }

    private function remove(string $path): void
    {
        if (\is_dir($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }

            \rmdir($path);
        } elseif (\is_file($path)) {
            \unlink($path);
        }
    }

    // ---- order and once-only ------------------------------------------------

    public function test_modules_run_in_dependency_order_and_files_in_name_order(): void
    {
        self::assertSame([
            'Customers:2026_01_01_000000_create_customers',
            'Customers:2026_03_01_000000_create_customer_notes',
            'Billing:2026_02_01_000000_create_invoices',
        ], \array_map(static fn(MigrationFile $file): string => $file->id(), $this->migrator()->files()));
    }

    public function test_a_run_creates_the_tables_and_a_second_run_does_nothing(): void
    {
        $migrator = $this->migrator();
        $ran = [];

        self::assertSame(3, $migrator->migrate(ran: static function (MigrationFile $file) use (&$ran): void {
            $ran[] = $file->name;
        }));
        self::assertSame(['2026_01_01_000000_create_customers', '2026_03_01_000000_create_customer_notes', '2026_02_01_000000_create_invoices'], $ran);
        self::assertSame(0, $migrator->migrate(), 'a migration ran twice');

        self::assertSame(
            [
                ['id' => 'Customers:2026_01_01_000000_create_customers', 'batch' => 1, 'file' => true],
                ['id' => 'Customers:2026_03_01_000000_create_customer_notes', 'batch' => 1, 'file' => true],
                ['id' => 'Billing:2026_02_01_000000_create_invoices', 'batch' => 1, 'file' => true],
            ],
            $migrator->status(),
        );
    }

    public function test_status_before_anything_ran_lists_every_migration_as_pending(): void
    {
        $status = $this->migrator()->status();

        self::assertCount(3, $status);
        self::assertSame([null, null, null], \array_column($status, 'batch'));
    }

    /** Pretending writes each migration's SQL for this database, and runs and records nothing. */
    public function test_pretending_shows_the_sql_and_changes_nothing(): void
    {
        $app = $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', self::FIXTURES]],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();
        $migrator = $app->container()->get(Migrator::class);
        $shown = [];

        self::assertSame(3, $migrator->migrate(pretend: true, ran: static function (MigrationFile $file, array $statements) use (&$shown): void {
            $shown[$file->name] = \array_column($statements, 'sql');
        }));

        self::assertStringStartsWith('CREATE TABLE "laika_mig_invoices"', $shown['2026_02_01_000000_create_invoices'][0]);
        self::assertSame('CREATE INDEX "laika_mig_invoices_customer_id_index" ON "laika_mig_invoices" ("customer_id")', $shown['2026_02_01_000000_create_invoices'][1]);
        self::assertSame([], self::tablesIn($app->container()->get(ConnectionManager::class)));
        self::assertSame(3, \count(\array_filter($migrator->status(), static fn(array $s): bool => $s['batch'] === null)));
    }

    // ---- rollback -----------------------------------------------------------

    public function test_a_rollback_undoes_the_last_batch_newest_first(): void
    {
        $app = $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', self::FIXTURES]],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();
        $migrator = $app->container()->get(Migrator::class);
        $connections = $app->container()->get(ConnectionManager::class);

        $migrator->migrate();
        $undone = [];

        self::assertSame(3, $migrator->rollback(undone: static function (MigrationFile $file) use (&$undone): void {
            $undone[] = $file->name;
        }));

        // The reverse of the order they ran in, so the invoices go before the customers they point at.
        self::assertSame(['2026_02_01_000000_create_invoices', '2026_03_01_000000_create_customer_notes', '2026_01_01_000000_create_customers'], $undone);
        self::assertSame([], self::tablesIn($connections));
        self::assertSame(0, $migrator->rollback(), 'nothing is left to undo');

        // And they run again, as a new batch.
        self::assertSame(3, $migrator->migrate());
        self::assertSame(['laika_mig_customer_notes', 'laika_mig_customers', 'laika_mig_invoices'], self::tablesIn($connections));
    }

    public function test_batches_are_separate_and_rolled_back_one_at_a_time(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_first.php' => self::migration(
                '$tables->create("laika_mig_first", static fn (Table $t) => $t->id());',
                '$tables->drop("laika_mig_first");',
            ),
        ]);
        $migrator->migrate();

        \file_put_contents(
            (string) $this->scratch . '/Scratch/' . MigrationFile::DIRECTORY . '/2026_01_02_000000_second.php',
            self::migration('$tables->create("laika_mig_second", static fn (Table $t) => $t->id());', '$tables->drop("laika_mig_second");'),
        );
        $migrator->migrate();

        self::assertSame([1, 2], \array_column($migrator->status(), 'batch'));
        self::assertSame(1, $migrator->rollback());
        self::assertSame([1, null], \array_column($migrator->status(), 'batch'));
    }

    public function test_a_rollback_over_a_migration_without_down_undoes_nothing(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_reversible.php' => self::migration(
                '$tables->create("laika_mig_a", static fn (Table $t) => $t->id());',
                '$tables->drop("laika_mig_a");',
            ),
            '2026_01_02_000000_one_way.php' => self::migration('$tables->create("laika_mig_b", static fn (Table $t) => $t->id());'),
        ]);
        $migrator->migrate();

        try {
            $migrator->rollback();
            self::fail('a migration without down() was rolled back');
        } catch (MigrationException $e) {
            self::assertStringContainsString('Nothing was rolled back: Scratch:2026_01_02_000000_one_way cannot be undone', $e->getMessage());
        }

        self::assertSame([1, 1], \array_column($migrator->status(), 'batch'), 'the reversible one was undone anyway');
    }

    public function test_a_rollback_whose_file_is_gone_undoes_nothing(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_gone.php' => self::migration(
                '$tables->create("laika_mig_gone", static fn (Table $t) => $t->id());',
                '$tables->drop("laika_mig_gone");',
            ),
        ]);
        $migrator->migrate();
        \unlink((string) $this->scratch . '/Scratch/' . MigrationFile::DIRECTORY . '/2026_01_01_000000_gone.php');

        self::assertSame([['id' => 'Scratch:2026_01_01_000000_gone', 'batch' => 1, 'file' => false]], $migrator->status());

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('the file is gone');

        $migrator->rollback();
    }

    // ---- refusals and failures ----------------------------------------------

    public function test_a_badly_named_file_stops_the_run_before_it_starts(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_fine.php' => self::migration('$tables->create("laika_mig_fine", static fn (Table $t) => $t->id());'),
            'create_things.php' => self::migration('$tables->create("laika_mig_things", static fn (Table $t) => $t->id());'),
        ]);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('named "create_things.php", which does not sort into a run order');

        $migrator->migrate();
    }

    public function test_a_file_that_returns_something_else_stops_the_run_before_it_starts(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_fine.php' => self::migration('$tables->create("laika_mig_fine", static fn (Table $t) => $t->id());'),
            '2026_01_02_000000_closure.php' => '<?php return static function (): void {};',
        ]);

        try {
            $migrator->migrate();
            self::fail('a file that is not a migration was run');
        } catch (MigrationException $e) {
            self::assertStringContainsString('returned Closure', $e->getMessage());
        }

        self::assertSame([null, null], \array_column($migrator->status(), 'batch'), 'the migration before it ran');
    }

    /**
     * SQLite rolls structure back, so a migration that fails halfway leaves
     * nothing: not its first table, and no record. The ones before it stay.
     */
    public function test_a_failing_migration_leaves_nothing_of_itself_and_is_not_recorded(): void
    {
        $migrator = $this->scratchMigrator([
            '2026_01_01_000000_fine.php' => self::migration('$tables->create("laika_mig_fine", static fn (Table $t) => $t->id());'),
            '2026_01_02_000000_breaks.php' => self::migration(
                '$tables->create("laika_mig_half", static fn (Table $t) => $t->id()); $tables->raw("THIS IS NOT SQL", "sqlite");',
            ),
        ]);

        try {
            $migrator->migrate();
            self::fail('the broken migration succeeded');
        } catch (MigrationException $e) {
            self::assertStringContainsString('Scratch:2026_01_02_000000_breaks failed on sqlite', $e->getMessage());
            self::assertStringContainsString('Nothing of it was kept', $e->getMessage());
            self::assertInstanceOf(DatabaseException::class, $e->getPrevious());

            // The database's own words are the database's: shown in debug
            // mode, and pointed at otherwise, never repeated by this message.
            self::assertTrue($e->disclosesMessage());
            self::assertStringNotContainsString('THIS IS NOT SQL', $e->getMessage());
            self::assertTrue($e->disclosesCause(true));
            self::assertFalse($e->disclosesCause(false));
        }

        self::assertSame([1, null], \array_column($migrator->status(), 'batch'));
    }

    public function test_a_rollback_of_no_batches_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->migrator()->rollback(batches: 0);
    }
}
