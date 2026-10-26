<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * A module registered an MCP capability that cannot be registered.
 *
 * Found at boot, by the author of the module. A client that somehow met one
 * would be told only that the server failed.
 */
final class RegistryException extends McpException
{
    public static function invalidName(string $kind, string $name): self
    {
        return new self(\sprintf(
            'MCP %s name "%s" is invalid. A name is 1 to 128 lowercase letters, digits, dot, dash and underscore, '
            . 'starting with a letter, such as "customer.get".',
            $kind,
            $name,
        ));
    }

    public static function invalidUriTemplate(string $template, string $why): self
    {
        return new self(\sprintf('MCP resource URI template "%s" is invalid: %s.', $template, $why));
    }

    public static function emptyHandler(string $kind, string $name): self
    {
        return new self(\sprintf('MCP %s "%s" has no handler class.', $kind, $name));
    }

    public static function invalidPermission(string $kind, string $name, string $permission): self
    {
        return new self(\sprintf(
            'MCP %s "%s" asks for permission "%s", which is not a capability name such as "customer.view".',
            $kind,
            $name,
            $permission,
        ));
    }

    public static function duplicate(string $kind, string $name, string $module, string $existing): self
    {
        return new self(\sprintf(
            'MCP %s "%s" is registered by %s and again by %s. A name belongs to one module.',
            $kind,
            $name,
            $existing === '' ? 'the application' : $existing,
            $module === '' ? 'the application' : $module,
        ));
    }

    public function error(): McpError
    {
        return McpError::internal();
    }
}
