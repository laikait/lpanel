<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Cli\Commands\ModuleMakeCommand;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Container\Container;
use App\Engine\Core\Application;
use App\Engine\Core\ExecutionContext;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Structure\Tables;
use App\Engine\Http\Request;
use App\Engine\Migration\MigrationFile;
use App\Engine\Migration\Reversible;
use App\Engine\Migration\Seeder;
use App\Engine\Migration\SeederFile;
use App\Engine\Module\ModuleDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * module:make, against a throwaway base path. The real modules/ is never touched.
 */
final class ModuleMakeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lphp-make-' . \bin2hex(\random_bytes(4));
        \mkdir($this->base, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->delete($this->base);
    }

    private function delete(string $path): void
    {
        if (\is_file($path)) {
            \unlink($path);

            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->delete($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        \rmdir($path);
    }

    /**
     * @param list<string> $paths
     *
     * @return array{int, string}
     */
    private function make(string $name, array $paths = ['modules'], ?string $with = null): array
    {
        $container = new Container();
        $container->instance(Config::class, new Config(['modules' => ['paths' => $paths]]));
        $app = new Application($container, ExecutionContext::cli([]), $this->base);

        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        $status = (new ModuleMakeCommand($app))(new Output($stream), $name, $with);

        \rewind($stream);
        $output = (string) \stream_get_contents($stream);
        \fclose($stream);

        return [$status, $output];
    }

    public function test_it_creates_a_module_that_discovery_finds(): void
    {
        [$status, $output] = $this->make('Billing');

        self::assertSame(0, $status);
        self::assertStringContainsString('modules/Billing/module.php', $output);
        self::assertStringContainsString('php laika module:list', $output);

        $file = $this->base . '/modules/Billing/module.php';
        self::assertFileExists($file);
        self::assertSame(['module.php'], \array_values(\array_diff(\scandir(\dirname($file)) ?: [], ['.', '..'])));

        $found = (new ModuleDiscovery($this->base, ['modules']))->scan();
        self::assertCount(1, $found);
        self::assertSame('Billing', $found[0]->id);
    }

    public function test_the_file_is_the_documented_skeleton_and_valid_php(): void
    {
        $this->make('OrderHistory');
        $file = $this->base . '/modules/OrderHistory/module.php';

        $source = (string) \file_get_contents($file);
        self::assertStringContainsString('declare(strict_types=1);', $source);
        self::assertStringContainsString('use App\Engine\Module\ModuleContext;', $source);
        self::assertStringContainsString("->name('OrderHistory')", $source);
        self::assertStringContainsString("->version('0.1.0')", $source);

        $php = \escapeshellarg(PHP_BINARY);
        \exec($php . ' -l ' . \escapeshellarg($file) . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, \implode("\n", $lines));
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return [
            'lowercase first' => ['billing'],
            'three levels' => ['A/B/C'],
            'double slash' => ['Plugins//Billing'],
            'lowercase module' => ['Plugins/billing'],
            'lowercase group' => ['plugins/Billing'],
            'dot segment' => ['Plugins/./Billing'],
            'traversal' => ['../Evil'],
            'dotdot' => ['..'],
            'absolute' => ['/etc/Evil'],
            'drive' => ['C:\\Evil'],
            'hyphen' => ['Bill-ing'],
            'quote' => ["Bill'ing"],
            'php injection' => ["A');system('x');//"],
            'null byte' => ["Bill\0ing"],
            'empty' => [''],
            'leading digit' => ['1Billing'],
        ];
    }

    #[DataProvider('badNames')]
    public function test_an_invalid_name_is_refused_and_writes_nothing(string $name): void
    {
        [$status, $output] = $this->make($name);

        self::assertSame(1, $status);
        self::assertStringContainsString('is not a module name', $output);
        self::assertSame([], \glob($this->base . '/*') ?: []);
    }

    public function test_an_existing_module_is_not_overwritten(): void
    {
        \mkdir($this->base . '/modules/Billing', 0o775, true);
        \file_put_contents($this->base . '/modules/Billing/module.php', 'mine');

        [$status, $output] = $this->make('Billing');

        self::assertSame(1, $status);
        self::assertStringContainsString('already exists', $output);
        self::assertSame('mine', \file_get_contents($this->base . '/modules/Billing/module.php'));
    }

    public function test_a_partial_directory_and_a_plain_file_both_count_as_taken(): void
    {
        \mkdir($this->base . '/modules/Empty', 0o775, true);
        \file_put_contents($this->base . '/modules/Filed', 'x');

        self::assertSame(1, $this->make('Empty')[0]);
        self::assertSame(1, $this->make('Filed')[0]);
        self::assertSame([], \glob($this->base . '/modules/Empty/*') ?: []);
        self::assertSame('x', \file_get_contents($this->base . '/modules/Filed'));
    }

    public function test_a_folder_that_differs_only_by_case_counts_as_taken(): void
    {
        \mkdir($this->base . '/modules/BILLING', 0o775, true);

        [$status, $output] = $this->make('Billing');

        self::assertSame(1, $status);
        self::assertStringContainsString('modules/BILLING already exists', $output);
        self::assertSame(['BILLING'], \array_values(\array_diff(\scandir($this->base . '/modules') ?: [], ['.', '..'])));
    }

    public function test_a_failed_write_reports_failure_and_leaves_nothing_behind(): void
    {
        // A file where modules/ should be a directory: portable, unlike chmod.
        \file_put_contents($this->base . '/modules', 'in the way');

        [$status, $output] = $this->make('Billing');

        self::assertSame(1, $status);
        self::assertStringNotContainsString('Created', $output);
        self::assertSame('in the way', \file_get_contents($this->base . '/modules'));
    }

    public function test_modules_is_created_when_the_project_has_none_yet(): void
    {
        self::assertSame(0, $this->make('Billing')[0]);
        self::assertFileExists($this->base . '/modules/Billing/module.php');
    }

    public function test_a_nested_name_creates_the_module_inside_its_group(): void
    {
        [$status, $output] = $this->make('Plugins/Billing', ['modules', 'modules/Plugins']);

        self::assertSame(0, $status);
        self::assertStringContainsString('modules/Plugins/Billing/module.php', $output);
        self::assertStringNotContainsString('modules.paths', $output);

        $file = $this->base . '/modules/Plugins/Billing/module.php';
        self::assertStringContainsString("->name('Billing')", (string) \file_get_contents($file));

        $found = (new ModuleDiscovery($this->base, ['modules/Plugins']))->scan();
        self::assertSame(['Billing'], \array_map(static fn($m) => $m->id, $found));
    }

    public function test_a_backslash_separator_is_the_same_as_a_slash(): void
    {
        self::assertSame(0, $this->make('Plugins\Billing', ['modules/Plugins'])[0]);
        self::assertFileExists($this->base . '/modules/Plugins/Billing/module.php');
    }

    public function test_it_creates_config_modules_when_there_is_none_and_the_module_then_loads(): void
    {
        [$status, $output] = $this->make('Plugins/Billing');

        self::assertSame(0, $status);
        self::assertStringContainsString('creating config/modules.php', $output);
        self::assertStringNotContainsString('will not load', $output);

        $config = $this->base . '/config/modules.php';
        self::assertFileExists($config);
        $items = include $config;
        self::assertSame(['paths' => ['modules', 'modules/Plugins']], $items);

        $found = ModuleDiscovery::fromConfig(new Config(['modules' => $items]), $this->base)->scan();
        self::assertSame(['Billing'], \array_map(static fn($m) => $m->id, $found));
    }

    public function test_an_existing_config_file_is_left_alone_and_the_line_is_printed(): void
    {
        \mkdir($this->base . '/config', 0o775, true);
        $mine = "<?php\n// mine\nreturn ['paths' => ['modules']];\n";
        \file_put_contents($this->base . '/config/modules.php', $mine);

        [$status, $output] = $this->make('Plugins/Billing');

        self::assertSame(0, $status);
        self::assertFileExists($this->base . '/modules/Plugins/Billing/module.php');
        self::assertSame($mine, \file_get_contents($this->base . '/config/modules.php'));
        self::assertStringContainsString('modules/Plugins is not in modules.paths, so Billing will not load', $output);
        self::assertStringContainsString("'modules/Plugins'", $output);
    }

    public function test_paths_set_some_other_way_are_not_overridden_by_a_new_config_file(): void
    {
        [$status, $output] = $this->make('Plugins/Billing', ['modules', 'vendor/acme']);

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($this->base . '/config/modules.php');
        self::assertStringContainsString('will not load', $output);
    }

    public function test_config_is_untouched_when_the_group_is_already_a_root_or_there_is_no_group(): void
    {
        self::assertSame(0, $this->make('Plugins/Billing', ['modules', 'modules/Plugins'])[0]);
        self::assertSame(0, $this->make('Crm')[0]);

        self::assertDirectoryDoesNotExist($this->base . '/config');
    }

    public function test_slashes_around_a_name_are_ignored(): void
    {
        self::assertSame(0, $this->make('/Billing/')[0]);
        self::assertFileExists($this->base . '/modules/Billing/module.php');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function parts(): array
    {
        $migration = 'Database/Migrations/%s_create_order_history_table.php';

        return [
            'http' => ['http', ['module.php', 'Http/Index.php']],
            'model' => ['model', ['module.php', 'Model/OrderHistory.php']],
            'database' => ['database', ['module.php', $migration]],
            'database and seeder' => ['database,seeder', ['module.php', $migration, 'Database/Seeders/order_history.php']],
            'http and database' => ['http,database', ['module.php', 'Http/Index.php', $migration]],
            'http, database and templates' => ['http,database,templates', ['module.php', 'Http/Index.php', $migration, 'Templates/index.php']],
            'templates' => ['templates', ['module.php', 'Templates/index.php']],
            'http and templates' => ['http,templates', ['module.php', 'Http/Index.php', 'Templates/index.php']],
            'assets' => ['assets', ['module.php', 'assets/css/order-history.css']],
            'lang' => ['lang', ['module.php', 'lang/en.php']],
            'duplicates and case' => ['LANG, lang', ['module.php', 'lang/en.php']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('parts')]
    public function test_each_part_writes_its_files_and_only_those(string $with, array $expected): void
    {
        [$status] = $this->make('OrderHistory', with: $with);
        self::assertSame(0, $status);

        $root = $this->base . '/modules/OrderHistory';
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $found[] = \str_replace('\\', '/', \substr((string) $file, \strlen($root) + 1));

            if (\str_ends_with((string) $file, '.php')) {
                \exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg((string) $file) . ' 2>&1', $lines, $code);
                self::assertSame(0, $code, $file . ': ' . \implode("\n", $lines));
            }
        }

        // The migration's name starts with a timestamp, so match it by its tail.
        $found = \array_map(static fn(string $path): string => \preg_replace('#/\d{4}_\d{2}_\d{2}_\d{6}_#', '/%s_', $path) ?? $path, $found);
        \sort($found);
        \sort($expected);

        self::assertSame($expected, $found);
    }

    public function test_all_parts_on_a_grouped_module_use_the_group_in_every_namespace(): void
    {
        self::assertSame(0, $this->make('Plugins/Billing', ['modules', 'modules/Plugins'], 'all')[0]);

        $root = $this->base . '/modules/Plugins/Billing';
        self::assertStringContainsString('namespace App\Modules\Plugins\Billing\Http;', (string) \file_get_contents($root . '/Http/Index.php'));
        self::assertStringContainsString('namespace App\Modules\Plugins\Billing\Model;', (string) \file_get_contents($root . '/Model/Billing.php'));
        self::assertStringContainsString('use App\Modules\Plugins\Billing\Http\Index;', (string) \file_get_contents($root . '/module.php'));
    }

    public function test_the_generated_files_are_the_shapes_the_framework_reads(): void
    {
        $this->make('Billing', with: 'all');
        $root = $this->base . '/modules/Billing';

        $migration = \glob($root . '/Database/Migrations/*.php') ?: [];
        self::assertCount(1, $migration);
        self::assertMatchesRegularExpression(MigrationFile::PATTERN, \basename($migration[0], '.php'));
        self::assertInstanceOf(Reversible::class, require $migration[0]);

        self::assertMatchesRegularExpression(SeederFile::PATTERN, 'billing');
        self::assertInstanceOf(Seeder::class, require $root . '/Database/Seeders/billing.php');

        self::assertSame(['title' => 'Billing'], require $root . '/lang/en.php');
        self::assertInstanceOf(\Closure::class, require $root . '/module.php');
        self::assertStringContainsString("'/billing', Index::class", (string) \file_get_contents($root . '/module.php'));
    }

    /** @return array<string, array{string}> */
    public static function badParts(): array
    {
        return [
            'unknown' => ['controllers'],
            'empty' => [''],
            'empty entry' => ['http,'],
            'seeder alone' => ['seeder'],
            'seeder without database' => ['http,seeder'],
        ];
    }

    #[DataProvider('badParts')]
    public function test_a_bad_with_list_is_refused_and_writes_nothing(string $with): void
    {
        [$status, $output] = $this->make('Billing', with: $with);

        self::assertSame(1, $status);
        self::assertStringContainsString('--with takes', $output);
        self::assertSame([], \glob($this->base . '/*') ?: []);
    }

    public function test_a_repeat_run_with_parts_is_refused_and_changes_nothing(): void
    {
        $root = $this->base . '/modules/Billing';

        self::assertSame(0, $this->make('Billing', with: 'all')[0]);
        $before = $this->tree($this->base);

        self::assertSame(1, $this->make('Billing', with: 'all')[0]);
        self::assertSame($before, $this->tree($this->base));
        self::assertFileExists($root . '/module.php');
    }

    /** @return list<string> */
    private function tree(string $directory): array
    {
        $paths = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $path) {
            $paths[] = (string) $path;
        }

        \sort($paths);

        return $paths;
    }

    public function test_an_existing_group_folder_is_reused_and_an_existing_nested_module_is_not_overwritten(): void
    {
        \mkdir($this->base . '/modules/Plugins/Billing', 0o775, true);
        \file_put_contents($this->base . '/modules/Plugins/Billing/module.php', 'mine');

        self::assertSame(1, $this->make('Plugins/Billing')[0]);
        self::assertSame('mine', \file_get_contents($this->base . '/modules/Plugins/Billing/module.php'));

        self::assertSame(0, $this->make('Plugins/Crm')[0]);
        self::assertFileExists($this->base . '/modules/Plugins/Crm/module.php');
    }

    public function test_a_group_folder_in_other_letters_is_refused(): void
    {
        \mkdir($this->base . '/modules/PLUGINS', 0o775, true);

        [$status, $output] = $this->make('Plugins/Billing');

        self::assertSame(1, $status);
        self::assertStringContainsString('modules/PLUGINS already exists', $output);
        self::assertSame(['PLUGINS'], \array_values(\array_diff(\scandir($this->base . '/modules') ?: [], ['.', '..'])));
    }

    public function test_an_id_already_used_in_another_root_is_refused(): void
    {
        \mkdir($this->base . '/modules/Billing', 0o775, true);
        \file_put_contents($this->base . '/modules/Billing/module.php', 'mine');

        [$status, $output] = $this->make('Plugins/Billing', ['modules', 'modules/Plugins']);

        self::assertSame(1, $status);
        self::assertStringContainsString('ids must be unique', $output);
        self::assertDirectoryDoesNotExist($this->base . '/modules/Plugins');
    }

    public function test_a_group_that_cannot_be_created_fails_without_touching_what_is_there(): void
    {
        // A file where the group folder must go: creating it fails, and nothing is lost.
        \mkdir($this->base . '/modules', 0o775, true);
        \file_put_contents($this->base . '/modules/Plugins', 'in the way');

        [$status] = $this->make('Plugins/Billing');

        self::assertSame(1, $status);
        self::assertSame('in the way', \file_get_contents($this->base . '/modules/Plugins'));
    }

    public function test_the_handler_lists_the_rows_of_the_table_the_migration_creates(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $this->make('Ledger', with: 'http,database');
        $root = $this->base . '/modules/Ledger';

        $connections = new ConnectionManager([ConnectionConfig::of('test', 'sqlite::memory:')], 'test');
        $connection = $connections->connection();

        $migration = \glob($root . '/Database/Migrations/*.php') ?: [];
        (require $migration[0])->up(new Tables($connection));
        $connection->table('ledger')->insert(['name' => '<b>Rent</b>']);
        $connection->table('ledger')->insert(['name' => 'Food']);

        require $root . '/Http/Index.php';
        // Generated a moment ago, so it is known to no analyser: name it at run time.
        $class = 'App\\Modules\\' . \basename($root) . '\\Http\\Index';
        self::assertTrue(\class_exists($class));
        $handler = new $class($connections);
        self::assertTrue(\is_callable($handler));
        $body = $handler(Request::create('GET', '/ledger'))->body();

        self::assertStringContainsString('<li>Food</li>', $body);
        self::assertStringContainsString('<li>&lt;b&gt;Rent&lt;/b&gt;</li>', $body);
        self::assertStringNotContainsString('<b>Rent', $body);
    }

    public function test_the_handler_only_reads_the_table_when_database_is_asked_for(): void
    {
        $this->make('Plain', with: 'http,templates');
        $handler = (string) \file_get_contents($this->base . '/modules/Plain/Http/Index.php');
        self::assertStringNotContainsString('ConnectionManager', $handler);
        self::assertStringNotContainsString('$rows', (string) \file_get_contents($this->base . '/modules/Plain/Templates/index.php'));

        $this->make('Listed', with: 'http,database,templates');
        $handler = (string) \file_get_contents($this->base . '/modules/Listed/Http/Index.php');
        self::assertStringContainsString("->table('listed')", $handler);
        self::assertStringContainsString("['rows' => \$rows]", $handler);
        self::assertStringContainsString('foreach ($rows as $row)', (string) \file_get_contents($this->base . '/modules/Listed/Templates/index.php'));

        // A template with no handler to give it rows must not read any.
        $this->make('Orphan', with: 'database,templates');
        self::assertStringNotContainsString('$rows', (string) \file_get_contents($this->base . '/modules/Orphan/Templates/index.php'));
    }
}
