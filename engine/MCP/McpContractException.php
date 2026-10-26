<?php

declare(strict_types=1);

namespace App\Engine\MCP;

/**
 * Code built an MCP value that breaks the contract -- a programming mistake,
 * found when the value is made rather than when a client receives it.
 *
 * The client is told only that the server failed.
 */
final class McpContractException extends McpException
{
    public static function filterChangedType(string $filter, string $expected, string $given): self
    {
        return new self(\sprintf('The %s filter returned %s. It must return %s: a filter changes the value, never what kind of value it is.', $filter, $given, $expected));
    }

    public static function emptyErrorMessage(): self
    {
        return new self('An MCP error needs a message: it is the only explanation a client receives.');
    }

    public static function notPlainData(string $path, string $type): self
    {
        return new self(\sprintf(
            'MCP value %s holds %s. What is sent to a client is scalars, null and arrays of them, so nothing about an object can leak through it.',
            $path,
            $type,
        ));
    }

    public static function notATool(string $name, string $class): self
    {
        return new self(\sprintf('MCP tool "%s" is handled by %s, which does not implement %s.', $name, $class, Tool\Tool::class));
    }

    public static function unknownTransport(string $transport): self
    {
        return new self(\sprintf('"%s" is not an MCP transport. The transports are %s.', $transport, \implode(' and ', McpContext::TRANSPORTS)));
    }

    public static function emptyRequestId(): self
    {
        return new self('An MCP context needs a request id: it is how a failure the client is told nothing about is found in the log.');
    }

    public static function notAPrompt(string $name, string $class): self
    {
        return new self(\sprintf('MCP prompt "%s" is handled by %s, which does not implement %s.', $name, $class, Prompt\Prompt::class));
    }

    public static function invalidPromptArgument(string $name): self
    {
        return new self(\sprintf('Prompt argument name "%s" is invalid. It is up to 64 lowercase letters, digits and underscore.', $name));
    }

    public static function emptyPrompt(): self
    {
        return new self('A prompt resolves to at least one message.');
    }

    public static function invalidMimeType(string $type): self
    {
        return new self(\sprintf('"%s" is not a media type such as text/plain or application/json.', $type));
    }

    public static function notAResource(string $template, string $class): self
    {
        return new self(\sprintf('MCP resource "%s" is handled by %s, which does not implement %s.', $template, $class, Resource\Resource::class));
    }

    public static function invalidInputSchema(string $name, string $why): self
    {
        return new self(\sprintf('MCP tool "%s" has an unusable input schema: %s.', $name, $why));
    }

    public function error(): McpError
    {
        return McpError::internal();
    }
}
