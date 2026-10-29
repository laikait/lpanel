<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Http\UploadedFile;
use App\Engine\Security\Signer;
use App\Engine\Security\UploadPolicy;
use App\Engine\Storage\LocalDisk;
use App\Engine\Storage\MemoryDisk;
use App\Engine\Storage\S3\S3Disk;
use App\Engine\Storage\Storage;
use App\Engine\Storage\StorageException;
use App\Tests\Support\TestCase;

final class StorageSliceTest extends TestCase
{
    private string $root;

    private Application $app;

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/lphp-storage-' . \bin2hex(\random_bytes(6));
        $this->app = $this->shippedApplication([
            'security' => ['key' => Signer::generate()],
            'storage' => ['disks' => [
                'local' => ['driver' => 'local', 'root' => $this->root],
                'scratch' => ['driver' => 'memory'],
                's3' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'eu-west-1', 'key' => 'k', 'secret' => 's'],
                'broken' => ['driver' => 'ftp'],
            ]],
        ])->boot();
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->root));
    }

    private function storage(): Storage
    {
        return $this->app->container()->get(Storage::class);
    }

    private function get(string $url): Response
    {
        return $this->app->handle(Request::create('GET', $url, ['server' => ['REMOTE_ADDR' => '203.0.113.9']]));
    }

    private static function body(Response $response): string
    {
        // A callback, because a stream flushes as it goes and ob_get_clean()
        // would see nothing.
        $body = '';
        \ob_start(static function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });

        try {
            $response->send();
        } finally {
            \ob_end_clean();
        }

        return $body;
    }

    public function test_disks_come_from_configuration(): void
    {
        $storage = $this->storage();

        $local = $storage->disk();
        self::assertInstanceOf(LocalDisk::class, $local);
        self::assertSame($this->root, $local->root());
        self::assertInstanceOf(MemoryDisk::class, $storage->disk('scratch'));
        self::assertInstanceOf(S3Disk::class, $storage->disk('s3'));
        self::assertSame($storage->disk('scratch'), $storage->disk('scratch'));
    }

    public function test_an_unknown_disk_or_driver_says_what_is_configured(): void
    {
        try {
            $this->storage()->disk('nope');
            self::fail('An unknown disk was built.');
        } catch (StorageException $e) {
            self::assertStringContainsString('Configured: local, s3, scratch, broken', $e->getMessage());
        }

        $this->expectExceptionMessage('driver "ftp"');
        $this->storage()->disk('broken');
    }

    public function test_a_temporary_url_downloads_the_file_until_it_expires(): void
    {
        $disk = $this->storage()->disk('local');
        $disk->put('reports/q3 final.txt', 'revenue up');

        $url = $disk->temporaryUrl('reports/q3 final.txt', \time() + 60);
        self::assertStringStartsWith('/files/local?', $url);

        $response = $this->get($url);
        self::assertSame(200, $response->status());
        self::assertSame('revenue up', self::body($response));
        self::assertSame('text/plain', $response->header('Content-Type'));
        self::assertSame('10', $response->header('Content-Length'));
        self::assertStringStartsWith('inline; filename="q3 final.txt"', (string) $response->header('Content-Disposition'));
        self::assertSame('private, no-store', $response->header('Cache-Control'));

        self::assertSame(403, $this->get(\str_replace('q3%20final', 'q4%20final', $url))->status());
        self::assertSame(410, $this->get($disk->temporaryUrl('reports/q3 final.txt', \time() - 1))->status());
        self::assertSame(403, $this->get('/files/local?path=reports%2Fq3%20final.txt')->status());
    }

    public function test_script_capable_files_download_rather_than_render(): void
    {
        $disk = $this->storage()->disk('local');
        $disk->put('evil.html', '<html><script>alert(1)</script></html>');

        $response = $this->get($disk->temporaryUrl('evil.html', \time() + 60));

        self::assertSame('application/octet-stream', $response->header('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $response->header('Content-Disposition'));
    }

    public function test_a_signed_link_to_a_file_that_is_gone_is_404(): void
    {
        $disk = $this->storage()->disk('local');
        $disk->put('gone.txt', 'x');
        $url = $disk->temporaryUrl('gone.txt', \time() + 60);
        $disk->delete('gone.txt');

        self::assertSame(404, $this->get($url)->status());
    }

    public function test_an_upload_is_stored_on_a_disk_under_a_generated_name(): void
    {
        $temporary = (string) \tempnam(\sys_get_temp_dir(), 'up');
        \file_put_contents($temporary, \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true) ?: '');
        $file = new UploadedFile($temporary, 'me.png', 'image/png', (int) \filesize($temporary));

        try {
            $policy = UploadPolicy::images();
            self::assertSame([], $policy->check($file));

            $disk = $this->storage()->disk('scratch');
            $path = $policy->storeOn($file, $disk, 'avatars/');

            self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{32}\.png$#', $path);
            self::assertSame(\file_get_contents($temporary), $disk->get($path));
        } finally {
            @\unlink($temporary);
        }
    }

    public function test_fake_replaces_every_disk_with_memory(): void
    {
        $storage = Storage::fake(['uploads', 'local']);

        self::assertSame('uploads', $storage->defaultName());
        self::assertInstanceOf(MemoryDisk::class, $storage->disk());
        self::assertSame(['uploads', 'local'], $storage->names());
    }
}
