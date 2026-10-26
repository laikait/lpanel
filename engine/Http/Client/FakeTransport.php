<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * A transport for tests: answers from a queue, and remembers what was sent.
 *
 *     $fake = (new FakeTransport())->push(200, ['id' => 7])->push(503);
 *     $client = new Client($fake);
 *     ...
 *     $fake->sent()[0]->url;
 *
 * A response may be a ClientResponse, or a status with a body (an array is
 * sent as JSON) and headers; or a closure receiving the ClientRequest, for an
 * answer that depends on it. A HttpClientException may be queued too, to test
 * what happens when there is no answer at all.
 */
final class FakeTransport implements Transport
{
    /** @var list<ClientResponse|HttpClientException|\Closure(ClientRequest): ClientResponse> */
    private array $queue = [];

    /** @var list<ClientRequest> */
    private array $sent = [];

    /**
     * @param array<mixed>|string                 $body
     * @param array<string, string|list<string>>  $headers
     */
    public function push(int $status = 200, array|string $body = '', array $headers = []): self
    {
        if (\is_array($body)) {
            $headers += ['Content-Type' => 'application/json'];
            $body = \json_encode($body, \JSON_THROW_ON_ERROR);
        }

        $this->queue[] = new ClientResponse($status, $headers, $body);

        return $this;
    }

    /** @param ClientResponse|HttpClientException|\Closure(ClientRequest): ClientResponse $answer */
    public function pushAnswer(ClientResponse|HttpClientException|\Closure $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    public function send(ClientRequest $request): ClientResponse
    {
        $this->sent[] = $request;
        $answer = \array_shift($this->queue) ?? throw HttpClientException::noResponseQueued($request->method, $request->url);

        if ($answer instanceof HttpClientException) {
            throw $answer;
        }

        $response = $answer instanceof \Closure ? $answer($request) : $answer;

        // Bound to the request it answers, so throw() names the right URL.
        return new ClientResponse($response->status(), $response->headers(), $response->body(), $request->method, $request->url);
    }

    /** @return list<ClientRequest> */
    public function sent(): array
    {
        return $this->sent;
    }
}
