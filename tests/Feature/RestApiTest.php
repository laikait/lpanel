<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Tests\Support\TestCase;

/**
 * The REST surface through the real application.
 *
 * The claim the phase is built on is that there is nothing here to test that is
 * not also the web stack: the same kernel, the same router, the same dispatcher
 * and the same handlers. What these assert is the conventions layered on top --
 * one error shape, real negotiation, correct statuses, and versioning that is a
 * route prefix rather than a subsystem.
 */
final class RestApiTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function app(array $config = []): Application
    {
        return $this->application($config)->boot();
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = []): Response
    {
        return $this->app()->handle(Request::create('GET', $uri, ['headers' => $headers]));
    }

    /** @param array<string, string> $headers */
    private function post(string $uri, string $body, array $headers = []): Response
    {
        return $this->app()->handle(Request::create('POST', $uri, [
            'headers' => $headers + ['Accept' => 'application/json'],
            'body' => $body,
        ]));
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($response->body(), true, 32, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ---- one kernel -------------------------------------------------------

    /**
     * One route, two representations, and the only thing that chose between
     * them was a header. There is no API kernel and no web kernel.
     */
    public function test_the_same_route_answers_html_or_json(): void
    {
        $page = $this->get('/customers');
        $data = $this->get('/customers', ['Accept' => 'application/json']);

        self::assertSame('text/html; charset=UTF-8', $page->header('Content-Type'));
        self::assertStringContainsString('<title>Customers</title>', $page->body());

        self::assertSame('application/json; charset=UTF-8', $data->header('Content-Type'));
        self::assertArrayHasKey('data', $this->decode($data));
    }

    /** The case a substring test on Accept gets wrong. */
    public function test_quality_values_are_honoured(): void
    {
        $response = $this->get('/customers', ['Accept' => 'application/json;q=0.9, text/html;q=0.8']);

        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
    }

    public function test_a_browser_still_gets_the_page(): void
    {
        $response = $this->get('/customers', [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));
    }

    /**
     * 406 rather than serving JSON anyway. A client that asked for something
     * this endpoint cannot produce has a bug, and answering with a body it
     * cannot parse turns that bug into a mystery.
     */
    public function test_an_unproducible_type_is_refused_with_the_alternatives(): void
    {
        $response = $this->get('/customers', ['Accept' => 'application/xml']);

        self::assertSame(406, $response->status());

        $json = $this->get('/customers', ['Accept' => 'application/xml, application/json;q=0.1']);
        self::assertSame(200, $json->status(), 'a fallback the endpoint can produce is still used');
    }

    /** The explicit path, for clients that cannot set Accept. */
    public function test_the_json_route_needs_no_header(): void
    {
        $response = $this->get('/customers.json');

        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
    }

    // ---- the envelope -----------------------------------------------------

    public function test_a_list_carries_its_data_meta_and_links(): void
    {
        $payload = $this->decode($this->get('/customers.json?page=2&per_page=2'));

        self::assertIsArray($payload['data']);
        self::assertCount(1, $payload['data']);

        /** @var array<string, mixed> $meta */
        $meta = $payload['meta'];

        self::assertSame(3, $meta['total']);
        self::assertSame(2, $meta['page']);
        self::assertSame(2, $meta['pages']);

        /** @var array<string, string|null> $links */
        $links = $meta['links'];

        self::assertSame('/customers.json?page=2', $links['self']);
        self::assertSame('/customers.json?page=1', $links['prev']);
        self::assertNull($links['next'], 'the last page has nowhere to go');
    }

    public function test_a_single_resource_is_wrapped_in_data(): void
    {
        $payload = $this->decode($this->get('/api/v1/customers/1'));

        /** @var array<string, mixed> $customer */
        $customer = $payload['data'];

        self::assertSame('Ada Lovelace', $customer['name']);
        self::assertSame('ada', $customer['owner']);
    }

    /**
     * 201 with a Location built from the route's own name, so the URL cannot
     * drift from the route.
     */
    public function test_creating_a_resource_answers_201_with_its_location(): void
    {
        $response = $this->post(
            '/api/v1/customers',
            (string) \json_encode(['name' => 'Barbara Liskov', 'email' => 'liskov@example.test']),
            ['Content-Type' => 'application/json'],
        );

        self::assertSame(201, $response->status());
        self::assertSame('/api/v1/customers/4', $response->header('Location'));

        /** @var array<string, mixed> $created */
        $created = $this->decode($response)['data'];
        self::assertSame(4, $created['id']);
    }

    // ---- one error shape --------------------------------------------------

    /**
     * The point of ErrorDocument. Before it, a validation failure and an
     * uncaught exception were built by two different pieces of code that agreed
     * on a shape by coincidence.
     */
    public function test_every_error_has_the_same_three_keys(): void
    {
        $responses = [
            'validation' => $this->post('/api/v1/customers', '{}', ['Content-Type' => 'application/json']),
            'wrong media type' => $this->post('/api/v1/customers', 'name=Ada', ['Content-Type' => 'text/plain']),
            'not found' => $this->get('/api/v1/customers/999', ['Accept' => 'application/json']),
            'no route' => $this->get('/nope', ['Accept' => 'application/json']),
            'not acceptable' => $this->get('/customers', ['Accept' => 'application/xml']),
        ];

        foreach ($responses as $label => $response) {
            $payload = $this->decode($response);

            self::assertArrayHasKey('error', $payload, $label);

            /** @var array<string, mixed> $error */
            $error = $payload['error'];

            self::assertSame(
                ['status', 'title', 'message'],
                \array_slice(\array_keys($error), 0, 3),
                $label . ' does not lead with the three fixed keys',
            );
            self::assertSame($response->status(), $error['status'], $label);
            self::assertIsString($error['title']);
            self::assertIsString($error['message']);
        }
    }

    public function test_a_validation_failure_reports_every_field_and_the_contract(): void
    {
        $response = $this->post('/api/v1/customers', '{}', ['Content-Type' => 'application/json']);

        self::assertSame(400, $response->status());

        /** @var array<string, mixed> $error */
        $error = $this->decode($response)['error'];

        self::assertSame(
            ['name' => ['is required'], 'email' => ['is required']],
            $error['fields'],
        );
        self::assertArrayHasKey('expected', $error, 'the contract travels with the failure');
    }

    /**
     * 415 and 400 are different failures. Reporting the first as the second
     * sends whoever is debugging to look at their fields instead of at their
     * Content-Type header.
     */
    public function test_a_body_in_the_wrong_format_is_415_not_400(): void
    {
        $response = $this->post('/api/v1/customers', '{"name":"Ada"}', ['Content-Type' => 'text/plain']);

        self::assertSame(415, $response->status());
        self::assertStringContainsString('text/plain', $this->decode($response)['error']['message']);
    }

    public function test_a_vendor_json_content_type_is_accepted(): void
    {
        $response = $this->post(
            '/api/v1/customers',
            (string) \json_encode(['name' => 'Grace Murray', 'email' => 'murray@example.test']),
            ['Content-Type' => 'application/vnd.example.v1+json'],
        );

        self::assertSame(201, $response->status());
    }

    public function test_405_still_advertises_what_is_allowed(): void
    {
        $response = $this->app()->handle(
            Request::create('DELETE', '/api/v1/customers', ['headers' => ['Accept' => 'application/json']]),
        );

        self::assertSame(405, $response->status());
        // HEAD is in the list because the router answers it from the GET
        // route, and a client that can HEAD this path deserves to be told.
        self::assertSame('GET, HEAD, POST', $response->header('Allow'));
        self::assertSame(405, $this->decode($response)['error']['status']);
    }

    /**
     * An error must never carry a file path or a class name into production.
     *
     * Debug is on for the whole suite -- phpunit.xml sets APP_DEBUG -- so this
     * is one of the few tests that has to turn it off to mean anything.
     */
    public function test_an_error_leaks_nothing_outside_debug_mode(): void
    {
        $body = $this->app(['app' => ['debug' => false]])
            ->handle(Request::create('GET', '/api/v1/customers/999', [
                'headers' => ['Accept' => 'application/json'],
            ]))
            ->body();

        self::assertStringNotContainsString('xampp', $body);
        self::assertStringNotContainsString('Engine', $body);
        self::assertStringNotContainsString('#0', $body);
    }

    // ---- versioning -------------------------------------------------------

    /**
     * Versioning is a route prefix plus route metadata, and the metadata is
     * read by one filter in the shared module. There is no version negotiator
     * and no version resolver, because a prefix already answers the question
     * and a second mechanism could only disagree with it.
     */
    public function test_a_versioned_route_says_which_version_answered(): void
    {
        self::assertSame('v1', $this->get('/api/v1/customers/1')->header('X-Api-Version'));
        self::assertSame('v0', $this->get('/api/v0/customers')->header('X-Api-Version'));
    }

    public function test_an_unversioned_route_is_not_stamped(): void
    {
        self::assertNull($this->get('/customers.json')->header('X-Api-Version'));
    }

    /**
     * A deprecation announced in the response reaches exactly the people who
     * need it: whoever is still calling the endpoint.
     */
    public function test_a_deprecated_version_announces_itself(): void
    {
        $response = $this->get('/api/v0/customers');

        self::assertSame(200, $response->status(), 'deprecated is not gone');
        self::assertSame('Thu, 01 Jan 2026 00:00:00 GMT', $response->header('Deprecation'));
        self::assertSame('Fri, 01 Jan 2027 00:00:00 GMT', $response->header('Sunset'));
    }

    public function test_a_current_version_announces_no_deprecation(): void
    {
        $response = $this->get('/api/v1/customers/1');

        self::assertNull($response->header('Deprecation'));
        self::assertNull($response->header('Sunset'));
    }

    /**
     * Both versions are the same handler. The prefix is the version; nothing
     * about the code is duplicated to support one.
     */
    public function test_two_versions_answer_with_the_same_data(): void
    {
        $v0 = $this->decode($this->get('/api/v0/customers'))['data'];
        $latest = $this->decode($this->get('/customers.json'))['data'];

        self::assertSame($latest, $v0);
    }

    // ---- the response seam ------------------------------------------------

    /**
     * dispatch.response is the only point where the finished Response and its
     * Route both exist, which is what lets route metadata drive response
     * behaviour without a middleware pipeline.
     */
    public function test_the_dispatch_response_filter_sees_the_route(): void
    {
        $app = $this->app();
        $seen = [];

        add_filter(
            'dispatch.response',
            static function (Response $response, \App\Engine\Routing\Route $route) use (&$seen): Response {
                $seen[] = $route->routeName();

                return $response->withHeader('X-Seen', 'yes');
            },
        );

        $response = $app->handle(Request::create('GET', '/api/v1/customers/1'));

        self::assertSame(['api.v1.customers.show'], $seen);
        self::assertSame('yes', $response->header('X-Seen'));
    }

    /** It runs on dispatch, so an error response never reaches it. */
    public function test_the_dispatch_response_filter_does_not_see_errors(): void
    {
        $app = $this->app();
        $seen = 0;

        add_filter('dispatch.response', static function (Response $response) use (&$seen): Response {
            ++$seen;

            return $response;
        });

        $app->handle(Request::create('GET', '/nope'));

        self::assertSame(0, $seen);
    }
}
