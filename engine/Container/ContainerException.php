<?php

declare(strict_types=1);

namespace App\Engine\Container;

use Psr\Container\ContainerExceptionInterface;

/**
 * Resolution failures.
 *
 * Every named constructor exists to make the message actionable: the developer
 * should be able to fix the problem from the exception text alone, without
 * opening the container.
 */
final class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
    /** @param list<string> $chain */
    public static function circularDependency(array $chain): self
    {
        return new self(\sprintf(
            "Circular dependency detected while resolving %s:\n    %s",
            $chain[0] ?? '?',
            \implode(' -> ', $chain),
        ));
    }

    public static function notInstantiable(string $id, string $reason): self
    {
        return new self(\sprintf(
            '%s is not instantiable (%s). Bind an implementation: $services->bind(%s::class, YourImplementation::class);',
            $id,
            $reason,
            $id,
        ));
    }

    public static function unresolvableParameter(string $owner, string $parameter, string $type): self
    {
        return new self(\sprintf(
            'Cannot autowire %s $%s of %s: it has no default value and nothing is bound for it. '
            . 'Bind it explicitly in the owning module, for example: '
            . '$services->singleton(Thing::class, static fn (Container $c) => new Thing($config->get(...)));',
            $type,
            $parameter,
            $owner,
        ));
    }

    public static function ambiguousUnion(string $owner, string $parameter, string $type): self
    {
        return new self(\sprintf(
            'Cannot autowire union type %s $%s of %s: no member of the union is bound. '
            . 'Union parameters are never guessed. Bind exactly one member explicitly.',
            $type,
            $parameter,
            $owner,
        ));
    }

    public static function intersectionNotSupported(string $owner, string $parameter, string $type): self
    {
        return new self(\sprintf(
            'Cannot autowire intersection type %s $%s of %s. '
            . 'Intersection types are never autowired; bind the parameter explicitly.',
            $type,
            $parameter,
            $owner,
        ));
    }

    public static function notCallable(string $description): self
    {
        return new self(\sprintf('Cannot call %s: it is not a resolvable callable.', $description));
    }
}
