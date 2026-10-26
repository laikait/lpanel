<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/**
 * What a module gets inside $module->access(...).
 *
 * The same shape as RouteCollector and CommandCollector, for the same reason: a
 * module declares what it provides, the manager replays the declarations in a
 * deterministic order, and the module never touches a registry itself.
 *
 * ```php
 * $module->access(static function (AccessCollector $access): void {
 *     $access->capability('invoice.void', 'Cancel an invoice that has been issued.');
 *     $access->role('accountant', ['invoice.*', 'report.finance.read']);
 * });
 * ```
 *
 * Capabilities are declared by the module that **enforces** them; roles by
 * whichever module is entitled to say what a job title means, which in most
 * applications is the shared one.
 */
final class AccessCollector
{
    public function __construct(
        private readonly AccessRegistry $registry,
        private readonly string $module = '',
    ) {}

    /**
     * A capability this module will check for.
     *
     * The description is not decoration: it is what appears on a role screen
     * and in `auth:access`, and "invoice.void" on its own does not tell an
     * administrator whether granting it also lets somebody re-issue.
     */
    public function capability(string $capability, string $description = ''): self
    {
        $this->registry->declarePermission(new Permission($capability, $description, $this->module));

        return $this;
    }

    /**
     * A role, and what it grants.
     *
     * @param list<string> $capabilities may include `prefix.*` or `*`
     * @param list<string> $inherits     other roles, which need not be declared yet
     */
    public function role(
        string $name,
        array $capabilities = [],
        array $inherits = [],
        string $description = '',
    ): self {
        $this->registry->declareRole(new Role($name, $capabilities, $inherits, $description, $this->module));

        return $this;
    }
}
