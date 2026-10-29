<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers;

use App\Engine\Http\Response;

/**
 * A shared spy the fixture modules write to, so tests can observe what ran and
 * in what order without any global state of their own.
 */
final class Recorder
{
    /** @var list<string> */
    public array $booted = [];

    /** @var list<string> */
    public array $events = [];

    public function record(string $what): void
    {
        $this->events[] = $what;
    }

    public function stamp(Response $response): Response
    {
        return $response->withHeader('X-Engine', 'shared');
    }
}
