<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Engine\Http\Client\Client;
use App\Engine\Storage\Disk;
use App\Engine\Storage\LocalDisk;
use App\Engine\Storage\MemoryDisk;
use App\Engine\Storage\S3\S3Disk;
use App\Engine\Storage\StorageException;
use App\Tests\Fixtures\Storage\FakeS3;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * One contract, every disk. A disk that passes this can stand in for any other.
 */
final class DiskConformanceTest extends TestCase
{
    /** @var list<string> */
    private static array $directories = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$directories as $directory) {
            self::remove($directory);
        }
    }

    /** @return iterable<string, array{\Closure(): Disk}> */
    public static function disks(): iterable
    {
        yield 'memory' => [static fn(): Disk => new MemoryDisk('memory', 'https://cdn.example/m')];

        yield 'local' => [static function (): Disk {
            $root = \sys_get_temp_dir() . '/lphp-disk-' . \bin2hex(\random_bytes(6));
            self::$directories[] = $root;

            return new LocalDisk($root, 'local', 'https://cdn.example/m', static fn(string $path, int $at): string => '/files/local?path=' . \rawurlencode($path) . '&expires=' . $at);
        }];

        yield 's3, path style' => [static fn(): Disk => new S3Disk(
            new Client(new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1')),
            'bucket',
            'eu-central-1',
            'AKID',
            'secret',
            'http://127.0.0.1:9000',
            true,
            'https://cdn.example/m',
            partBytes: 7,
        )];

        yield 's3, virtual host, under a root' => [static fn(): Disk => new S3Disk(
            new Client(new FakeS3('bucket', 'AKID', 'secret', 'us-east-1')),
            'bucket',
            'us-east-1',
            'AKID',
            'secret',
            url: 'https://cdn.example/m',
            root: 'tenant/7',
            partBytes: 7,
        )];
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_a_file_comes_back(\Closure $make): void
    {
        $disk = $make();
        $disk->put('a/b/hello.txt', 'Hello, world');

        self::assertTrue($disk->exists('a/b/hello.txt'));
        self::assertSame('Hello, world', $disk->get('a/b/hello.txt'));
        self::assertSame(12, $disk->size('a/b/hello.txt'));
        self::assertEqualsWithDelta(\time(), $disk->lastModified('a/b/hello.txt'), 5);
        self::assertSame('Hello, world', \stream_get_contents($disk->readStream('a/b/hello.txt')));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_put_replaces(\Closure $make): void
    {
        $disk = $make();
        $disk->put('x.txt', 'one');
        $disk->put('x.txt', 'two');

        self::assertSame('two', $disk->get('x.txt'));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_a_stream_is_written_whole(\Closure $make): void
    {
        $disk = $make();
        $bytes = \random_bytes(100) . "\0\r\n" . \str_repeat('z', 30);
        $stream = \fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        \fwrite($stream, $bytes);
        \rewind($stream);

        // Bigger than the S3 disks' 7-byte part: a multipart upload there.
        $disk->put('bin/blob', $stream);

        self::assertSame($bytes, $disk->get('bin/blob'));
        self::assertSame(\strlen($bytes), $disk->size('bin/blob'));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_an_empty_file_is_a_file(\Closure $make): void
    {
        $disk = $make();
        $disk->put('empty', '');

        self::assertTrue($disk->exists('empty'));
        self::assertSame('', $disk->get('empty'));
        self::assertSame(0, $disk->size('empty'));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_what_is_not_there(\Closure $make): void
    {
        $disk = $make();

        self::assertFalse($disk->exists('nothing.txt'));
        self::assertFalse($disk->delete('nothing.txt'));
        self::assertSame([], $disk->files());

        foreach ([
            static fn() => $disk->get('nothing.txt'),
            static fn() => $disk->size('nothing.txt'),
            static fn() => $disk->lastModified('nothing.txt'),
            static fn() => $disk->readStream('nothing.txt'),
        ] as $call) {
            try {
                $call();
                self::fail('A missing file was read.');
            } catch (StorageException $e) {
                self::assertStringContainsString('There is no "nothing.txt"', $e->getMessage());
            }
        }
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_delete(\Closure $make): void
    {
        $disk = $make();
        $disk->put('d/gone.txt', 'x');

        self::assertTrue($disk->delete('d/gone.txt'));
        self::assertFalse($disk->exists('d/gone.txt'));
        self::assertSame([], $disk->files('d'));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_files_lists_everything_under_a_prefix(\Closure $make): void
    {
        $disk = $make();

        foreach (['a/1.txt', 'a/b/2.txt', 'a/b/c/3.txt', 'ab.txt', 'z.txt'] as $path) {
            $disk->put($path, $path);
        }

        self::assertSame(['a/1.txt', 'a/b/2.txt', 'a/b/c/3.txt', 'ab.txt', 'z.txt'], $disk->files());
        self::assertSame(['a/1.txt', 'a/b/2.txt', 'a/b/c/3.txt'], $disk->files('a'));
        self::assertSame(['a/b/2.txt', 'a/b/c/3.txt'], $disk->files('a/b/'));
        self::assertSame([], $disk->files('nope'));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_names_that_need_encoding(\Closure $make): void
    {
        $disk = $make();
        $path = 'docs/rapport final+v2 (copy)/ঢাকা #1?.txt';
        $disk->put($path, 'ok');

        self::assertSame('ok', $disk->get($path));
        self::assertSame([$path], $disk->files('docs'));
        self::assertSame('https://cdn.example/m/docs/rapport%20final%2Bv2%20%28copy%29/' . \rawurlencode('ঢাকা') . '%20%231%3F.txt', $disk->url($path));
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_no_path_leaves_the_disk(\Closure $make): void
    {
        $disk = $make();

        foreach (['', '/etc/passwd', '../x', 'a/../../x', 'a//b', './a', 'a\\b', "a\0b", 'C:/x', 'a/./b'] as $path) {
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

        $this->expectException(StorageException::class);
        $disk->files('../');
    }

    /** @param \Closure(): Disk $make */
    #[DataProvider('disks')]
    public function test_a_temporary_url_carries_its_expiry(\Closure $make): void
    {
        $disk = $make();
        $disk->put('t.txt', 'x');
        $at = \time() + 600;

        $url = $disk->temporaryUrl('t.txt', $at);

        self::assertStringContainsString('t.txt', $url);
        self::assertMatchesRegularExpression('/[?&](expires=' . $at . '|X-Amz-Expires=(599|600))(&|$)/', $url);
    }

    private static function remove(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);

            return;
        }

        if (!\is_dir($path)) {
            return;
        }

        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        @\rmdir($path);
    }
}
