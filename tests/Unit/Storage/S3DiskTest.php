<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\CurlTransport;
use App\Engine\Http\Client\StreamTransport;
use App\Engine\Http\Client\Transport;
use App\Engine\Storage\S3\S3Disk;
use App\Engine\Storage\StorageException;
use App\Tests\Fixtures\Storage\FakeS3;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class S3DiskTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    private static string $state = '';

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
            self::$server = null;
        }

        if (self::$state !== '') {
            @\unlink(self::$state);
        }
    }

    private static function disk(Transport $transport, string $secret = 'secret', int $partBytes = S3Disk::PART_BYTES): S3Disk
    {
        return new S3Disk(new Client($transport), 'bucket', 'eu-central-1', 'AKID', $secret, 'http://127.0.0.1:9000', true, partBytes: $partBytes);
    }

    public function test_a_wrong_secret_is_reported_with_s3s_own_code(): void
    {
        $disk = self::disk(new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1'), 'not-the-secret');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('HTTP 403 (SignatureDoesNotMatch)');

        $disk->put('a.txt', 'x');
    }

    public function test_a_failed_part_aborts_the_upload(): void
    {
        $s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1');
        $parts = 0;
        $transport = new class ($s3, $parts) implements Transport {
            public function __construct(private readonly FakeS3 $s3, private int &$parts) {}

            public function send(ClientRequest $request): ClientResponse
            {
                if (\str_contains($request->url, 'partNumber=') && ++$this->parts === 2) {
                    return new ClientResponse(500, [], '<Error><Code>InternalError</Code></Error>');
                }

                return $this->s3->send($request);
            }
        };

        try {
            self::disk($transport, partBytes: 4)->put('big.bin', \str_repeat('x', 10));
            self::fail('The upload succeeded.');
        } catch (StorageException $e) {
            self::assertStringContainsString('Uploading part 2', $e->getMessage());
        }

        self::assertSame([], $s3->uploads, 'The upload was left open.');
        self::assertSame([], $s3->objects);
        self::assertStringStartsWith('DELETE /bucket/big.bin?uploadId=', (string) \end($s3->log));
    }

    public function test_a_presigned_url_works_without_credentials_and_only_for_its_file(): void
    {
        $s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1');
        $disk = self::disk($s3);
        $disk->put('report.pdf', '%PDF-1.4');

        $url = $disk->temporaryUrl('report.pdf', \time() + 300);
        $anonymous = new Client($s3);

        self::assertSame('%PDF-1.4', $anonymous->get($url)->body());
        self::assertSame(403, $anonymous->get(\str_replace('report.pdf', 'other.pdf', $url))->status());
        self::assertSame(403, $anonymous->get(\str_replace('X-Amz-Expires=', 'X-Amz-Expires=9', $url))->status());
    }

    public function test_list_follows_continuation_tokens(): void
    {
        $s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1', pageSize: 2);
        $disk = self::disk($s3);

        foreach (\range(1, 5) as $n) {
            $disk->put('p/' . $n, (string) $n);
        }

        self::assertSame(['p/1', 'p/2', 'p/3', 'p/4', 'p/5'], $disk->files('p'));
        self::assertCount(3, \array_filter($s3->log, static fn(string $line): bool => \str_contains($line, 'list-type=2')));
    }

    public function test_the_media_type_is_sent(): void
    {
        $s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1');
        self::disk($s3)->put('a.png', \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true) ?: '');

        self::assertSame('image/png', $s3->objects['a.png']['type']);
    }

    /**
     * The bucket serves an object with the type it was stored under, to
     * anyone with the URL. An uploaded page stored as text/html ran script on
     * the bucket's origin; stored as application/octet-stream, it downloads.
     */
    public function test_a_file_that_could_run_script_is_stored_to_download(): void
    {
        $s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1');
        $disk = self::disk($s3);

        $disk->put('page.html', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
        $disk->put('icon.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $disk->put('notes.txt', 'just text');

        self::assertSame('application/octet-stream', $s3->objects['page.html']['type']);
        self::assertSame('application/octet-stream', $s3->objects['icon.svg']['type']);
        self::assertSame('text/plain', $s3->objects['notes.txt']['type']);
    }

    public function test_it_needs_its_credentials(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('bucket, region, key and secret are all required');

        new S3Disk(new Client(), 'bucket', 'eu-central-1', '', '');
    }

    /** @return array<string, array{\Closure(): Transport}> */
    public static function transports(): array
    {
        return [
            'stream' => [static fn(): Transport => new StreamTransport()],
            'curl' => [static fn(): Transport => new CurlTransport()],
        ];
    }

    /**
     * The whole protocol over a real socket, through each real transport, so
     * what the signature covers is what actually goes on the wire.
     *
     * @param \Closure(): Transport $make
     */
    #[DataProvider('transports')]
    public function test_over_a_real_socket(\Closure $make): void
    {
        $transport = $make();

        if ($transport instanceof CurlTransport && !CurlTransport::available()) {
            self::markTestSkipped('the curl extension is not loaded');
        }

        self::start();
        $disk = new S3Disk(new Client($transport, timeout: 5), 'bucket', 'eu-central-1', 'AKID', 'secret', self::$base, true, partBytes: 6);
        $name = $transport::class === CurlTransport::class ? 'curl' : 'stream';

        $disk->put($name . '/small dir/a+b.txt', 'hello');
        $disk->put($name . '/big.bin', \str_repeat('0123456789', 3));

        self::assertSame('hello', $disk->get($name . '/small dir/a+b.txt'));
        self::assertSame(\str_repeat('0123456789', 3), $disk->get($name . '/big.bin'));
        self::assertSame(30, $disk->size($name . '/big.bin'));
        self::assertSame([$name . '/big.bin', $name . '/small dir/a+b.txt'], $disk->files($name));
        self::assertSame('hello', (new Client($transport, timeout: 5))->get($disk->temporaryUrl($name . '/small dir/a+b.txt', \time() + 60))->body());
        self::assertTrue($disk->delete($name . '/big.bin'));
        self::assertFalse($disk->exists($name . '/big.bin'));
    }

    private static function start(): void
    {
        if (self::$server !== null) {
            return;
        }

        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        self::$state = \sys_get_temp_dir() . '/lphp-fake-s3-' . \bin2hex(\random_bytes(6));
        $router = \dirname(__DIR__, 2) . '/Fixtures/Storage/s3-router.php';
        $process = \proc_open(
            [\PHP_BINARY, '-S', $address, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [...\getenv(), 'LPHP_FAKE_S3_STATE' => self::$state],
        );
        self::assertIsResource($process);
        self::$server = $process;
        self::$base = 'http://' . $address;

        for ($i = 0; $i < 100; ++$i) {
            $probe = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);

            if ($probe !== false) {
                \fclose($probe);

                return;
            }

            \usleep(20_000);
        }

        self::fail('the fake S3 server did not start');
    }
}
