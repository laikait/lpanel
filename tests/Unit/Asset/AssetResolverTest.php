<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use App\Engine\Asset\AssetException;
use App\Engine\Asset\AssetKind;
use App\Engine\Asset\AssetResolver;
use App\Engine\Asset\AssetSource;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The security tests.
 *
 * Everything here is an attempt to name a file that is not an asset. Each one
 * has to fail, and the ones that would be most damaging -- an executable, a
 * traversal, a symlink out -- get their own named test rather than a row in a
 * data provider, because a provider row is easy to delete without anybody
 * noticing what it protected.
 */
final class AssetResolverTest extends TestCase
{
    private string $root;

    private AssetSource $source;

    private AssetResolver $resolver;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/framework-assets-' . \bin2hex(\random_bytes(6));

        \mkdir($this->root . '/js', 0o777, true);
        \mkdir($this->root . '/nested/deep', 0o777, true);

        \file_put_contents($this->root . '/js/app.js', 'console.log(1);');
        \file_put_contents($this->root . '/nested/deep/style.css', 'body{}');
        \file_put_contents($this->root . '/secret.php', '<?php echo "owned";');
        \file_put_contents($this->root . '/.env', 'DB_PASSWORD=hunter2');

        // Somewhere outside the published directory, for the traversal tests to
        // aim at. If any of them succeed they will find this.
        \file_put_contents(\dirname($this->root) . '/framework-assets-outside.txt', 'outside');

        $this->source = new AssetSource(AssetKind::Core, null, $this->root);
        $this->resolver = new AssetResolver();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        @\unlink(\dirname($this->root) . '/framework-assets-outside.txt');

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $entries = \scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            if (\is_link($child) || \is_file($child)) {
                @\unlink($child);

                continue;
            }

