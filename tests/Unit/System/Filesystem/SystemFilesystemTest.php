<?php

declare(strict_types=1);

namespace App\Tests\Unit\System\Filesystem;

use App\Engine\System\Filesystem\FilesystemException;
use App\Engine\System\Filesystem\FilesystemPolicy;
use App\Engine\System\Filesystem\SystemFilesystem;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A real directory tree per test:
 *
 *     <base>/writable/       allowWrite
 *     <base>/readable/       allowRead
 *     <base>/outside/        not allowed; holds secret.txt
 */
final class SystemFilesystemTest extends TestCase
{
    private string $base;

    private string $writable;

    private string $readable;

    private string $outside;

    private SystemFilesystem $files;

    protected function setUp(): void
    {
        $this->base = \str_replace('\\', '/', (string) \realpath(\sys_get_temp_dir())) . '/lphp-fs-' . \bin2hex(\random_bytes(4));
        $this->writable = $this->base . '/writable';
        $this->readable = $this->base . '/readable';
        $this->outside = $this->base . '/outside';

        foreach ([$this->writable, $this->readable, $this->outside] as $directory) {
            \mkdir($directory, 0o777, true);
        }

        \file_put_contents($this->readable . '/report.txt', 'quarterly');
        \file_put_contents($this->outside . '/secret.txt', 'do not read');

        $this->files = new SystemFilesystem(FilesystemPolicy::none()->allowWrite($this->writable)->allowRead($this->readable));
    }

    protected function tearDown(): void
    {
        $this->cleanUp();

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        self::remove($this->base);
    }

