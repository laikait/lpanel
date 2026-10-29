<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Storage;

use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\Transport;
use App\Engine\Storage\S3\SignatureV4;

/**
 * Just enough of S3 to hold S3Disk to the protocol: objects, HEAD, listing
 * with continuation, multipart uploads and presigned GETs -- and every request
 * refused unless its SigV4 signature and payload hash check out.
 *
 * In process as a Transport, or behind `php -S` through s3-router.php.
 */
final class FakeS3 implements Transport
{
    /** @var array<string, array{body: string, type: string, modified: int}> */
    public array $objects = [];

    /** @var array<string, array<int, string>> upload id => part number => bytes */
    public array $uploads = [];

    /** @var list<string> "METHOD path?query", for asserting on traffic */
    public array $log = [];

    public function __construct(
        private readonly string $bucket,
        private readonly string $key,
        private readonly string $secret,
        private readonly string $region,
        private readonly int $pageSize = 2,
    ) {}

    public function send(ClientRequest $request): ClientResponse
    {
        $parts = \parse_url($request->url);
        \assert(\is_array($parts));
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');
        $query = [];
        \parse_str((string) ($parts['query'] ?? ''), $query);
        $this->log[] = $request->method . ' ' . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');

        if (\str_starts_with($host, $this->bucket . '.')) {
            $key = \rawurldecode(\ltrim($path, '/'));
        } else {
            $segments = \explode('/', \ltrim($path, '/'), 2);

            if ($segments[0] !== $this->bucket) {
                return self::error(404, 'NoSuchBucket');
            }

            $key = \rawurldecode($segments[1] ?? '');
        }

        if (isset($query['X-Amz-Signature'])) {
            return $this->presigned($request, $query, $key);
        }

        if (!$this->authentic($request)) {
            return self::error(403, 'SignatureDoesNotMatch');
        }

        return match (true) {
            $key === '' && $request->method === 'GET' => $this->list($query),
            $request->method === 'POST' && \array_key_exists('uploads', $query) => $this->initiate(),
            $request->method === 'POST' && isset($query['uploadId']) => $this->complete($key, self::param($query, 'uploadId'), $request),
            $request->method === 'PUT' && isset($query['uploadId']) => $this->part(self::param($query, 'uploadId'), (int) self::param($query, 'partNumber'), $request->body),
            $request->method === 'DELETE' && isset($query['uploadId']) => $this->abort(self::param($query, 'uploadId')),
            $request->method === 'PUT' => $this->store($key, $request->body, $request->header('Content-Type') ?? 'application/octet-stream'),
            $request->method === 'GET' => $this->read($key, true),
            $request->method === 'HEAD' => $this->read($key, false),
            $request->method === 'DELETE' => $this->remove($key),
            default => self::error(405, 'MethodNotAllowed'),
        };
    }

    private function authentic(ClientRequest $request): bool
    {
        $authorization = $request->header('Authorization') ?? '';
        $date = $request->header('X-Amz-Date') ?? '';
        $hash = $request->header('x-amz-content-sha256') ?? '';

        if ($hash !== \hash('sha256', $request->body)
            || \preg_match('/SignedHeaders=([^,]+)/', $authorization, $match) !== 1
            || \preg_match('/^\d{8}T\d{6}Z$/', $date) !== 1
        ) {
            return false;
        }

        $headers = [];

        foreach (\explode(';', $match[1]) as $name) {
            if ($name !== 'host' && $name !== 'x-amz-date') {
                $headers[$name] = $request->header($name) ?? '';
            }
        }

        $expected = (new SignatureV4($this->key, $this->secret, $this->region))->authorize(
            $request->method,
            $request->url,
            $headers,
            $hash,
            new \DateTimeImmutable($date),
        );

        return \hash_equals($expected['Authorization'], $authorization);
    }

    /** @param array<array-key, mixed> $query */
    private function presigned(ClientRequest $request, array $query, string $key): ClientResponse
    {
        $date = (string) ($query['X-Amz-Date'] ?? '');
        $seconds = (int) ($query['X-Amz-Expires'] ?? 0);
        $base = \strstr($request->url, '?', true);
        \assert(\is_string($base));

        $expected = (new SignatureV4($this->key, $this->secret, $this->region))
            ->presign($request->method, $base, $seconds, new \DateTimeImmutable($date));

        if (!\hash_equals($expected, $request->url)) {
            return self::error(403, 'SignatureDoesNotMatch');
        }

        if ((new \DateTimeImmutable($date))->getTimestamp() + $seconds < \time()) {
            return self::error(403, 'AccessDenied');
        }

        return $this->read($key, $request->method !== 'HEAD');
    }

