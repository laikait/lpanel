<?php

declare(strict_types=1);

namespace App\Engine\Dispatch;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Support\Coercion;

/**
 * Turns a route's handler declaration into arguments the container can invoke.
 *
 * Two rules matter here, and both are deliberate:
 *
 *  - Route parameters are matched to handler arguments BY NAME. The Request is
 *    injected BY TYPE. That separation means a route parameter called
 *    "request" cannot displace the actual request object.
 *
 *  - Scalar coercion is narrow. A declared int/float/bool is converted only
 *    when the conversion is unambiguous -- Support\Coercion decides that, and
 *    decides it the same way for storage rows and schema input -- so anything
 *    else is a clean 400 rather than a TypeError surfacing as a 500. This is
 *    the one place the framework trades explicitness for ergonomics, and the
 *    trade is bounded: no guessing, and a readable error when it does not fit.
 */
final class HandlerResolver
{
    /**
     * Normalise the declared handler into something Container::call() accepts.
     *
     * Nothing is instantiated here; the container does that, so constructor
     * injection still applies.
     *
     * @return InvokableTarget
     */
    public function resolve(mixed $handler): callable|array|string
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        if (\is_string($handler)) {
            if (\str_contains($handler, '::')) {
                return $handler;
            }

            if (!\class_exists($handler)) {
                throw DispatchException::unresolvableHandler(
                    \sprintf('"%s"', $handler),
                    'no such class, and it is not a "Class::method" string.',
                );
            }

            if (!\method_exists($handler, '__invoke')) {
                throw DispatchException::unresolvableHandler(
                    $handler,
                    'the class exists but has no __invoke() method. '
                    . 'Either add one or declare the handler as [Class::class, \'method\'].',
                );
            }

            return [$handler, '__invoke'];
        }

        if (\is_array($handler)) {
            if (\count($handler) !== 2 || !isset($handler[0], $handler[1]) || !\is_string($handler[1])) {
                throw DispatchException::unresolvableHandler(
                    'an array handler',
                    'it must be exactly [Class::class or $object, \'method\'].',
                );
            }

            $target = $handler[0];
            $method = $handler[1];

            if (\is_string($target) && !\class_exists($target)) {
                throw DispatchException::unresolvableHandler(
                    \sprintf('%s::%s()', $target, $method),
                    'no such class.',
                );
            }

            if (!\method_exists($target, $method)) {
                throw DispatchException::unresolvableHandler(
                    \sprintf('%s::%s()', \is_string($target) ? $target : $target::class, $method),
                    'no such method.',
                );
            }

            return [$target, $method];
        }

        if (\is_object($handler) && \method_exists($handler, '__invoke')) {
            return $handler;
        }

        throw DispatchException::unresolvableHandler(
            \get_debug_type($handler),
            'a handler must be a class name, [class, method], a closure, or an invokable object.',
        );
    }

    /**
     * Build the named argument overrides for a call.
     *
     * Only parameters the handler actually declares are passed, so a route may
     * capture more than a given handler cares about.
     *
     * @param InvokableTarget                           $callable
     * @param array<string, string|int|float|bool|null> $parameters
     *
     * @return array<string, mixed>
     */
    public function arguments(callable|array|string $callable, array $parameters, Request $request): array
    {
        $reflection = $this->reflect($callable);
        $arguments = [];

        foreach ($reflection->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            // The request goes in by type, never by name, so that a route
            // parameter named "request" cannot hijack it.
            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[$name] = $request;

                continue;
            }

            if (!\array_key_exists($name, $parameters)) {
                continue;
            }

            $arguments[$name] = $this->coerce($parameters[$name], $parameter, $reflection);
        }

        return $arguments;
    }

    /** @param InvokableTarget $callable */
    private function reflect(callable|array|string $callable): \ReflectionFunctionAbstract
    {
        if ($callable instanceof \Closure) {
            return new \ReflectionFunction($callable);
        }

        if (\is_string($callable) && \str_contains($callable, '::')) {
            [$class, $method] = \explode('::', $callable, 2);

            return new \ReflectionMethod($class, $method);
        }

        if (\is_string($callable)) {
            return new \ReflectionFunction($callable);
        }

        if (\is_array($callable)) {
            /** @var object|class-string $target */
            $target = $callable[0];
            /** @var string $method */
            $method = $callable[1];

            return new \ReflectionMethod($target, $method);
        }

        if (\is_object($callable)) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        throw DispatchException::unresolvableHandler(
            \get_debug_type($callable),
            'it is not something that can be reflected.',
        );
    }

    /**
     * Convert a captured route parameter to the type the handler declares.
     *
     * Conversion happens only when it is unambiguous. "7" becomes 7; "abc" for
     * an int parameter is a client error, so it becomes a 400 here rather than
     * a TypeError and a 500 one frame later.
     */
    private function coerce(
        string|int|float|bool|null $value,
        \ReflectionParameter $parameter,
        \ReflectionFunctionAbstract $owner,
    ): mixed {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || !$type->isBuiltin() || !\is_string($value)) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => $this->toInt($value, $parameter, $owner),
            'float' => $this->toFloat($value, $parameter, $owner),
            'bool' => $this->toBool($value, $parameter, $owner),
            default => $value,
        };
    }

    private function toInt(string $value, \ReflectionParameter $parameter, \ReflectionFunctionAbstract $owner): int
    {
        return Coercion::toInt($value) ?? throw $this->rejected($value, 'an integer', $parameter, $owner);
    }

    private function toFloat(string $value, \ReflectionParameter $parameter, \ReflectionFunctionAbstract $owner): float
    {
        return Coercion::toFloat($value) ?? throw $this->rejected($value, 'a number', $parameter, $owner);
    }

    private function toBool(string $value, \ReflectionParameter $parameter, \ReflectionFunctionAbstract $owner): bool
    {
        return Coercion::toBool($value) ?? throw $this->rejected($value, 'a boolean', $parameter, $owner);
    }

    private function rejected(
        string $value,
        string $expected,
        \ReflectionParameter $parameter,
        \ReflectionFunctionAbstract $owner,
    ): HttpException {
        $where = $owner instanceof \ReflectionMethod
            ? $owner->getDeclaringClass()->getShortName() . '::' . $owner->getName() . '()'
            : 'the route handler';

        return HttpException::badRequest(\sprintf(
            'The "%s" parameter of %s must be %s, but "%s" was given.',
            $parameter->getName(),
            $where,
            $expected,
            $value,
        ));
    }
}
