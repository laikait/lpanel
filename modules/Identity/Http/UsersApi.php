<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Identity;
use App\Engine\Auth\Password;
use App\Engine\Error\ErrorDocument;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Users\Roles;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserStore;

/**
 * GET /api/v1/users (users.read), POST /api/v1/users (users.manage).
 *
 * Only root may create an administrator: an admin making another admin is how
 * one stolen admin session becomes two that survive a password change.
 */
final class UsersApi
{
    public function __construct(
        private readonly UserStore $users,
        private readonly Password $passwords,
        private readonly AuditLog $audit,
    ) {}

    public static function input(): Schema
    {
        return Schema::of(
            'identity.user.input',
            Field::string('login')->pattern('/^[a-z][a-z0-9._-]{2,63}$/', 'a lower-case login of 3-64 letters, digits, ".", "_" or "-"'),
            Field::string('name')->length(1, 190),
            Field::string('email')->optional()->nullable()->check(
                'an email address',
                static fn(mixed $v): bool => $v === null || (\is_string($v) && \filter_var($v, \FILTER_VALIDATE_EMAIL) !== false && \strlen($v) <= 190),
            ),
            Field::string('password')->length(12, 1024),
            Field::listOf('roles', Field::string('role')->oneOf(Roles::ASSIGNABLE))->size(1, 2),
        );
    }

    public function index(): JsonResponse
    {
        return ApiResponse::collection(\array_map(static fn(User $u): array => $u->toArray(), $this->users->all()));
    }

    public function store(Request $request, Identity $actor): Response
    {
        $request->requirePayload();
        $payload = $request->json();
        $schema = self::input();
        $result = $schema->validate(\is_array($payload) ? $payload : []);

        if (!$result->isValid()) {
            return ErrorDocument::validation(fields: $result->messages(), message: 'The user could not be created.', expected: $schema->describe()['fields'])->toResponse();
        }

        /** @var array{login: string, name: string, email?: ?string, password: string, roles: list<string>} $input */
        $input = $schema->deserialize(\is_array($payload) ? $payload : []);

        if (\in_array(Roles::ADMIN, $input['roles'], true) && !$actor->hasRole(Roles::ROOT)) {
            $this->audit->record('user.create', 'denied', $actor, 'user', null, ['login' => $input['login'], 'reason' => 'only root creates administrators']);

            throw HttpException::forbidden('Only root can create administrators.');
        }

        if ($input['login'] === UserStore::ROOT_LOGIN || $this->users->byLogin($input['login']) !== null) {
            throw new HttpException(409, 'That login is already taken.');
        }

        $user = $this->users->create(
            $input['login'],
            $input['name'],
            $input['email'] ?? null,
            $this->passwords->hash(new Secret($input['password'])),
            $input['roles'],
        );
        $this->audit->record('user.create', 'succeeded', $actor, 'user', $user->uid, ['login' => $user->login, 'roles' => $user->effectiveRoles()]);

        return ApiResponse::created($user->toArray());
    }
}
