<?php

declare(strict_types=1);

namespace App\Engine\Storage\S3;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Storage\Contents;
use App\Engine\Storage\Disk;
use App\Engine\Storage\MemoryDisk;
use App\Engine\Storage\Storage;
use App\Engine\Storage\StorageException;
use App\Engine\Storage\StoragePath;

/**
 * An S3 bucket, or anything that speaks its API: R2, Spaces, B2, MinIO, Wasabi.
 *
 *     'uploads' => [
 *         'driver' => 's3',
 *         'bucket' => 'shop-uploads',
 *         'region' => 'eu-central-1',
 *         'key' => env('S3_KEY'), 'secret' => env('S3_SECRET'),
 *         'endpoint' => null,          // https://<account>.r2.cloudflarestorage.com, http://127.0.0.1:9000…
 *         'path_style' => false,       // true for MinIO and most self-hosted servers
 *         'url' => null,               // a public base URL (a CDN) for url()
 *         'root' => '',                // a prefix inside the bucket
 *     ],
 *
 * Every request goes through Http\Client, signed by SignatureV4; there is no
 * SDK. Files over 16 MB are sent as a multipart upload, 16 MB at a time, and a
 * failed one is aborted so the bucket is not billed for the parts.
 *
 * **delete() costs a HEAD first**, because S3 answers 204 to deleting nothing
 * and the Disk contract says whether there was something.
 *
 * **readStream() buffers** the object into php://temp (memory up to 2 MB, a
 * temporary file beyond): the client reads whole responses.
 */
final class S3Disk implements Disk
{
    /** Above this, put() uploads in parts of this size. S3's own minimum part is 5 MB. */
    public const PART_BYTES = 16777216;

    private readonly SignatureV4 $signature;

    private readonly string $root;

    public function __construct(
        private readonly Client $http,
        private readonly string $bucket,
        private readonly string $region,
        string $key,
        #[\SensitiveParameter]
        string $secret,
        private readonly ?string $endpoint = null,
        private readonly bool $pathStyle = false,
        private readonly ?string $url = null,
        string $root = '',
        private readonly string $name = 's3',
        private readonly int $partBytes = self::PART_BYTES,
    ) {
        if (!\function_exists('simplexml_load_string')) {
            throw StorageException::misconfigured($name, 'the s3 driver needs PHP\'s simplexml extension.');
        }

        if ($bucket === '' || $region === '' || $key === '' || $secret === '') {
            throw StorageException::misconfigured($name, 'bucket, region, key and secret are all required.');
        }

        $this->signature = new SignatureV4($key, $secret, $region);
        $this->root = StoragePath::prefix($root);
    }

    public function put(string $path, mixed $contents): void
    {
        $key = $this->key($path);

        if (\is_string($contents) && \strlen($contents) <= $this->partBytes) {
            $this->putObject($key, $path, $contents);

            return;
        }

        $stream = Contents::stream($contents);
        $first = self::read($stream, $this->partBytes);

        if (\feof($stream)) {
            $this->putObject($key, $path, $first);

            return;
        }

        $this->multipart($key, $path, $first, $stream);
    }

    public function get(string $path): string
    {
        return $this->expect($this->request('GET', $this->objectUrl($this->key($path))), 'Reading', $path)->body();
    }

    public function readStream(string $path): mixed
    {
        return Contents::fromString($this->get($path));
    }

    public function exists(string $path): bool
    {
        return $this->head($path) !== null;
    }

    public function delete(string $path): bool
    {
        if ($this->head($path) === null) {
            return false;
        }

        $response = $this->request('DELETE', $this->objectUrl($this->key($path)));

        if (!$response->successful()) {
            throw StorageException::remote($this->name, 'Deleting', $path, $response->status(), self::code($response));
        }

        return true;
    }

    public function size(string $path): int
    {
        $head = $this->head($path) ?? throw StorageException::notFound($this->name, $path);

        return (int) ($head->header('Content-Length') ?? 0);
    }

