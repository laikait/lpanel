<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Engine\Storage\LocalDisk;
use App\Engine\Storage\StorageException;
use App\Tests\Support\TestCase;

final class LocalDiskTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/lphp-local-' . \bin2hex(\random_bytes(6));
        \mkdir($this->base . '/root', 0o777, true);
        \mkdir($this->base . '/outside', 0o777, true);
        \file_put_contents($this->base . '/outside/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->base));
    }

    /**
     * Names Windows reads as something else: a data stream, a name whose
     * trailing dot or space it drops, a device. Refused on every system, so a
     * path that works on a laptop cannot misbehave on a Windows server.
     */
    public function test_names_windows_would_read_differently_are_refused(): void
    {
        $disk = new LocalDisk($this->base . '/root');
        $disk->put('report.php', 'x');

        foreach (['report.txt:hidden', 'a/b.txt::$DATA', 'report.php.', 'report.php ', 'dir./x', 'CON', 'nul.txt',
            'a/COM1', 'Lpt9.log', 'aux'] as $path) {
            foreach ([
                static fn() => $disk->put($path, 'x'),
                static fn() => $disk->get($path),
                static fn() => $disk->exists($path),
                static fn() => $disk->delete($path),
            ] as $call) {
                try {
                    $call();
                    self::fail(\sprintf('"%s" was accepted.', $path));
                } catch (StorageException $e) {
                    self::assertStringContainsString('is not a storage path', $e->getMessage());
                }
            }
        }

        // Names that merely look similar are ordinary files.
        foreach (['console.txt', 'nullable.json', 'com10.txt', 'a.b.c', 'reports/2026-10-25.csv'] as $path) {
            $disk->put($path, 'ok');
            self::assertSame('ok', $disk->get($path));
        }
    }

    /** S3 and memory keys are not files: ":" stays an ordinary character there. */
    public function test_other_disks_keep_the_shorter_rule(): void
    {
        $disk = new \App\Engine\Storage\MemoryDisk('memory');
        $disk->put('logs/2026-10-25T10:00:00.log', 'x');

        self::assertTrue($disk->exists('logs/2026-10-25T10:00:00.log'));
    }

    public function test_a_symlink_out_of_the_root_is_neither_read_nor_written_through(): void
    {
        if (!\function_exists('symlink') || \PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('No symlinks here.');
        }

        \symlink($this->base . '/outside', $this->base . '/root/escape');
        \symlink($this->base . '/outside/secret.txt', $this->base . '/root/secret.txt');
        $disk = new LocalDisk($this->base . '/root');

        self::assertFalse($disk->exists('escape/secret.txt'));
        self::assertFalse($disk->exists('secret.txt'));
        self::assertSame([], $disk->files());
        self::assertFalse($disk->delete('secret.txt'));
        self::assertFileExists($this->base . '/outside/secret.txt');

        try {
            $disk->put('escape/new/planted.txt', 'x');
            self::fail('A write went through a symlink out of the disk.');
        } catch (StorageException $e) {
            self::assertStringContainsString('outside the disk', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->base . '/outside/new');
    }

    public function test_the_root_is_created_on_the_first_write(): void
    {
        $disk = new LocalDisk($this->base . '/later/deeper');

        self::assertSame([], $disk->files());
        $disk->put('x.txt', 'x');

        self::assertFileExists($this->base . '/later/deeper/x.txt');
    }

    public function test_a_write_in_flight_is_never_listed(): void
    {
        $disk = new LocalDisk($this->base . '/root');
        $disk->put('a.txt', 'x');
        \file_put_contents($this->base . '/root/.a.txt.0123456789abcdef.part', 'half');

        self::assertSame(['a.txt'], $disk->files());
    }

    public function test_without_a_signer_there_are_no_temporary_urls(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('cannot make temporary URLs');

        (new LocalDisk($this->base . '/root'))->temporaryUrl('a.txt', \time() + 60);
    }
}
