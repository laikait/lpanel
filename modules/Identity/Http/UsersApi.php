<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Error\ErrorDocument;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Schema\Schema;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserAdmin;
use App\Modules\Identity\Users\UserAdminException;
use App\Modules\Identity\Users\UserStore;

/**
 * GET /users (users.read), GET /users/{id} (users.read), POST /users
 * (users.manage) -- under /api/v1 for the panel's pages and /api/token/v1
 * for a bearer token. The rules are UserAdmin's, the same as the screens'.
 */
final class UsersApi
{
    public function __construct(
        private readonly UserStore $users,
        private readonly UserAdmin $admin,
    ) {}

    public static function input(): Schema
    {
        return UserAdmin::createSchema();
    }

    public function index(): JsonResponse
    {
        return ApiResponse::collection(\array_map(static fn(User $u): array => $u->toArray(), $this->users->all()));
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->users->byUid($id) ?? throw HttpException::notFound();

        return ApiResponse::item($user->toArray());
    }

    public function store(Request $request, Identity $actor): Response
    {
        $request->requirePayload();
        $payload = $request->json();
        $payload = \is_array($payload) ? $payload : [];
        $schema = UserAdmin::createSchema();
        $result = $schema->validate($payload);

        if (!$result->isValid()) {
            return ErrorDocument::validation(fields: $result->messages(), message: 'The user could not be created.', expected: $schema->describe()['fields'])->toResponse();
        }

        /** @var array{login: string, name: string, email?: ?string, password: string, roles: list<string>} $input */
        $input = $schema->deserialize($payload);

        try {
            $user = $this->admin->create($actor, $input);
        } catch (UserAdminException $e) {
            throw new HttpException($e->status, $e->getMessage());
        }

        return ApiResponse::created($user->toArray());
    }
}