            $this->removeTree($child);
        }

        @\rmdir($path);
    }

    // ---- what should work -------------------------------------------------

    public function test_a_published_file_resolves(): void
    {
        $reference = $this->resolver->resolve($this->source, 'js/app.js');

        self::assertSame('js/app.js', $reference->path);
        self::assertStringEndsWith('js/app.js', $reference->absolutePath);
        self::assertSame('text/javascript; charset=UTF-8', $reference->contentType());
        self::assertSame(15, $reference->size());
    }

    public function test_nesting_is_fine(): void
    {
        self::assertTrue($this->resolver->exists($this->source, 'nested/deep/style.css'));
    }

    public function test_a_hash_is_stable_and_changes_with_the_content(): void
    {
        $first = $this->resolver->resolve($this->source, 'js/app.js')->hash();

        self::assertSame($first, $this->resolver->resolve($this->source, 'js/app.js')->hash());

        \file_put_contents($this->root . '/js/app.js', 'console.log(2);');
        $this->resolver->flush();

        self::assertNotSame($first, $this->resolver->resolve($this->source, 'js/app.js')->hash());
    }

    // ---- executables ------------------------------------------------------

    /**
     * The single most important assertion in the asset layer.
     *
     * modules/ holds module.php, repositories and schemas. If a .php inside a
     * published assets/ directory could be delivered, the asset manager would
     * be a way to read the application's source, and the .htaccess denying
     * modules/ would be pointless.
     */
    public function test_a_php_file_in_a_published_directory_is_not_servable(): void
    {
        self::assertFileExists($this->root . '/secret.php');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('does not serve');

        $this->resolver->resolve($this->source, 'secret.php');
    }

    public function test_a_dotfile_is_not_reachable(): void
    {
        self::assertFileExists($this->root . '/.env');

        $this->expectException(AssetException::class);

        $this->resolver->resolve($this->source, '.env');
    }

    /**
     * A double extension is a classic upload bypass: the file is called
     * app.js.php so that a check on "does it end in .js" passes. The extension
     * is the last one, so this is refused like any other .php.
     */
    public function test_a_double_extension_is_judged_by_its_last_extension(): void
    {
        \file_put_contents($this->root . '/js/app.js.php', '<?php');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('does not serve');

        $this->resolver->resolve($this->source, 'js/app.js.php');
    }

    // ---- traversal --------------------------------------------------------

    /** @return list<array{string}> */
    public static function traversals(): array
    {
        return [
            ['../framework-assets-outside.txt'],
            ['js/../../framework-assets-outside.txt'],
            ['./js/app.js'],
            ['/etc/passwd'],
            ['C:/Windows/win.ini'],
            ['js//app.js'],
            ['js/./app.js'],
            // URL-encoded traversal. The path arrives already decoded, so a
            // literal %2e%2e is just a filename with per cent signs in it --
            // and that is not a plain file name either.
            ['%2e%2e/framework-assets-outside.txt'],
            ['..%2Fframework-assets-outside.txt'],
            ["js/app.js\0.php"],
            ['js\\app.js'],
            ['..\\framework-assets-outside.txt'],
        ];
    }

    #[DataProvider('traversals')]
    public function test_nothing_escapes_the_published_directory(string $path): void
    {
        $this->expectException(AssetException::class);

        $this->resolver->resolve($this->source, $path);
    }

    public function test_a_refusal_never_names_the_absolute_path(): void
    {
        try {
            $this->resolver->resolve($this->source, '../framework-assets-outside.txt');
            self::fail('the traversal was not refused');
        } catch (AssetException $e) {
            // Whoever is probing should learn that it did not work, and nothing
            // about where the application lives on disk.
            self::assertStringNotContainsString($this->root, $e->getMessage());
            self::assertStringNotContainsString(\DIRECTORY_SEPARATOR === '\\' ? 'C:\\' : '/var', $e->getMessage());
        }
    }

    /**
     * Containment is checked after the filesystem has had its say, which is the
     * only way to catch this: every character in "link.css" is legal, and it is
     * the link target that leaves the directory.
     */
    public function test_a_symlink_pointing_out_of_the_directory_is_refused(): void
    {
        $target = \dirname($this->root) . '/framework-assets-outside.txt';
        $link = $this->root . '/link.css';

        if (!@\symlink($target, $link)) {
            self::markTestSkipped('this account cannot create symlinks');
        }

        self::assertFileExists($link, 'the link itself resolves, which is what makes this test worth having');

        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('outside');

        $this->resolver->resolve($this->source, 'link.css');
    }

    public function test_a_symlink_inside_the_directory_still_works(): void
    {
        $link = $this->root . '/js/alias.js';

        if (!@\symlink($this->root . '/js/app.js', $link)) {
            self::markTestSkipped('this account cannot create symlinks');
        }

        self::assertTrue($this->resolver->exists($this->source, 'js/alias.js'));
    }

    // ---- the rest ---------------------------------------------------------

    public function test_a_missing_file_is_reported_as_missing(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('There is no asset "js/nope.js"');

        $this->resolver->resolve($this->source, 'js/nope.js');
    }

    public function test_find_returns_null_instead_of_throwing(): void
    {
        self::assertNull($this->resolver->find($this->source, 'js/nope.js'));
        self::assertNull($this->resolver->find($this->source, '../framework-assets-outside.txt'));
        self::assertNotNull($this->resolver->find($this->source, 'js/app.js'));
    }

    public function test_an_absurdly_deep_path_is_refused_without_touching_the_disk(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('nested more than');

        $this->resolver->resolve($this->source, \str_repeat('a/', 40) . 'x.css');
    }

    public function test_an_absurdly_long_path_is_refused(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('longer than');

        $this->resolver->resolve($this->source, \str_repeat('a', 2000) . '.css');
    }

    public function test_an_empty_path_is_refused(): void
    {
        $this->expectException(AssetException::class);
        $this->expectExceptionMessage('it is empty');

        $this->resolver->resolve($this->source, '');
    }
}
