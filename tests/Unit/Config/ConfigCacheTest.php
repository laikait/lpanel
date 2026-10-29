<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Engine\Config\ConfigCache;
use App\Engine\Config\ConfigLoader;
use App\Engine\Config\ConfigurationException;
use App\Engine\Config\Env;
use App\Tests\Support\TestCase;

/**
 * Caching the whole resolved configuration.
 *
 * The interesting half is not the writing, it is the invalidation. Config files
 * read environment variables; caching them freezes those values; changing a
 * variable afterwards then does nothing at all, silently. That is a famous
 * afternoon-eating bug and the fingerprint is the fix, so most of these tests
 * are about the fingerprint.
 */
final class ConfigCacheTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $original = [];

    private string $file = '';

    protected function setUp(): void
    {
        $this->original = $_ENV;
        $this->file = \sys_get_temp_dir() . '/config-cache-' . \bin2hex(\random_bytes(6)) . '/config.php';
        Env::forget();
    }

    protected function tearDown(): void
    {
        $_ENV = $this->original;
        Env::forget();

        if (\is_file($this->file)) {
            @\unlink($this->file);
            @\rmdir(\dirname($this->file));
        }

        parent::tearDown();
    }

    // ---- the round trip ---------------------------------------------------

    public function test_what_goes_in_comes_out(): void
    {
        $items = ['app' => ['env' => 'production', 'debug' => false], 'logging' => ['writers' => ['file']]];

        self::assertTrue(ConfigCache::write($this->file, $items, []));
        self::assertSame($items, ConfigCache::read($this->file));
    }

    public function test_the_directory_is_created(): void
    {
        self::assertFalse(\is_dir(\dirname($this->file)));

        ConfigCache::write($this->file, ['a' => 1], []);

        self::assertFileExists($this->file);
    }

    public function test_a_missing_cache_is_a_miss_rather_than_a_failure(): void
    {
        self::assertNull(ConfigCache::read($this->file));
    }

    /**
     * A cache is an optimisation. A broken one means "build it the slow way",
     * never "the application does not start".
     */
    public function test_a_file_that_is_not_a_cache_is_ignored(): void
    {
        @\mkdir(\dirname($this->file), 0o775, true);
        \file_put_contents($this->file, "<?php\n\nreturn 'not a cache';\n");

        self::assertNull(ConfigCache::read($this->file));
    }

    // ---- the fingerprint --------------------------------------------------

    public function test_an_unchanged_environment_keeps_the_cache(): void
    {
        $_ENV['SOME_SETTING'] = 'one';

        ConfigCache::write($this->file, ['a' => 1], ['SOME_SETTING' => 'one']);

        self::assertSame(['a' => 1], ConfigCache::read($this->file));
    }

    public function test_a_changed_variable_makes_the_cache_stale(): void
    {
        $_ENV['SOME_SETTING'] = 'one';

        ConfigCache::write($this->file, ['a' => 1], ['SOME_SETTING' => 'one']);

        $_ENV['SOME_SETTING'] = 'two';

        self::assertNull(ConfigCache::read($this->file), 'the environment moved, so the cache is wrong');
    }

    /**
     * The asymmetric case, and the one a naive fingerprint misses: a variable
     * that was not set when the cache was built and is set now.
     */
    public function test_a_variable_that_has_appeared_makes_the_cache_stale(): void
    {
        ConfigCache::write($this->file, ['a' => 1], ['APPEARS_LATER' => null]);

        self::assertSame(['a' => 1], ConfigCache::read($this->file));

        $_ENV['APPEARS_LATER'] = 'here now';

        self::assertNull(ConfigCache::read($this->file));
    }

    public function test_a_variable_that_has_gone_makes_the_cache_stale(): void
    {
        $_ENV['GOES_AWAY'] = 'here';

        ConfigCache::write($this->file, ['a' => 1], ['GOES_AWAY' => 'here']);

        unset($_ENV['GOES_AWAY']);

        self::assertNull(ConfigCache::read($this->file));
    }

    /**
     * Editing a config file does not invalidate anything, and that is the
     * deliberate half of the contract: noticing would mean stat-ing every file
     * on every request, and a deployment that changes configuration is a
     * deployment, which runs cache:clear.
     */
    public function test_nothing_else_invalidates_it(): void
    {
        ConfigCache::write($this->file, ['a' => 'stale on purpose'], []);

        \touch($this->file, \time() - 86400);

        self::assertSame(['a' => 'stale on purpose'], ConfigCache::read($this->file));
    }

    // ---- what can be cached at all ----------------------------------------

    /**
     * var_export() writes a closure out happily and the result is a fatal error
     * on the way back in, inside a generated file nobody has read. Refusing at
     * the point somebody asked, naming the key, is worth the recursion.
     */
    public function test_something_that_is_not_data_is_refused_by_name(): void
    {
        $items = (new ConfigLoader($this->basePath('tests/Fixtures/Config/unexportable')))->load();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/closures\.factory/');

        ConfigCache::write($this->file, $items, []);
    }

    public function test_plain_data_of_every_shape_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        ConfigCache::assertPlainData([
            'string' => 'a',
            'int' => 1,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
            'list' => [1, 2, 3],
            'nested' => ['deep' => ['deeper' => 'still data']],
            'empty' => [],
        ]);
    }
}
