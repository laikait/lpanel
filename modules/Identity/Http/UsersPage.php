<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Schema\Schema;
use App\Engine\Security\Secret;
use App\Modules\Identity\Tokens\TokenStore;
use App\Modules\Identity\Users\Roles;
use App\Modules\Identity\Users\User;
use App\Modules\Identity\Users\UserAdmin;
use App\Modules\Identity\Users\UserAdminException;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Shared\Ui\Pages;

/**
 * /users: the people who use the panel. Reading needs users.read; every
 * change needs users.manage and goes through UserAdmin, whose rules (root's
 * row is the server's, only root changes administrators, nobody changes
 * their own roles here) are the API's too.
 */
final class UsersPage
{
    public function __construct(
        private readonly Pages $pages,
        private readonly UserStore $users,
        private readonly UserAdmin $admin,
        private readonly TokenStore $tokens,
        private readonly Authorizer $authorizer,
    ) {}

    public function index(Request $request, Identity $identity): Response
    {
        $search = $request->query('q');
        $search = \is_string($search) ? \mb_substr(\trim($search), 0, 100) : '';
        $role = $request->query('role');
        $role = \is_string($role) && \in_array($role, [Roles::ROOT, ...Roles::ASSIGNABLE], true) ? $role : '';
        $all = $this->users->all();

        $shown = \array_values(\array_filter($all, static function (User $u) use ($search, $role): bool {
            if ($role !== '' && !\in_array($role, $u->effectiveRoles(), true)) {
                return false;
            }

            return $search === ''
                || \str_contains($u->login, \mb_strtolower($search))
                || \str_contains(\mb_strtolower($u->name), \mb_strtolower($search))
                || ($u->email !== null && \str_contains(\mb_strtolower($u->email), \mb_strtolower($search)));
        }));

        return $this->pages->render($request, '@Identity/users/index', [
            'rows' => \array_map(fn(User $u): array => ['user' => $u, 'url' => $this->pages->url('users.show', ['id' => $u->uid])], $shown),
            'total' => \count($all),
            'counts' => $this->users->counts(),
            'search' => $search,
            'role' => $role,
            'can_manage' => $this->authorizer->allows($identity, 'users.manage'),
            'create_url' => $this->pages->url('users.create'),
            'index_url' => $this->pages->url('users.index'),
        ], identity: $identity, section: 'users');
    }

    public function create(Request $request, Identity $identity): Response
    {
        return $this->createForm($request, $identity, ['roles' => [Roles::CUSTOMER]], []);
    }

    public function store(Request $request, Identity $identity): Response
    {
        $input = [
            'login' => \mb_strtolower(\trim(self::text($request, 'login'))),
            'name' => \trim(self::text($request, 'name')),
            'email' => self::optional($request, 'email'),
            'password' => self::text($request, 'password'),
            'roles' => self::roles($request),
        ];
        $old = ['password' => ''] + $input;

        if ($input['password'] !== self::text($request, 'password_confirmation')) {
            return $this->createForm($request, $identity, $old, ['password_confirmation' => 'The two passwords are not the same.'], 422);
        }

        $errors = self::errors(UserAdmin::createSchema(), $input);

        if ($errors !== []) {
            return $this->createForm($request, $identity, $old, $errors, 422);
        }

        /** @var array{login: string, name: string, email?: ?string, password: string, roles: list<string>} $clean */
        $clean = UserAdmin::createSchema()->deserialize($input);

        try {
            $user = $this->admin->create($identity, $clean);
        } catch (UserAdminException $e) {
            return $this->createForm($request, $identity, $old, $e->status === 409 ? ['login' => $e->getMessage()] : ['roles' => $e->getMessage()], $e->status);
        }

        $this->pages->flash('success', \sprintf('%s can now sign in as %s.', $user->name, $user->login));

        return new RedirectResponse($this->pages->url('users.show', ['id' => $user->uid]), 303);
    }

    public function show(Request $request, Identity $identity, string $id): Response
    {
        return $this->detail($request, $identity, $this->target($id), [], []);
    }

    public function update(Request $request, Identity $identity, string $id): Response
    {
        $target = $this->target($id);
        $input = ['name' => \trim(self::text($request, 'name')), 'email' => self::optional($request, 'email'), 'roles' => self::roles($request)];
        $errors = self::errors(UserAdmin::updateSchema(), $input);

        if ($errors !== []) {
            return $this->detail($request, $identity, $target, $input, $errors, 422);
        }

        /** @var array{name: string, email?: ?string, roles: list<string>} $clean */
        $clean = UserAdmin::updateSchema()->deserialize($input);

        return $this->act($target, fn() => $this->admin->update($identity, $target, $clean), 'Changes saved.');
    }

    public function status(Request $request, Identity $identity, string $id): Response
    {
        $target = $this->target($id);
        $active = $request->input('status') === User::ACTIVE;

        return $this->act($target, fn() => $this->admin->setStatus($identity, $target, $active), $active
            ? \sprintf('%s can sign in again.', $target->name)
            : \sprintf('%s is suspended and was signed out.', $target->name));
    }

