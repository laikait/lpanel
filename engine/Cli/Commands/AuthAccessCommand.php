<?php

declare(strict_types=1);

namespace App\Engine\Cli\Commands;

use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthGuard;
use App\Engine\Auth\AuthManager;
use App\Engine\Auth\Capability;
use App\Engine\Cli\Output;
use App\Engine\Routing\Router;

/**
 * Everything the application can express about who may do what.
 *
 * The command the whole access model exists to make possible. "Which
 * permissions are there, what does each role get, and which of them does any
 * route actually check" is a question somebody has to answer -- for an audit,
 * for a role screen, for the person deciding what a new starter gets -- and in
 * most applications the answer is to grep for strings and hope.
 *
 * It reads the live registry, so it reports what the running process resolved
 * to rather than what a file says: roles appear flattened, with inherited
 * capabilities folded in, because that is what a check will actually see.
 *
 * The last section is the useful one. A capability nobody checks is either
 * dead or a hole -- somebody declared the permission, put it on a role screen,
 * granted it to people, and then never guarded the endpoint.
 */
final class AuthAccessCommand
{
    public function __construct(
        private readonly AccessRegistry $access,
        private readonly AuthManager $auth,
        private readonly Router $router,
    ) {}

    public function __invoke(Output $output, bool $verbose = false): int
    {
        $output->heading('Access');
        $output->pairs([
            'Users' => $this->auth->provider()->describe(),
            'Authenticators' => $this->auth->describe(),
            'Capabilities' => (string) \count($this->access->permissions()),
            'Roles' => (string) \count($this->access->roles()),
        ]);

        $this->printCapabilities($output);
        $this->printRoles($output);
        $this->printRoutes($output, $verbose);
        $this->printUnchecked($output);

        return 0;
    }

    private function printCapabilities(Output $output): void
    {
        $permissions = $this->access->permissions();

        if ($permissions === []) {
            $output->line();
            $output->warning('No module declares a capability.');
            $output->line('Nothing can be authorized until one does; every route with a "can" would refuse.');

            return;
        }

        $output->line();
        $output->table(
            ['CAPABILITY', 'MODULE', 'MEANS'],
            \array_map(
                static fn($permission): array => [
                    $permission->capability,
                    $permission->module === '' ? '-' : $permission->module,
                    $permission->description === '' ? '-' : $permission->description,
                ],
                \array_values($permissions),
            ),
        );
    }

    private function printRoles(Output $output): void
    {
        $roles = $this->access->roles();

        if ($roles === []) {
            return;
        }

        $output->line();
        $output->table(
            ['ROLE', 'INHERITS', 'GRANTS (FLATTENED)'],
            \array_map(
                fn($role): array => [
                    $role->name,
                    $role->inherits === [] ? '-' : \implode(', ', $role->inherits),
                    // Flattened, not declared: this is what a check sees, and
                    // the difference between the two is exactly what somebody
                    // reading a role declaration gets wrong.
                    \implode(', ', $this->access->grantsFor([$role->name])) ?: '-',
                ],
                \array_values($roles),
            ),
        );
    }

    private function printRoutes(Output $output, bool $verbose): void
    {
        $rows = [];

        foreach ($this->router->routes() as $route) {
            if (!AuthGuard::isProtected($route) && !$verbose) {
                continue;
            }

            $capabilities = AuthGuard::capabilitiesFor($route);

            $rows[] = [
                $route->method() . ' ' . $route->path(),
                $capabilities === []
                    ? ($route->metaValue(AuthGuard::AUTH_META) === true ? 'login' : 'public')
                    : \implode(' + ', $capabilities),
            ];
        }

        if ($rows === []) {
            return;
        }

        $output->line();
        $output->table(['ROUTE', 'REQUIRES'], $rows);
    }

    /**
     * Capabilities that exist and that nothing asks for.
     *
     * Not an error: plenty are checked in a handler rather than on a route, and
     * this cannot see those. It is a list worth reading, because the other
     * explanation is that somebody granted a permission and forgot to guard the
     * thing it was for.
     */
    private function printUnchecked(Output $output): void
    {
        $checked = [];

        foreach ($this->router->routes() as $route) {
            foreach (AuthGuard::capabilitiesFor($route) as $capability) {
                $checked[$capability] = true;
            }
        }

        $unchecked = [];

        foreach ($this->access->permissions() as $capability => $_) {
            if (!Capability::of($capability)->coveredByAny(\array_keys($checked))) {
                $unchecked[] = $capability;
            }
        }

        if ($unchecked === []) {
            return;
        }

        $output->line();
        $output->line(\sprintf(
            '%d capability(ies) no route checks: %s',
            \count($unchecked),
            \implode(', ', $unchecked),
        ));
        $output->line('Checked in a handler, or granted to people and never guarded. Only you can tell.');
    }
}
