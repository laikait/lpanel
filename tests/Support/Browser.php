<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Csrf;

/**
 * A browser over one booted application: it keeps the cookies every response
 * sets (the session and CSRF ones rotate at login), and sends the CSRF token
 * back the way a page does.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly Application $app, private readonly string $ip = '203.0.113.10') {}

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->send('GET', $uri, ['headers' => $headers]);
    }

    /**
     * A form post, with the _token field filled in unless the test says otherwise.
     *
     * @param array<string, mixed> $fields
     */
    public function post(string $uri, array $fields = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $fields['_token'] = $this->token();
        }

        return $this->send('POST', $uri, ['body' => $fields]);
    }

    /**
     * A JSON post, as the panel's own scripts send it: X-CSRF-TOKEN from the cookie.
     *
     * @param array<string, mixed> $json
     */
    public function postJson(string $uri, array $json): Response
    {
        return $this->send('POST', $uri, [
            'body' => \json_encode($json, \JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-TOKEN' => $this->token()],
        ]);
    }

    /**
     * A JSON request with any method (DELETE, say), sent as the panel's scripts send it.
     *
     * @param array<string, mixed> $json
     */
    public function sendJson(string $method, string $uri, array $json = []): Response
    {
        return $this->send($method, $uri, [
            'body' => \json_encode($json, \JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-TOKEN' => $this->token()],
        ]);
    }

    /**
     * A script's request: a bearer token, and this browser's cookies too unless told not to,
     * to prove a route answers to the token alone.
     *
     * @param ?array<string, mixed> $json
     */
    public function bearer(string $method, string $uri, string $token, ?array $json = null, bool $withCookies = false): Response
    {
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token];
        $options = ['headers' => $headers];

        if ($json !== null) {
            $options['body'] = \json_encode($json, \JSON_THROW_ON_ERROR);
            $options['headers']['Content-Type'] = 'application/json';
        }

        $saved = $this->cookies;

        if (!$withCookies) {
            $this->cookies = [];
        }

        try {
            return $this->send($method, $uri, $options);
        } finally {
            $this->cookies = $saved;
        }
    }

    /** @return array<string, mixed> */
    public function json(string $uri): array
    {
        $decoded = \json_decode($this->get($uri, ['Accept' => 'application/json'])->body(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The "data" member of an ApiResponse.
     *
     * @return array<string, mixed>
     */
    public function data(string $uri): array
    {
        $data = $this->json($uri)['data'] ?? null;

        return \is_array($data) ? $data : [];
    }

    public function token(): string
    {
        if (!isset($this->cookies[Csrf::COOKIE])) {
            $this->get('/login');
        }

        return $this->cookies[Csrf::COOKIE] ?? throw new \LogicException('The application issued no CSRF cookie.');
    }

    /** @param array{body?: array<string, mixed>|string, headers?: array<string, string>} $options */
    private function send(string $method, string $uri, array $options): Response
    {
        $response = $this->app->handle(Request::create($method, $uri, $options + [
            'cookies' => $this->cookies,
            'server' => ['REMOTE_ADDR' => $this->ip],
        ]));

        foreach ($response->cookies() as $cookie) {
            if ($cookie->value === '' || ($cookie->expires > 0 && $cookie->expires < \time())) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }

        return $response;
    }
}
