<?php

declare(strict_types=1);

namespace App\Tests\Unit\Migration;

use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Migration\MigrationException;
use App\Engine\Migration\SeederFile;
use App\Engine\Migration\SeedRunner;
use App\Engine\Module\ModuleDefinition;
use App\Engine\Module\ModuleRegistry;
use App\Tests\Support\TestCase;

/**
 * Seeders, on SQLite: module order, running twice, one module alone, and
 * every way a run is refused before it writes anything.
 */
final class SeedRunnerTest extends TestCase
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

    /**
     * The fixture modules, migrated, on one in-memory database.
     *
     * @return array{SeedRunner, ConnectionManager}
     */
    private function fixtures(): array
    {
        $app = $this->application([
            'modules' => ['paths' => [self::SHOWCASE . '/Shared', self::FIXTURES]],
            'database' => ['connections' => ['default' => ['dsn' => 'sqlite::memory:']]],
        ])->boot();

        $this->migrate($app);

        return [$app->container()->get(SeedRunner::class), $app->container()->get(ConnectionManager::class)];
    }

    /**
     * A module of one's own with these seeder files, and a table for them to fill.
     *
     * @param array<string, string> $files name => PHP source
     *
     * @return array{SeedRunner, ConnectionManager}
     */
    private function scratch(array $files): array
    {
        $this->scratch = \str_replace('\\', '/', \sys_get_temp_dir()) . '/seeders-' . \bin2hex(\random_bytes(6));
        $directory = $this->scratch . '/Scratch/' . SeederFile::DIRECTORY;
        \mkdir($directory, 0o777, true);

        foreach ($files as $name => $source) {
            \file_put_contents($directory . '/' . $name, $source);
        }

        $modules = new ModuleRegistry();
        $modules->add(ModuleDefinition::create($this->scratch . '/Scratch', 'Scratch'));

        $connections = new ConnectionManager([ConnectionConfig::of('default', 'sqlite::memory:')]);
        $connections->connection()->execute('CREATE TABLE seeded (name TEXT UNIQUE)');

        return [new SeedRunner($connections, $modules), $connections];
    }

    private static function seeder(string $run): string
    {
        return '<?php use App\Engine\Database\Connection; use App\Engine\Migration\Seeder; '
            . 'return new class implements Seeder { public function run(Connection $db): void { ' . $run . ' } };';
    }

    /** @return list<string> */
    private static function seeded(ConnectionManager $connections): array
    {
        return \array_map(
            static fn(array $row): string => (string) $row['name'],
            $connections->connection()->table('seeded')->orderBy('name')->get(),
        );
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

    // ---- running ------------------------------------------------------------

    /** Billing's seeder needs Customers' row, and gets it: Billing requires Customers. */
    public function test_seeders_run_in_module_order_and_a_second_run_adds_nothing(): void
    {
        [$seeds, $connections] = $this->fixtures();
        $ran = [];

        self::assertSame(2, $seeds->seed(ran: static function (SeederFile $file) use (&$ran): void {
            $ran[] = $file->id();
        }));
        self::assertSame(['Customers:customers', 'Billing:invoices'], $ran);

        self::assertSame(2, $seeds->seed());

        $db = $connections->connection();
        self::assertSame(1, $db->table('laika_mig_customers')->count());
        self::assertSame(1, $db->table('laika_mig_invoices')->count());
    }

    public function test_one_module_can_be_seeded_alone(): void
    {
        [$seeds, $connections] = $this->fixtures();

        self::assertSame(1, $seeds->seed(module: 'Customers'));
        self::assertSame(1, $connections->connection()->table('laika_mig_customers')->count());
        self::assertSame(0, $connections->connection()->table('laika_mig_invoices')->count());
    }

    public function test_a_module_that_is_not_enabled_is_refused_by_name(): void
    {
        [$seeds] = $this->fixtures();

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('There is no enabled module "Nobody". The enabled ones are: ');

        $seeds->seed(module: 'Nobody');
    }

    public function test_files_within_a_module_run_in_name_order(): void
    {
        [$seeds, $connections] = $this->scratch([
            '02_second.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => (string) $db->table(\'seeded\')->count() . \'-second\']);'),
            '01_first.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => \'0-first\']);'),
        ]);

        self::assertSame(2, $seeds->seed());
        self::assertSame(['0-first', '1-second'], self::seeded($connections));
    }

    // ---- refusing -------------------------------------------------------------

    /** Everything is loaded first, so the good seeder before the broken file never runs. */
    public function test_a_badly_named_file_stops_the_run_before_it_starts(): void
    {
        [$seeds, $connections] = $this->scratch([
            'a_good_one.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => \'x\']);'),
            'BadName.php' => self::seeder(''),
        ]);

        try {
            $seeds->seed();
            self::fail('a seeder with a bad name ran');
        } catch (MigrationException $e) {
            self::assertStringContainsString('Scratch has a seeder named "BadName.php"', $e->getMessage());
        }

        self::assertSame([], self::seeded($connections));
    }

    public function test_a_file_that_returns_something_else_stops_the_run_before_it_starts(): void
    {
        [$seeds, $connections] = $this->scratch([
            'a_good_one.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => \'x\']);'),
            'b_closure.php' => '<?php return static function (): void {};',
        ]);

        try {
            $seeds->seed();
            self::fail('a file that is not a seeder was run');
        } catch (MigrationException $e) {
            self::assertStringContainsString('The file of Scratch:b_closure returned Closure', $e->getMessage());
        }

        self::assertSame([], self::seeded($connections));
    }

    /** A failing seeder's rows go with it; the seeder before it has committed. */
    public function test_a_failing_seeder_leaves_nothing_of_itself(): void
    {
        [$seeds, $connections] = $this->scratch([
            'a_fine.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => \'fine\']);'),
            'b_half.php' => self::seeder('$db->table(\'seeded\')->insert([\'name\' => \'half\']); $db->table(\'seeded\')->insert([\'name\' => \'fine\']);'),
        ]);

        try {
            $seeds->seed();
            self::fail('a seeder that broke a unique column succeeded');
        } catch (MigrationException $e) {
            self::assertStringContainsString('Scratch:b_half failed on sqlite. Nothing of it was kept; the seeders before it were.', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }

        self::assertSame(['fine'], self::seeded($connections));
    }
}