    public function lastModified(string $path): int
    {
        $head = $this->head($path) ?? throw StorageException::notFound($this->name, $path);
        $time = \strtotime((string) $head->header('Last-Modified'));

        return $time === false ? throw StorageException::unreadable($this->name, $path, 'it has no Last-Modified.') : $time;
    }

    public function files(string $prefix = ''): array
    {
        $prefix = StoragePath::prefix($prefix);
        $full = \implode('/', \array_filter([$this->root, $prefix], static fn(string $part): bool => $part !== ''));
        $found = [];
        $token = null;

        do {
            $query = ['list-type' => '2'];

            if ($full !== '') {
                $query['prefix'] = $full . '/';
            }

            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            $response = $this->request('GET', $this->bucketUrl() . '?' . \http_build_query($query, '', '&', \PHP_QUERY_RFC3986));
            $xml = $this->xml($this->expect($response, 'Listing', $prefix === '' ? '/' : $prefix));

            foreach ($xml->Contents as $object) {
                $key = (string) $object->Key;

                // A "directory marker" some tools create: a zero-byte "a/".
                if (\str_ends_with($key, '/')) {
                    continue;
                }

                $found[] = $this->root === '' ? $key : \substr($key, \strlen($this->root) + 1);
            }

            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');

        \sort($found, \SORT_STRING);

        return $found;
    }

    public function url(string $path): ?string
    {
        $path = StoragePath::file($path);

        return $this->url === null ? null : \rtrim($this->url, '/') . '/' . MemoryDisk::encode($path);
    }

    public function temporaryUrl(string $path, int $expiresAt): string
    {
        return $this->signature->presign('GET', $this->objectUrl($this->key($path)), $expiresAt - \time());
    }

    // ---- requests ----------------------------------------------------------

    private function putObject(string $key, string $path, string $body): void
    {
        $response = $this->request('PUT', $this->objectUrl($key), $body, ['Content-Type' => self::mediaType($body)]);

        if (!$response->successful()) {
            throw StorageException::remote($this->name, 'Writing', $path, $response->status(), self::code($response));
        }
    }

    /** @param resource $stream */
    private function multipart(string $key, string $path, string $first, mixed $stream): void
    {
        $object = $this->objectUrl($key);
        $started = $this->request('POST', $object . '?uploads', '', ['Content-Type' => self::mediaType($first)]);
        $upload = (string) $this->xml($this->expect($started, 'Starting an upload of', $path))->UploadId;

        if ($upload === '') {
            throw StorageException::remote($this->name, 'Starting an upload of', $path, $started->status(), 'no UploadId');
        }

        $parts = [];

        try {
            $chunk = $first;
            $number = 1;

            while ($chunk !== '') {
                $response = $this->request('PUT', $object . '?' . \http_build_query(['partNumber' => $number, 'uploadId' => $upload], '', '&', \PHP_QUERY_RFC3986), $chunk);

                if (!$response->successful()) {
                    throw StorageException::remote($this->name, 'Uploading part ' . $number . ' of', $path, $response->status(), self::code($response));
                }

                $parts[$number] = (string) $response->header('ETag');
                ++$number;
                $chunk = \feof($stream) ? '' : self::read($stream, $this->partBytes);
            }

            $xml = '<CompleteMultipartUpload>';

            foreach ($parts as $number => $etag) {
                $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . \htmlspecialchars($etag, \ENT_XML1) . '</ETag></Part>';
            }

            $xml .= '</CompleteMultipartUpload>';
            $done = $this->request('POST', $object . '?' . \http_build_query(['uploadId' => $upload], '', '&', \PHP_QUERY_RFC3986), $xml, ['Content-Type' => 'application/xml']);

            // S3 can answer 200 with an <Error> body when completing fails late.
            if (!$done->successful() || \str_contains($done->body(), '<Error>')) {
                throw StorageException::remote($this->name, 'Completing the upload of', $path, $done->status(), self::code($done));
            }
        } catch (\Throwable $e) {
            try {
                $this->request('DELETE', $object . '?' . \http_build_query(['uploadId' => $upload], '', '&', \PHP_QUERY_RFC3986));
            } catch (\Throwable) {
                // The original failure is the one worth reporting; a lifecycle
                // rule on the bucket cleans up an abandoned upload eventually.
            }

            throw $e;
        }
    }

    private function head(string $path): ?ClientResponse
    {
        $response = $this->request('HEAD', $this->objectUrl($this->key($path)));

        if ($response->status() === 404) {
            return null;
        }

        if (!$response->successful()) {
            throw StorageException::remote($this->name, 'Checking', $path, $response->status(), '');
        }

        return $response;
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $url, string $body = '', array $headers = []): ClientResponse
    {
        $hash = \hash('sha256', $body);
        $signed = $this->signature->authorize($method, $url, [...$headers, 'x-amz-content-sha256' => $hash], $hash);

        // The transport derives Host from the URL, exactly as it was signed.
        unset($signed['Host']);

        return $this->http->withHeaders($signed)->send($method, $url, $body === '' ? null : $body);
    }

    private function expect(ClientResponse $response, string $operation, string $path): ClientResponse
    {
        if ($response->status() === 404 && self::code($response) !== 'NoSuchBucket') {
            throw StorageException::notFound($this->name, $path);
        }

        if (!$response->successful()) {
            throw StorageException::remote($this->name, $operation, $path, $response->status(), self::code($response));
        }

        return $response;
    }

    private function xml(ClientResponse $response): \SimpleXMLElement
    {
        $previous = \libxml_use_internal_errors(true);

        try {
            $xml = \simplexml_load_string($response->body(), \SimpleXMLElement::class, \LIBXML_NONET);
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw StorageException::remote($this->name, 'Reading the answer for', $this->bucket, $response->status(), 'not XML');
        }

        return $xml;
    }

    // ---- addresses ---------------------------------------------------------

    private function key(string $path): string
    {
        $path = StoragePath::file($path);

        return $this->root === '' ? $path : $this->root . '/' . $path;
    }

    private function objectUrl(string $key): string
    {
        return $this->bucketUrl() . MemoryDisk::encode($key);
    }

    /** The bucket's URL, ending in a slash. */
    private function bucketUrl(): string
    {
        $endpoint = $this->endpoint === null || $this->endpoint === ''
            ? 'https://s3.' . $this->region . '.amazonaws.com'
            : \rtrim($this->endpoint, '/');

        if ($this->pathStyle) {
            return $endpoint . '/' . \rawurlencode($this->bucket) . '/';
        }

        $parts = \parse_url($endpoint);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https';
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        $port = \is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $this->bucket . '.' . $host . $port . '/';
    }

    // ---- helpers -----------------------------------------------------------

    /** @param resource $stream */
    private static function read(mixed $stream, int $bytes): string
    {
        $buffer = '';

        while (\strlen($buffer) < $bytes && !\feof($stream)) {
            $chunk = \fread($stream, \max(1, \min(1048576, $bytes - \strlen($buffer))));

            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * The Content-Type an object is stored with, and so served with.
     *
     * Only a type on Storage::INLINE_TYPES is kept. The bucket serves an
     * object with whatever type it was stored under, to anyone holding a
     * presigned or public URL, so an uploaded .html stored as text/html was
     * a page running script on the bucket's origin. Everything else is
     * stored as application/octet-stream, which browsers download.
     */
    private static function mediaType(string $body): string
    {
        if ($body !== '' && \class_exists(\finfo::class)) {
            $type = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($body);

            if (\is_string($type) && \in_array($type, Storage::INLINE_TYPES, true)) {
                return $type;
            }
        }

        return 'application/octet-stream';
    }

    /** S3's error code from an <Error> body: NoSuchKey, AccessDenied, SignatureDoesNotMatch… */
    private static function code(ClientResponse $response): string
    {
        return \preg_match('#<Code>([^<]+)</Code>#', $response->body(), $match) === 1 ? $match[1] : '';
    }
}
