<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Client;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\CurlTransport;
use App\Engine\Http\Client\HttpClientException;
use App\Engine\Http\Client\StreamTransport;
use App\Engine\Http\Client\Transport;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Both transports against a real HTTP server on this machine: PHP's built-in
 * one, serving tests/Fixtures/Http/server.php. Nothing leaves the machine.
 */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        $router = \dirname(__DIR__, 3) . '/Fixtures/Http/server.php';
        $process = \proc_open([\PHP_BINARY, '-S', $address, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        self::$server = $process;
        self::$base = 'http://' . $address;

        // Ready when it accepts a connection; a few hundred milliseconds at most.
        for ($i = 0; $i < 100; ++$i) {
            $probe = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);

            if ($probe !== false) {
                \fclose($probe);

                return;
            }

            \usleep(20_000);
        }

        self::fail('the fixture server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
    }

    /** @return array<string, array{\Closure(): Transport}> */
    public static function transports(): array
    {
        return [
            'stream' => [static fn(): Transport => new StreamTransport()],
            'curl' => [static fn(): Transport => new CurlTransport()],
        ];
    }

    private function client(Transport $transport): Client
    {
        if ($transport instanceof CurlTransport && !CurlTransport::available()) {
            self::markTestSkipped('the curl extension is not loaded');
        }

        return new Client($transport, timeout: 5);
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_a_request_and_its_answer(\Closure $transport): void
    {
        $response = $this->client($transport())
            ->withHeaders(['X-Hello' => 'world'])
            ->post(self::$base . '/echo?a=1', ['name' => 'Ana']);

        self::assertSame(200, $response->status());
        self::assertSame('yes', $response->header('x-fixture'));
        self::assertSame('POST', $response->json('method'));
        self::assertSame('1', $response->json('query.a'));
        self::assertSame('world', $response->json('headers.x-hello'));
        self::assertSame('application/json', $response->json('headers.content-type'));
        self::assertSame('{"name":"Ana"}', $response->json('body'));
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_an_error_status_is_an_answer_not_an_exception(\Closure $transport): void
    {
        $response = $this->client($transport())->get(self::$base . '/status/404');

        self::assertSame(404, $response->status());
        self::assertSame('status 404', $response->body());
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_a_redirect_is_followed_by_the_client(\Closure $transport): void
    {
        $response = $this->client($transport())->post(self::$base . '/redirect/303', ['a' => 1]);

        self::assertSame(200, $response->status());
        self::assertSame('GET', $response->json('method'));
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_a_redirect_loop_ends(\Closure $transport): void
    {
        $this->expectException(HttpClientException::class);
        $this->client($transport())->get(self::$base . '/loop');
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_a_slow_answer_times_out(\Closure $transport): void
    {
        $this->expectException(HttpClientException::class);
        $this->client($transport())->timeout(0.5)->get(self::$base . '/slow');
    }

    /** @param \Closure(): Transport $transport */
    #[DataProvider('transports')]
    public function test_nothing_listening_is_a_connection_error(\Closure $transport): void
    {
        $this->expectException(HttpClientException::class);
        $this->client($transport())->get('http://127.0.0.1:1/');
    }
}
