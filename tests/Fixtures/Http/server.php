<?php

declare(strict_types=1);

/*
 * The other end of the HTTP client's integration tests, run with
 * `php -S 127.0.0.1:<port> tests/Fixtures/Http/server.php`.
 *
 *   /echo            the request back as JSON: method, headers, body
 *   /status/<code>   that status, with a short body
 *   /redirect/<code> that redirect status, to /echo
 *   /loop            redirects to itself, for ever
 *   /slow            answers after two seconds
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/echo') {
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }

    header('Content-Type: application/json');
    header('X-Fixture: yes');
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $_GET,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]);

    return true;
}

if (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    echo 'status ' . $match[1];

    return true;
}

if (preg_match('#^/redirect/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    header('Location: /echo');

    return true;
}

if ($path === '/loop') {
    http_response_code(302);
    header('Location: /loop');

    return true;
}

if ($path === '/slow') {
    sleep(2);
    echo 'late';

    return true;
}

http_response_code(404);
echo 'no such fixture';

return true;