    /** @param array<array-key, mixed> $query */
    private function list(array $query): ClientResponse
    {
        $prefix = (string) ($query['prefix'] ?? '');
        $after = isset($query['continuation-token']) ? (string) \base64_decode((string) $query['continuation-token'], true) : '';
        $keys = \array_values(\array_filter(
            \array_keys($this->objects),
            static fn(string $key): bool => \str_starts_with($key, $prefix) && ($after === '' || \strcmp($key, $after) > 0),
        ));
        \sort($keys, \SORT_STRING);

        $page = \array_slice($keys, 0, $this->pageSize);
        $truncated = \count($keys) > $this->pageSize;

        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            . '<Name>' . $this->bucket . '</Name><IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';

        foreach ($page as $key) {
            $xml .= '<Contents><Key>' . \htmlspecialchars($key, \ENT_XML1) . '</Key><Size>' . \strlen($this->objects[$key]['body']) . '</Size></Contents>';
        }

        if ($truncated) {
            $xml .= '<NextContinuationToken>' . \base64_encode((string) \end($page)) . '</NextContinuationToken>';
        }

        return new ClientResponse(200, ['Content-Type' => 'application/xml'], $xml . '</ListBucketResult>');
    }

    private function store(string $key, string $body, string $type): ClientResponse
    {
        $this->objects[$key] = ['body' => $body, 'type' => $type, 'modified' => \time()];

        return new ClientResponse(200, ['ETag' => '"' . \md5($body) . '"']);
    }

    private function read(string $key, bool $withBody): ClientResponse
    {
        $object = $this->objects[$key] ?? null;

        if ($object === null) {
            return $withBody ? self::error(404, 'NoSuchKey') : new ClientResponse(404);
        }

        return new ClientResponse(200, [
            'Content-Type' => $object['type'],
            'Content-Length' => (string) \strlen($object['body']),
            'Last-Modified' => \gmdate('D, d M Y H:i:s \G\M\T', $object['modified']),
        ], $withBody ? $object['body'] : '');
    }

    private function remove(string $key): ClientResponse
    {
        unset($this->objects[$key]);

        return new ClientResponse(204);
    }

    private function initiate(): ClientResponse
    {
        $id = \bin2hex(\random_bytes(8));
        $this->uploads[$id] = [];

        return new ClientResponse(200, [], '<?xml version="1.0"?><InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>');
    }

    private function part(string $upload, int $number, string $body): ClientResponse
    {
        if (!isset($this->uploads[$upload])) {
            return self::error(404, 'NoSuchUpload');
        }

        $this->uploads[$upload][$number] = $body;

        return new ClientResponse(200, ['ETag' => '"' . \md5($body) . '"']);
    }

    private function complete(string $key, string $upload, ClientRequest $request): ClientResponse
    {
        $parts = $this->uploads[$upload] ?? null;

        if ($parts === null) {
            return self::error(404, 'NoSuchUpload');
        }

        \preg_match_all('#<PartNumber>(\d+)</PartNumber><ETag>([^<]+)</ETag>#', $request->body, $listed, \PREG_SET_ORDER);
        $body = '';

        foreach ($listed as [, $number, $etag]) {
            $bytes = $parts[(int) $number] ?? null;

            if ($bytes === null || \html_entity_decode($etag) !== '"' . \md5($bytes) . '"') {
                return self::error(400, 'InvalidPart');
            }

            $body .= $bytes;
        }

        unset($this->uploads[$upload]);
        $this->store($key, $body, 'application/octet-stream');

        return new ClientResponse(200, [], '<?xml version="1.0"?><CompleteMultipartUploadResult><Key>' . \htmlspecialchars($key, \ENT_XML1) . '</Key></CompleteMultipartUploadResult>');
    }

    private function abort(string $upload): ClientResponse
    {
        unset($this->uploads[$upload]);

        return new ClientResponse(204);
    }

    /** @param array<array-key, mixed> $query */
    private static function param(array $query, string $name): string
    {
        $value = $query[$name] ?? '';

        return \is_string($value) ? $value : '';
    }

    private static function error(int $status, string $code): ClientResponse
    {
        return new ClientResponse($status, ['Content-Type' => 'application/xml'], '<?xml version="1.0"?><Error><Code>' . $code . '</Code></Error>');
    }
}
