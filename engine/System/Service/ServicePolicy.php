<?php

declare(strict_types=1);

namespace App\Engine\System\Service;

/**
 * Which changes to which services are allowed at all.
 *
 *     $policy = ServicePolicy::none()
 *         ->allow('nginx', ServiceAction::Restart, ServiceAction::Reload)
 *         ->allow('php8.3-fpm', ServiceAction::Restart);
 *
 * **Denied unless allowed, one action on one service at a time.** Restarting
 * nginx says nothing about stopping it, and nothing at all about ssh. There is no
 * wildcard: a policy that allowed "every action" or "every service" would be
 * the unrestricted access this exists to prevent, and it would be the easiest
 * one to write.
 *
 * This is what the application is technically willing to do. Who may ask for
 * it is authorization, which is a separate question answered by the auth
 * layer's capabilities.
 *
 * Immutable: allow() returns a new policy.
 */
final class ServicePolicy
{
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9:_.@-]{0,246}$/D';

    private const SUFFIX = '.service';

    /** @param array<string, list<ServiceAction>> $allowed unit => actions */
    private function __construct(private readonly array $allowed) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function allow(string $service, ServiceAction ...$actions): self
    {
        $unit = self::unit($service);
        $allowed = $this->allowed;

        foreach ($actions as $action) {
            if (!\in_array($action, $allowed[$unit] ?? [], true)) {
                $allowed[$unit][] = $action;
            }
        }

        return new self($allowed);
    }

    public function permits(string $service, ServiceAction $action): bool
    {
        return \in_array($action, $this->allowed[self::unit($service)] ?? [], true);
    }

    /** @return array<string, list<ServiceAction>> unit => actions, for listing */
    public function allowed(): array
    {
        return $this->allowed;
    }

    /**
     * The systemd unit a service name means: "nginx" and "nginx.service" are
     * the same unit. Anything that is not a service unit is refused, so that
     * no name reaches systemctl as an option ("-H host") or as a target
     * ("poweroff.target").
     */
    public static function unit(string $service): string
    {
        $name = \str_ends_with($service, self::SUFFIX) ? \substr($service, 0, -\strlen(self::SUFFIX)) : $service;

        if (\preg_match(self::NAME, $name) !== 1 || \preg_match('/\.(target|socket|timer|mount|automount|path|slice|scope|swap|device)$/D', $name) === 1) {
            throw ServiceException::invalidName();
        }

        return $name . self::SUFFIX;
    }
}
