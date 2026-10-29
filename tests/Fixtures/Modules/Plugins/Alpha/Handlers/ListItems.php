<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers;

use App\Engine\Http\JsonResponse;

final class ListItems
{
    public function __construct(private readonly Recorder $recorder) {}

    public function __invoke(): JsonResponse
    {
        $this->recorder->record('alpha.list');

        return new JsonResponse(['data' => ['one', 'two', 'three']]);
    }
}
