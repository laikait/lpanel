<?php

declare(strict_types=1);

// `php -S 127.0.0.1:PORT tests/Fixtures/Storage/s3-router.php` with
// LPHP_FAKE_S3_STATE naming a file: FakeS3 behind a real socket, its objects
// kept in that file between requests.

use App\Engine\Http\Client\ClientRequest;
use App\Tests\Fixtures\Storage\FakeS3;

require __DIR__ . '/../../../vendor/autoload.php';

$state = (string) getenv('LPHP_FAKE_S3_STATE');
$s3 = new FakeS3('bucket', 'AKID', 'secret', 'eu-central-1');

if (is_file($state)) {
    /** @var array{objects: array<string, array{body: string, type: string, modified: int}>, uploads: array<string, array<int, string>>} $saved */
    $saved = unserialize((string) file_get_contents($state));
    $s3->objects = $saved['objects'];
    $s3->uploads = $saved['uploads'];
}

$headers = [];

foreach ($_SERVER as $name => $value) {
    if (is_string($value) && str_starts_with((string) $name, 'HTTP_')) {
        $headers[str_replace('_', '-', substr((string) $name, 5))] = $value;
    }
}

foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $key => $name) {
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
        $headers[$name] = $_SERVER[$key];
    }
}

$request = new ClientRequest(
    (string) $_SERVER['REQUEST_METHOD'],
    'http://' . ($headers['HOST'] ?? '127.0.0.1') . (string) $_SERVER['REQUEST_URI'],
    $headers,
    (string) file_get_contents('php://input'),
);

$response = $s3->send($request);
file_put_contents($state, serialize(['objects' => $s3->objects, 'uploads' => $s3->uploads]));

http_response_code($response->status());

foreach ($response->headers() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}

echo $response->body();
