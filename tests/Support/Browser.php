<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;

/**
 * A browser for feature tests: keeps cookies between requests and sends the
 * CSRF token with every form, the way a real browser on a real page does.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    public ?Response $last = null;

    public function __construct(private readonly Application $app) {}

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): Response
    {
        return $this->send(Request::create('GET', $path, ['cookies' => $this->cookies, 'headers' => $headers]));
    }

    /** @param array<string, string|list<string>> $form */
    public function post(string $path, array $form = []): Response
    {
        $token = $this->cookies['XSRF-TOKEN'] ?? null;

        if ($token === null) {
            // A real browser has visited a page first; that is what sets the token.
            $this->get('/health');
            $token = $this->cookies['XSRF-TOKEN'] ?? '';
        }

        return $this->send(Request::create('POST', $path, [
            'cookies' => $this->cookies,
            'body' => $form + ['_token' => $token],
        ]));
    }

    /** Sign in through the real form; returns the final response's status path. */
    public function signIn(string $area, string $username, string $password): Response
    {
        $prefix = $area === 'admin' ? '/admin' : '/panel';
        $this->get($prefix . '/login');

        return $this->post($prefix . '/login', ['username' => $username, 'password' => $password]);
    }

    public function app(): Application
    {
        return $this->app;
    }

    private function send(Request $request): Response
    {
        $response = $this->app->handle($request);

        foreach ($response->cookies() as $cookie) {
            if ($cookie->value === '' || ($cookie->expires !== 0 && $cookie->expires < \time())) {
                unset($this->cookies[$cookie->name]);
            } else {
                $this->cookies[$cookie->name] = $cookie->value;
            }
        }

        return $this->last = $response;
    }
}