    public function password(Request $request, Identity $identity, string $id): Response
    {
        $target = $this->target($id);
        $password = self::text($request, 'password');

        if (\strlen($password) < 12 || \strlen($password) > 1024) {
            return $this->detail($request, $identity, $target, [], ['password' => 'Choose a password of at least 12 characters.'], 422);
        }

        if ($password !== self::text($request, 'password_confirmation')) {
            return $this->detail($request, $identity, $target, [], ['password' => 'The two passwords are not the same.'], 422);
        }

        return $this->act($target, fn() => $this->admin->setPassword($identity, $target, new Secret($password)), 'Password set. Their other sessions have ended.');
    }

    public function resetLink(Request $request, Identity $identity, string $id): Response
    {
        $target = $this->target($id);

        return $this->act($target, fn() => $this->admin->sendResetLink($identity, $target, $request->ip()), \sprintf('A reset link is on its way to %s.', (string) $target->email));
    }

    public function resetTwoFactor(Identity $identity, string $id): Response
    {
        $target = $this->target($id);

        return $this->act($target, fn() => $this->admin->resetTwoFactor($identity, $target), 'Two-factor sign-in is off for this user. They can set it up again.');
    }

    public function endSessions(Identity $identity, string $id): Response
    {
        $target = $this->target($id);

        return $this->act($target, function () use ($identity, $target): void {
            $this->admin->endAccess($identity, $target);
        }, 'Signed out everywhere, and their API tokens are revoked.');
    }

    /** Run a change, and come back to the user's page with what happened. */
    private function act(User $target, \Closure $change, string $done): Response
    {
        try {
            $change();
            $this->pages->flash('success', $done);
        } catch (UserAdminException $e) {
            $this->pages->flash('danger', $e->getMessage());
        }

        return new RedirectResponse($this->pages->url('users.show', ['id' => $target->uid]), 303);
    }

    private function target(string $id): User
    {
        return $this->users->byUid($id) ?? throw HttpException::notFound();
    }

    /**
     * @param array<string, mixed>  $old
     * @param array<string, string> $errors
     */
    private function createForm(Request $request, Identity $identity, array $old, array $errors, int $status = 200): Response
    {
        return $this->pages->render($request, '@Identity/users/create', [
            'old' => $old + ['login' => '', 'name' => '', 'email' => '', 'roles' => []],
            'errors' => $errors,
            'assignable' => $this->assignable($identity),
            'store_url' => $this->pages->url('users.store'),
            'index_url' => $this->pages->url('users.index'),
        ], $status, $identity, 'users');
    }

    /**
     * @param array<string, mixed>  $old
     * @param array<string, string> $errors
     */
    private function detail(Request $request, Identity $identity, User $target, array $old, array $errors, int $status = 200): Response
    {
        $url = fn(string $name): string => $this->pages->url($name, ['id' => $target->uid]);
        $active = \array_filter($this->tokens->forUser($target->id), static fn($t): bool => $t->isUsable());

        return $this->pages->render($request, '@Identity/users/show', [
            'target' => $target,
            'refusal' => $this->admin->refusal($identity, $target),
            'old' => $old + ['name' => $target->name, 'email' => (string) $target->email, 'roles' => $target->effectiveRoles()],
            'errors' => $errors,
            'assignable' => $this->assignable($identity),
            'active_tokens' => \count($active),
            'index_url' => $this->pages->url('users.index'),
            'actions' => [
                'update' => $url('users.update'),
                'status' => $url('users.status'),
                'password' => $url('users.password'),
                'reset_link' => $url('users.reset_link'),
                'two_factor' => $url('users.two_factor_reset'),
                'sessions' => $url('users.sessions_end'),
            ],
        ], $status, $identity, 'users');
    }

    /** @return list<array{name: string, label: string, help: string, allowed: bool}> */
    private function assignable(Identity $identity): array
    {
        return [
            ['name' => Roles::CUSTOMER, 'label' => 'Customer', 'help' => 'Uses the customer side: their own hosting.', 'allowed' => true],
            ['name' => Roles::ADMIN, 'label' => 'Administrator', 'help' => 'Runs the panel: users, servers, audit log.', 'allowed' => $this->admin->mayGrant($identity, [Roles::ADMIN])],
        ];
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return \is_string($value) ? $value : '';
    }

    /** A trimmed field, or null when it was left empty. */
    private static function optional(Request $request, string $key): ?string
    {
        $value = \trim(self::text($request, $key));

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private static function roles(Request $request): array
    {
        $roles = $request->input('roles');

        return \is_array($roles) ? \array_values(\array_filter($roles, 'is_string')) : [];
    }

    /**
     * The first message for each field, keyed by the field's top-level name.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private static function errors(Schema $schema, array $input): array
    {
        $errors = [];

        foreach ($schema->validate($input)->messages() as $path => $messages) {
            $field = (string) \preg_replace('/[.\[].*$/', '', (string) $path);
            $errors[$field] ??= \ucfirst((string) ($messages[0] ?? 'Not valid.'));
        }

        return $errors;
    }
}
