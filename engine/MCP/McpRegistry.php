<?php

declare(strict_types=1);

namespace App\Engine\MCP;

use App\Engine\Auth\Capability as AuthCapability;

/**
 * Every tool, resource and prompt the application has registered.
 *
 * **Nothing is here unless a module put it here.** There is no discovery by
 * directory, no exposure of models or routes, and no default capability: an
 * application with no MCP registrations offers a client nothing at all.
 *
 * **One name, one owner.** A second registration of a name -- by another
 * module, or by the same one -- stops boot with a message naming both, the
 * same rule routes and commands follow. **Order is registration order**, which
 * is module load order, so a listing is the same on every request and every
 * machine.
 *
 * Modules register through McpCollector, which says who they are; they never
 * write to this directly.
 */
final class McpRegistry
{
    private const NAME = '/^[a-z][a-z0-9._-]{0,127}$/D';

    /** @var array<string, array<string, Capability>> kind => name => capability */
    private array $capabilities = [
        'tool' => [],
        'resource' => [],
        'prompt' => [],
    ];

    /** @throws RegistryException */
    public function register(Capability $capability): void
    {
        self::check($capability);

        $existing = $this->capabilities[$capability->kind->value][$capability->name] ?? null;

        if ($existing !== null) {
            throw RegistryException::duplicate($capability->kind->value, $capability->name, $capability->module, $existing->module);
        }

        $this->capabilities[$capability->kind->value][$capability->name] = $capability;
    }

    public function find(CapabilityKind $kind, string $name): ?Capability
    {
        return $this->capabilities[$kind->value][$name] ?? null;
    }

    /** @return list<Capability> in registration order */
    public function all(CapabilityKind $kind): array
    {
        return \array_values($this->capabilities[$kind->value]);
    }

    /** @return list<Capability> every kind, tools first, for a listing */
    public function everything(): array
    {
        return [...$this->all(CapabilityKind::Tool), ...$this->all(CapabilityKind::Resource), ...$this->all(CapabilityKind::Prompt)];
    }

    private static function check(Capability $capability): void
    {
        if ($capability->kind === CapabilityKind::Resource) {
            self::checkUriTemplate($capability->name);
        } elseif (\preg_match(self::NAME, $capability->name) !== 1) {
            throw RegistryException::invalidName($capability->kind->value, $capability->name);
        }

        if (\trim($capability->handler) === '') {
            throw RegistryException::emptyHandler($capability->kind->value, $capability->name);
        }

        if ($capability->permission !== null && !AuthCapability::isAskable($capability->permission)) {
            throw RegistryException::invalidPermission($capability->kind->value, $capability->name, $capability->permission);
        }
    }

    /**
     * `scheme://path` with `{name}` placeholders. A file: scheme is refused
     * whatever follows it: a resource is something the application describes,
     * never a door onto the server's files.
     */
    private static function checkUriTemplate(string $template): void
    {
        if (\preg_match('/^([a-z][a-z0-9+.-]*):\/\/[^\s]+$/D', $template, $match) !== 1) {
            throw RegistryException::invalidUriTemplate($template, 'it is scheme://path, lowercase scheme, no spaces');
        }

        if ($match[1] === 'file') {
            throw RegistryException::invalidUriTemplate($template, 'file: URIs would expose the server\'s filesystem');
        }

        $open = \substr_count($template, '{');

        if ($open !== \substr_count($template, '}') || \preg_match_all('/\{[a-z_][a-z0-9_]*\}/', $template) !== $open) {
            throw RegistryException::invalidUriTemplate($template, 'placeholders are {name}, lowercase letters, digits and underscore');
        }
    }
}