    private static function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path) || @\rmdir($path);

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            self::remove($path . '/' . $entry);
        }

        @\rmdir($path);
    }

    /** Symbolic links need a privilege on Windows that a test run usually lacks. */
    private function link(string $target, string $link): void
    {
        if (!@\symlink($target, $link)) {
            self::markTestSkipped('this platform does not let the test create a symbolic link');
        }
    }

    // ---- the policy ---------------------------------------------------------------------

    /** @return iterable<string, array{string, string}> */
    public static function refusedPaths(): iterable
    {
        yield 'relative' => ['writable/x.txt', 'is not an absolute path'];
        yield 'parent segment that escapes' => ['{writable}/../outside/secret.txt', 'contains a ".." segment'];
        yield 'parent segment that stays inside' => ['{writable}/../writable/x.txt', 'contains a ".." segment'];
        yield 'etc passwd' => ['{writable}/../../../../etc/passwd', 'contains a ".." segment'];
        yield 'nul byte' => ["{writable}/x.txt\0.jpg", 'NUL byte'];
        yield 'outside every root' => ['{outside}/secret.txt', 'is not inside any root'];
        yield 'a sibling whose name starts like a root' => ['{writable}-evil/x.txt', 'is not inside any root'];
    }

    #[DataProvider('refusedPaths')]
    public function test_a_path_the_policy_does_not_allow_is_refused_for_every_operation(string $path, string $message): void
    {
        $path = \str_replace(['{writable}', '{outside}'], [$this->writable, $this->outside], $path);

        foreach ([
            fn() => $this->files->inspect($path),
            fn() => $this->files->write($path, 'x'),
            fn() => $this->files->delete($path),
            fn() => $this->files->copy($this->readable . '/report.txt', $path),
        ] as $operation) {
            try {
                $operation();
                self::fail('the path was acted on');
            } catch (FilesystemException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertSame('do not read', \file_get_contents($this->outside . '/secret.txt'));
    }

    public function test_a_read_root_can_be_read_and_not_written(): void
    {
        self::assertSame(9, $this->files->inspect($this->readable . '/report.txt')->size);

        foreach ([
            fn() => $this->files->write($this->readable . '/new.txt', 'x'),
            fn() => $this->files->delete($this->readable . '/report.txt'),
            fn() => $this->files->createDirectory($this->readable . '/sub'),
            fn() => $this->files->move($this->readable . '/report.txt', $this->writable . '/report.txt'),
        ] as $operation) {
            try {
                $operation();
                self::fail('a read-only root was written to');
            } catch (FilesystemException $e) {
                self::assertStringContainsString('allows reading, not writing', $e->getMessage());
            }
        }

        self::assertFileExists($this->readable . '/report.txt');
    }

    /** @return iterable<string, array{string}> */
    public static function filesystemRoots(): iterable
    {
        yield 'unix root' => ['/'];
        yield 'unix root, repeated slashes' => ['//'];
        yield 'drive root' => ['C:/'];
        yield 'drive root, backslash' => ['C:\\'];
    }

    #[DataProvider('filesystemRoots')]
    public function test_the_root_of_a_filesystem_cannot_be_allowed(string $root): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('is the root of a filesystem');

        FilesystemPolicy::none()->allowWrite($root);
    }

    public function test_a_policy_is_immutable_and_write_implies_read(): void
    {
        $none = FilesystemPolicy::none();
        $policy = $none->allowRead('/srv/logs')->allowWrite('/srv/backups')->allowRead('/srv/backups');

        self::assertSame([], $none->roots());
        self::assertSame(['/srv/logs' => false, '/srv/backups' => true], $policy->roots());
    }

    // ---- symbolic links ------------------------------------------------------------------

    public function test_a_link_inside_a_root_that_points_outside_is_refused(): void
    {
        $this->link($this->outside, $this->writable . '/escape');

        foreach ([
            fn() => $this->files->inspect($this->writable . '/escape/secret.txt'),
            fn() => $this->files->write($this->writable . '/escape/planted.txt', 'x'),
            fn() => $this->files->list($this->writable . '/escape'),
        ] as $operation) {
            try {
                $operation();
                self::fail('a link was followed out of the root');
            } catch (FilesystemException $e) {
                self::assertStringContainsString('once symbolic links are followed', $e->getMessage());
            }
        }

        self::assertFileDoesNotExist($this->outside . '/planted.txt');
    }

    public function test_deleting_a_link_removes_the_link_not_what_it_points_at(): void
    {
        $this->link($this->outside, $this->writable . '/escape');
        $this->link($this->outside . '/secret.txt', $this->writable . '/secret-link');

        $this->files->delete($this->writable . '/secret-link');
        $this->files->delete($this->writable . '/escape');

        self::assertFalse(\is_link($this->writable . '/escape'));
        self::assertSame('do not read', \file_get_contents($this->outside . '/secret.txt'));
    }

    public function test_a_recursive_delete_never_descends_through_a_link(): void
    {
        \mkdir($this->writable . '/tree/deeper', 0o777, true);
        \file_put_contents($this->writable . '/tree/deeper/a.txt', 'a');
        $this->link($this->outside, $this->writable . '/tree/deeper/escape');

        $this->files->delete($this->writable . '/tree', recursive: true);

        self::assertDirectoryDoesNotExist($this->writable . '/tree');
        self::assertSame('do not read', \file_get_contents($this->outside . '/secret.txt'));
    }

    public function test_a_dangling_link_on_the_way_is_refused(): void
    {
        $this->link($this->base . '/nowhere', $this->writable . '/dangling');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('whose target does not exist');

        $this->files->write($this->writable . '/dangling/x.txt', 'x');
    }

    // ---- operations ----------------------------------------------------------------------

    public function test_write_creates_a_file_atomically_and_refuses_to_overwrite_unless_asked(): void
    {
        $path = $this->writable . '/manifest.json';

        $this->files->write($path, '{"v":1}');
        self::assertSame('{"v":1}', \file_get_contents($path));

        try {
            $this->files->write($path, '{"v":2}');
            self::fail('an existing file was replaced');
        } catch (FilesystemException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }

        $this->files->write($path, '{"v":2}', overwrite: true);

        self::assertSame('{"v":2}', \file_get_contents($path));
        self::assertSame(['manifest.json'], $this->files->list($this->writable), 'a temporary file was left behind');
    }

    public function test_write_into_a_missing_directory_fails_without_creating_it(): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('does not exist');

        $this->files->write($this->writable . '/no/such/dir/x.txt', 'x');
    }

    public function test_a_directory_is_never_replaced(): void
    {
        \mkdir($this->writable . '/dir');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('already exists');

        $this->files->write($this->writable . '/dir', 'x', overwrite: true);
    }

    public function test_directories_are_created_and_an_existing_one_is_left_alone(): void
    {
        $this->files->createDirectory($this->writable . '/2026/09', recursive: true);
        $this->files->createDirectory($this->writable . '/2026/09', recursive: true);

        self::assertDirectoryExists($this->writable . '/2026/09');
        self::assertTrue($this->files->inspect($this->writable . '/2026')->isDirectory());
    }

    public function test_permissions_with_special_bits_are_refused(): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('Permissions 4755 are not usable');

        $this->files->createDirectory($this->writable . '/setuid', 0o4755);
    }

    public function test_copy_and_move(): void
    {
        $this->files->copy($this->readable . '/report.txt', $this->writable . '/report.txt');
        $this->files->move($this->writable . '/report.txt', $this->writable . '/archived.txt');

        self::assertSame('quarterly', \file_get_contents($this->writable . '/archived.txt'));
        self::assertFileDoesNotExist($this->writable . '/report.txt');
        self::assertFileExists($this->readable . '/report.txt');
    }

    public function test_a_directory_cannot_be_copied_as_a_file(): void
    {
        \mkdir($this->writable . '/dir');

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('is not a regular file');

        $this->files->copy($this->writable . '/dir', $this->writable . '/dir2');
    }

    public function test_deleting_a_non_empty_directory_needs_recursive(): void
    {
        \mkdir($this->writable . '/full');
        \file_put_contents($this->writable . '/full/a.txt', 'a');

        try {
            $this->files->delete($this->writable . '/full');
            self::fail('a non-empty directory was deleted');
        } catch (FilesystemException $e) {
            self::assertStringContainsString('not empty', $e->getMessage());
        }

        $this->files->delete($this->writable . '/full', recursive: true);
        self::assertDirectoryDoesNotExist($this->writable . '/full');
    }

    public function test_a_root_cannot_be_deleted_or_moved(): void
    {
        foreach ([
            fn() => $this->files->delete($this->writable, recursive: true),
            fn() => $this->files->move($this->writable, $this->writable . '-moved'),
        ] as $operation) {
            try {
                $operation();
                self::fail('a root was removed');
            } catch (FilesystemException) {
                // Refused, by whichever check reaches it first.
            }
        }

        self::assertDirectoryExists($this->writable);
    }

    public function test_inspect_describes_a_file(): void
    {
        \file_put_contents($this->writable . '/a.txt', 'hello');
        $info = $this->files->inspect($this->writable . '/a.txt');

        self::assertTrue($info->isFile());
        self::assertSame(5, $info->size);
        self::assertMatchesRegularExpression('/^0[0-7]{3}$/', $info->mode());
        self::assertGreaterThan(0, $info->modified);

        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertNull($info->owner);
        } else {
            self::assertIsInt($info->owner);
        }
    }

    public function test_missing_paths_are_reported_as_missing(): void
    {
        self::assertFalse($this->files->exists($this->writable . '/nothing.txt'));

        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage('does not exist');

        $this->files->inspect($this->writable . '/nothing.txt');
    }
}
