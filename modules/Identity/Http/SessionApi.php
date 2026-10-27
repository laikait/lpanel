<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\JsonResponse;
use App\Modules\Identity\Users\UserStore;

/**
 * GET /api/v1/session: who the browser is signed in as, for the panel's own
 * scripts. GET /api/token/v1/session: who a bearer token acts as -- "via" says
 * which, so a script can check its token before doing anything with it.
 */
final class SessionApi
{
    public function __construct(private readonly UserStore $users, private readonly Authorizer $authorizer) {}

    public function __invoke(Identity $identity): JsonResponse
    {
        $user = $identity->isGuest() ? null : $this->users->byUid($identity->id);

        if ($user === null) {
            return ApiResponse::item(['authenticated' => false]);
        }

        return ApiResponse::item([
            'authenticated' => true,
            'via' => \is_string($identity->attribute('token')) ? 'api_token' : 'session',
            'user' => $user->toArray(),
            'capabilities' => \array_values($this->authorizer->grantsFor($identity)),
        ]);
    }
}
