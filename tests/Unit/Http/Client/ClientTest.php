<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Client;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\ClientRequest;
use App\Engine\Http\Client\ClientResponse;
use App\Engine\Http\Client\CurlTransport;
use App\Engine\Http\Client\FakeTransport;
use App\Engine\Http\Client\HttpClientException;
use App\Tests\Support\TestCase;

final class ClientTest extends TestCase
{
    private FakeTransport $fake;

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
    }

    /** @param array<string, list<string>> $dns */
    private function client(array $dns = []): Client
    {
        return new Client(
            $this->fake,
            sleep: function (int $ms): void {
                $this->slept[] = $ms;
            },
            resolve: static fn(string $host): array => $dns[$host] ?? ['93.184.216.34'],
        );
    }

    private function sent(int $index = 0): ClientRequest
    {
        return $this->fake->sent()[$index];
    }

    // ---- building ----------------------------------------------------------

    public function test_an_array_body_is_sent_as_json(): void
    {
        $this->fake->push(201, ['id' => 7]);

        $response = $this->client()->post('https://api.example.com/charges', ['amount' => 500, 'note' => 'café/x']);

        self::assertSame(201, $response->status());
        self::assertSame(7, $response->json('id'));
        self::assertSame('POST', $this->sent()->method);
        self::assertSame('{"amount":500,"note":"café/x"}', $this->sent()->body);
        self::assertSame('application/json', $this->sent()->header('content-type'));
        self::assertSame((string) \strlen($this->sent()->body), $this->sent()->header('Content-Length'), 'bytes, not characters');
    }

    public function test_a_form_body_when_asked(): void
    {
        $this->fake->push();

        $this->client()->asForm()->post('https://example.com/login', ['user' => 'ana', 'tags' => ['a', 'b']]);

        self::assertSame('user=ana&tags%5B0%5D=a&tags%5B1%5D=b', $this->sent()->body);
        self::assertSame('application/x-www-form-urlencoded', $this->sent()->header('Content-Type'));
    }

    public function test_query_base_url_headers_and_token(): void
    {
        $this->fake->push();

        $this->client()
            ->baseUrl('https://api.example.com/v2/')
            ->withHeaders(['X-Trace' => 'abc'])
            ->withToken('secret')
            ->accept('text/csv')
            ->timeout(5)
            ->get('/reports', ['from' => '2026-01-01', 'q' => 'a b']);

        self::assertSame('https://api.example.com/v2/reports?from=2026-01-01&q=a%20b', $this->sent()->url);
        self::assertSame('Bearer secret', $this->sent()->header('authorization'));
        self::assertSame('abc', $this->sent()->header('X-Trace'));
        self::assertSame('text/csv', $this->sent()->header('Accept'));
        self::assertSame(5.0, $this->sent()->timeout);
    }

    /** Each with-method returns a new client: one caller's token never reaches another's request. */
    public function test_the_client_is_immutable(): void
    {
        $this->fake->push()->push();
        $base = $this->client();

        $base->withToken('mine')->get('https://example.com/a');
        $base->get('https://example.com/b');

        self::assertNull($this->sent(1)->header('Authorization'));
    }

    public function test_a_header_with_a_line_break_is_refused(): void
    {
        $this->expectException(HttpClientException::class);
        $this->client()->withHeaders(['X-Name' => "ana\r\nX-Admin: yes"]);
    }

    public function test_only_http_and_https(): void
    {
        $this->expectException(HttpClientException::class);
        $this->client()->get('file:///etc/passwd');
    }

    // ---- responses ---------------------------------------------------------

    public function test_the_response(): void
    {
        $this->fake->push(200, ['data' => ['items' => [['id' => 1]]]], ['ETag' => '"v1"']);

        $response = $this->client()->get('https://example.com/');

        self::assertTrue($response->successful());
        self::assertSame('"v1"', $response->header('etag'));
        self::assertSame(1, $response->json('data.items.0.id'));
        self::assertNull($response->json('data.missing'));
        self::assertSame($response, $response->throw());
    }

    public function test_throw_on_an_error_status_carries_the_response_and_hides_the_query(): void
    {
        $this->fake->push(422, ['error' => 'amount']);

        try {
            $this->client()->get('https://example.com/charges?token=secret')->throw();
            self::fail('a 422 did not throw');
        } catch (HttpClientException $e) {
            self::assertSame(422, $e->response()?->status());
            self::assertStringContainsString('https://example.com/charges', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    // ---- redirects ---------------------------------------------------------

    public function test_a_303_becomes_a_get_and_a_307_keeps_the_method(): void
    {
        $this->fake->push(303, '', ['Location' => '/done'])->push(200)
            ->push(307, '', ['Location' => 'https://example.com/again'])->push(200);

        $this->client()->post('https://example.com/form', ['a' => 1]);
        $this->client()->post('https://example.com/form', ['a' => 1]);

        self::assertSame('GET', $this->sent(1)->method);
        self::assertSame('https://example.com/done', $this->sent(1)->url);
        self::assertSame('', $this->sent(1)->body);
        self::assertSame('POST', $this->sent(3)->method);
        self::assertSame('{"a":1}', $this->sent(3)->body);
    }

    public function test_credentials_do_not_follow_a_redirect_to_another_host(): void
    {
        $this->fake->push(302, '', ['Location' => 'https://cdn.example.net/file'])->push(200);

        $this->client()->withToken('secret')->get('https://api.example.com/file');

        self::assertNull($this->sent(1)->header('Authorization'));
    }

    public function test_redirects_are_limited(): void
    {
        for ($i = 0; $i <= Client::MAX_REDIRECTS; ++$i) {
            $this->fake->push(302, '', ['Location' => '/loop']);
        }

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('redirected more than 5 times');
        $this->client()->get('https://example.com/loop');
    }

    // ---- retries -----------------------------------------------------------

    public function test_a_server_error_is_retried_with_a_doubling_wait(): void
    {
        $this->fake->push(503)->push(502)->push(200, ['ok' => true]);

        $response = $this->client()->retry(2, 100)->get('https://example.com/');

        self::assertTrue($response->successful());
        self::assertCount(3, $this->fake->sent());
        self::assertSame([100, 200], $this->slept);
    }

    public function test_retry_after_is_honoured(): void
    {
        $this->fake->push(429, '', ['Retry-After' => '3'])->push(200);

        $this->client()->retry(1)->get('https://example.com/');

        self::assertSame([3000], $this->slept);
    }

    public function test_no_connection_is_retried_and_then_thrown(): void
    {
        $this->fake->pushAnswer(HttpClientException::connection('GET', 'https://example.com/', 'refused'))
            ->pushAnswer(HttpClientException::connection('GET', 'https://example.com/', 'refused'));

        $this->expectException(HttpClientException::class);
        $this->client()->retry(1)->get('https://example.com/');
    }

    /** A POST that timed out may have charged the card: it is not sent again unless the caller says so. */
    public function test_a_post_is_not_retried_unless_asked(): void
    {
        $this->fake->push(503)->push(200);

        self::assertSame(503, $this->client()->retry(1)->post('https://example.com/charge')->status());
        self::assertCount(1, $this->fake->sent());

        self::assertSame(200, $this->client()->retry(1, always: true)->post('https://example.com/charge')->status());
    }

    public function test_a_client_error_is_not_retried(): void
    {
        $this->fake->push(404);

        self::assertSame(404, $this->client()->retry(3)->get('https://example.com/')->status());
        self::assertCount(1, $this->fake->sent());
    }

    // ---- publicOnly --------------------------------------------------------

    public function test_public_only_refuses_a_private_address(): void
    {
        $client = $this->client(['internal.example.com' => ['10.0.0.5']])->publicOnly();

        try {
            $client->get('https://internal.example.com/');
            self::fail('a private address was requested');
        } catch (HttpClientException $e) {
            self::assertStringContainsString('10.0.0.5', $e->getMessage());
        }

        self::assertSame([], $this->fake->sent(), 'nothing was sent');
    }

    /** Any one bad address is enough: DNS can answer differently at connect time. */
    public function test_public_only_refuses_a_host_with_any_private_address(): void
    {
        $this->expectException(HttpClientException::class);
        $this->client(['mixed.example.com' => ['93.184.216.34', '::1']])->publicOnly()->get('https://mixed.example.com/');
    }

    public function test_public_only_checks_every_redirect(): void
    {
        $this->fake->push(302, '', ['Location' => 'http://169.254.169.254/latest/meta-data/']);

        $this->expectException(HttpClientException::class);
        $this->client(['169.254.169.254' => ['169.254.169.254']])->publicOnly()->get('https://example.com/');
    }

    public function test_public_only_allows_a_public_host(): void
    {
        $this->fake->push(200);

        self::assertSame(200, $this->client()->publicOnly()->get('https://example.com/')->status());
    }

    /**
     * DNS rebinding: the host answers with a public address for the check and a
     * private one when the transport looks it up again. So the transport is
     * handed the address that was checked, and never looks it up at all.
     */
    public function test_public_only_pins_the_address_it_checked(): void
    {
        $this->fake->push(302, '', ['Location' => 'https://other.example.com/next']);
        $this->fake->push(200);

        $this->client(['example.com' => ['93.184.216.34'], 'other.example.com' => ['93.184.216.99']])
            ->publicOnly()
            ->get('https://example.com/');

        self::assertSame('93.184.216.34', $this->sent(0)->address);
        self::assertSame('93.184.216.99', $this->sent(1)->address, 'a redirect is checked and pinned afresh');
    }

    public function test_without_public_only_nothing_is_pinned(): void
    {
        $this->fake->push(200);
        $this->client()->get('https://example.com/');

        self::assertNull($this->sent()->address);
    }

    /** Where parse_url() and curl disagree about the host, the URL is refused rather than guessed at. */
    public function test_public_only_refuses_urls_whose_host_is_ambiguous(): void
    {
        foreach (['https://example.com\\@127.0.0.1/', 'https://user@example.com/', 'https://a@b@example.com/'] as $url) {
            try {
                $this->client()->publicOnly()->get($url);
                self::fail($url . ' was sent');
            } catch (HttpClientException) {
                // refused, as it should be
            }
        }

        self::assertSame([], $this->fake->sent());
    }

    public function test_curl_connects_to_the_pinned_address(): void
    {
        if (!CurlTransport::available()) {
            self::markTestSkipped('curl is not installed');
        }

        // A host that cannot resolve, pinned to a local port nothing listens
        // on: if the pin were ignored this would be a DNS failure instead.
        $request = new ClientRequest('GET', 'http://pinned.invalid:9/', [], '', 2.0, '127.0.0.1');

        try {
            (new CurlTransport())->send($request);
            self::fail('something answered on port 9');
        } catch (HttpClientException $e) {
            self::assertStringNotContainsStringIgnoringCase('resolve', $e->getMessage());
        }
    }

    public function test_an_unanswered_fake_says_so(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('FakeTransport has no response');
        $this->client()->get('https://example.com/');
    }

    public function test_header_lines_after_a_redirect_describe_the_last_response(): void
    {
        $response = ClientResponse::fromHeaderLines(
            ['HTTP/1.1 301 Moved', 'Location: /x', 'HTTP/1.1 200 OK', 'Content-Type: text/plain', 'Set-Cookie: a=1', 'Set-Cookie: b=2'],
            'body',
            'GET',
            'https://example.com/',
        );

        self::assertSame(200, $response->status());
        self::assertNull($response->header('Location'));
        self::assertSame(['a=1', 'b=2'], $response->headers()['set-cookie']);
    }
}
