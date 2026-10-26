<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Error\FrameworkException;

/**
 * The access model was put together wrongly.
 *
 * Note what is NOT here: a wrong password, a request from a guest to a route
 * that needs a login, a user without the capability. Those are answers -- 401
 * and 403 -- that a client is entitled to, so they are HttpExceptions, and
 * turning them into this class would make a routine refusal look like a fault
 * and lose the status code on the way.
 *
 * What is here is a mistake in the declarations: a capability nobody defined, a
 * role that inherits itself, a provider that was never configured. All of them
 * are found at boot, which is the point -- an authorization mistake discovered
 * at runtime is discovered by whoever was wrongly allowed through.
 */
final class AuthException extends FrameworkException
{
    public static function unaskableCapability(string $name): self
    {
        return new self(\sprintf(
            'Capability "%s" cannot be checked for. Write it as dotted lowercase segments, '
            . 'e.g. "invoice.void". Wildcards belong in a role\'s grants, never in a check: '
            . '"may the user do anything at all under invoice" is not a question with a useful answer.',
            $name,
        ));
    }

    public static function ungrantableCapability(string $name, string $role): self
    {
        return new self(\sprintf(
            'Role "%s" grants "%s", which is not a capability. Write it as dotted lowercase '
            . 'segments, optionally ending in ".*", or "*" for everything.',
            $role,
            $name,
        ));
    }

    public static function unusableRoleName(string $name): self
    {
        return new self(\sprintf(
            'Role name "%s" cannot be used. Write it as dotted lowercase segments, e.g. '
            . '"accountant" or "finance.approver".',
            $name,
        ));
    }

    public static function duplicateRole(string $name, string $module, string $existing): self
    {
        return new self(\sprintf(
            'Module %s declares the role "%s", which %s already declared. Two modules defining '
            . 'one role means neither can be read on its own to know what it grants.',
            $module,
            $name,
            $existing,
        ));
    }

    public static function duplicatePermission(string $capability, string $module, string $existing): self
    {
        return new self(\sprintf(
            'Module %s declares the capability "%s", which %s already declared. The module that '
            . 'enforces a capability is the one that should define it.',
            $module,
            $capability,
            $existing,
        ));
    }

    public static function unknownRole(string $name, string $referrer): self
    {
        return new self(\sprintf(
            'Role "%s", inherited by "%s", is not declared by any module. A role that inherits '
            . 'something nobody defined grants less than whoever wrote it believed.',
            $name,
            $referrer,
        ));
    }

    /** @param list<string> $chain */
    public static function circularRole(array $chain): self
    {
        return new self(\sprintf(
            'Role inheritance is circular: %s. Roles are flattened once at boot, so a cycle is '
            . 'not a slow lookup -- it is one that never finishes.',
            \implode(' -> ', $chain),
        ));
    }

    public static function undeclaredCapability(string $capability, string $where): self
    {
        return new self(\sprintf(
            'Nothing declares the capability "%s", required by %s. Declare it in the module that '
            . 'enforces it: $access->capability(\'%s\', \'what it allows\'). Without this, the '
            . 'route would refuse everybody and look like a routing bug.',
            $capability,
            $where,
            $capability,
        ));
    }

    public static function noProvider(): self
    {
        return new self(
            'Authentication is in use but no UserProvider is registered. Bind one in a module: '
            . '$services->singleton(UserProvider::class, ...). The framework does not know what '
            . 'a user is, which is the point.',
        );
    }

    public static function emptyLogin(): self
    {
        return new self('A login identifier cannot be an empty string.');
    }

    public static function providerLacks(string $interface, string $provider, string $feature): self
    {
        return new self(\sprintf(
            '%s needs the UserProvider to implement %s, and %s does not. Implement it on the provider '
            . 'registered as UserProvider.',
            $feature,
            $interface,
            $provider,
        ));
    }

    public static function noEmail(string $id): self
    {
        return new self(\sprintf('Account "%s" has no email address to send to.', $id));
    }
}
