<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Engine\Config\ConfigLoader;
use App\Engine\Config\ConfigurationException;
use App\Engine\Config\Env;
use App\Tests\Support\TestCase;

/**
 * Reading config/.
 *
 * The mapping from filename to key is the whole contract, and it is direct on
 * purpose: config/database.php is "database", config/plugins/Example.php is
 * "plugins/Example", which is a module's id. A lookup table between the two
 * would be one more thing to maintain and to get wrong.
 */
final class ConfigLoaderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    protected function setUp(): void
    {
        $this->original = $_ENV;
        Env::forget();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->original;
        Env::forget();

        parent::tearDown();
    }

    private function loader(string $fixture): ConfigLoader
    {
        return new ConfigLoader($this->basePath('tests/Fixtures/Config/' . $fixture));
    }

    // ---- discovery --------------------------------------------------------

    /**
     * An application with no config/ directory runs on the defaults and the
     * environment, which is what makes this phase an addition rather than a new
     * obligation.
     */
    public function test_a_missing_directory_is_not_an_error(): void
    {
        $loader = $this->loader('no-such-directory');

        self::assertFalse($loader->exists());
        self::assertSame([], $loader->files());
        self::assertSame([], $loader->load());
    }

    public function test_the_filename_is_the_namespace(): void
    {
        self::assertArrayHasKey('app', $this->loader('site')->files());
    }

    public function test_a_subdirectory_joins_with_a_slash(): void
    {
        self::assertArrayHasKey('plugins/Example', $this->loader('site')->files());
    }

    /**
     * Sorted, because two files contribute to one tree and the result must not
     * depend on the order a filesystem hands back directory entries. It is the
     * rule module loading follows for the same reason.
     */
    public function test_the_order_is_sorted_rather_than_whatever_the_filesystem_says(): void
    {
        self::assertSame(['app', 'plugins/Example'], \array_keys($this->loader('site')->files()));
    }

    // ---- reading ----------------------------------------------------------

    public function test_values_arrive_under_the_namespace(): void
    {
        $items = $this->loader('site')->load();

        self::assertIsArray($items['app']);
        self::assertSame('Fixture', $items['app']['name']);
        self::assertSame(['page_size' => 10], $items['plugins/Example']);
    }

    public function test_nesting_inside_a_file_is_preserved(): void
    {
        $items = $this->loader('site')->load();

        self::assertIsArray($items['app']);
        self::assertSame(['kept' => 'yes'], $items['app']['nested']);
    }

    /**
     * A config file may read the environment -- that is where the precedence
     * between a file and a variable becomes visible, in the file somebody has
     * open, rather than hidden in a merge order somewhere else.
     */
    public function test_a_file_may_read_the_environment(): void
    {
        $_ENV['FIXTURE_DEBUG'] = 'true';

        $items = $this->loader('site')->load();

        self::assertIsArray($items['app']);
        self::assertTrue($items['app']['debug']);
        self::assertArrayHasKey('FIXTURE_DEBUG', Env::reads(), 'and the read is recorded for the cache');
    }

    public function test_a_file_that_returns_nothing_says_which_file(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/bad\.php/');

        $this->loader('broken')->load();
    }

    /**
     * A config file is data that happens to be written in PHP. It gets no
     * access to the loader's own scope, so it cannot come to depend on one.
     */
    public function test_a_file_is_read_with_nothing_of_ours_in_scope(): void
    {
        $source = \file_get_contents($this->basePath('engine/Config/ConfigLoader.php'));

        self::assertIsString($source);
        self::assertStringContainsString('static fn(string $file): mixed => require $file', $source);
    }
}
