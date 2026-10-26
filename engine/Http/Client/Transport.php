<?php

declare(strict_types=1);

namespace App\Engine\Http\Client;

/**
 * Sends one request and returns what came back, whatever its status.
 *
 * A transport never follows redirects and never retries: Client does both, the
 * same way over every transport, and checks each redirect's destination when
 * the request is publicOnly().
 */
interface Transport
{
    /**
     * @throws HttpClientException when there is no answer at all: no connection, a timeout
     */
    public function send(ClientRequest $request): ClientResponse;
}
