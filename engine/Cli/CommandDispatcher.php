<?php

declare(strict_types=1);

namespace App\Engine\Cli;

use App\Engine\Container\Container;
use App\Engine\Dispatch\DispatchException;
use App\Engine\Dispatch\HandlerResolver;
use App\Engine\Hook\HookEngine;
use App\Engine\Support\Coercion;

/**
 * Calls a command's handler and turns what it returns into an exit code.
 *
 * The HTTP dispatcher's twin, and deliberately built from the same parts:
 * HandlerResolver normalises the declared handler, the container calls it so
 * constructor dependencies are injected, and declared input binds to parameters
 * by name while everything with a class type comes from the container. A route
 * parameter called "request" cannot displace the Request; a command argument
 * called "output" cannot displace the Output, for exactly the same reason.
 *
 * What differs is the far end. HTTP normalises a return value into a Response;
 * here it becomes a process exit code, and the rules are narrower because an
 * exit code is read by a shell script that cannot ask what was meant.
 */
final class CommandDispatcher
{
    public function __construct(
        private readonly Container $container,
        private readonly HookEngine $hooks,
        private readonly HandlerResolver $handlers = new HandlerResolver(),
    ) {}

    public function dispatch(Command $command, Input $input, Output $output): int
    {
        $target = $this->handlers->resolve($command->handler);

        // Injected by type, the way the Request is during a web request.
        $this->container->instance(Input::class, $input);
        $this->container->instance(Output::class, $output);

        $this->hooks->do('command.matched', $command, $input);

        /** @var mixed $result */
        $result = $this->container->call($target, $this->arguments($target, $command, $input));

        $status = $this->status($command, $result, $output);

        $this->hooks->do('command.finished', $status, $command, $input);

        return $status;
    }

    /**
     * Bind declared input to the handler's parameters.
     *
     * Only parameters the handler actually declares are passed, so a command
     * may accept more input than a given handler cares to look at -- the same
     * rule routing uses.
     *
     * @param callable|array{0: object|class-string, 1: string}|string $target
     *
     * @return array<string, mixed>
     */
    private function arguments(callable|array|string $target, Command $command, Input $input): array
    {
        $values = $this->byParameterName($input);
        $arguments = [];

        foreach ($this->reflect($target)->getParameters() as $parameter) {
            $type = $parameter->getType();

            // A class type is a service, an Input or an Output. Those come from
            // the container, never from something the operator typed.
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                continue;
            }

            if ($type !== null && !$type instanceof \ReflectionNamedType) {
                continue;
            }

            $name = $parameter->getName();

            if (!\array_key_exists($name, $values)) {
                continue;
            }

            $value = $values[$name];

            if ($value === null) {
                continue;
            }

            $arguments[$name] = $this->coerce($command, $name, $value, $type);
        }

        return $arguments;
    }

    /**
     * Input values under every name a parameter could reasonably use.
     *
     * A dash is not legal in a PHP parameter name, so --dry-run has to bind to
     * something: it binds to $dryRun. The transformation is mechanical and goes
     * one way only, and an option with no dash in it binds to its own name, so
     * the common case involves no transformation at all.
     *
     * @return array<string, string|bool|null>
     */
    private function byParameterName(Input $input): array
    {
        $values = [];

        foreach ($input->parameters() as $name => $value) {
            $values[$name] = $value;

            if (\str_contains($name, '-')) {
                $values[\lcfirst(\str_replace(' ', '', \ucwords(\str_replace('-', ' ', $name))))] = $value;
            }
        }

        return $values;
    }

    /**
     * Convert a typed-in value to what the handler declared.
     *
     * Narrow, like the routing coercion, and through the same Coercion helper
     * so that "1" means the same thing here as it does in a URL. Anything that
     * does not convert cleanly is the operator's mistake, so it is a usage
     * error with the command's synopsis, not a TypeError.
     */
    private function coerce(Command $command, string $name, string|bool $value, ?\ReflectionNamedType $type): mixed
    {
        if ($type === null || !$type->isBuiltin()) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => \is_string($value)
                ? Coercion::toInt($value) ?? throw ConsoleException::valueRejected(
                    $command->name,
                    $name,
                    'a whole number',
                    $value,
                )
                : throw ConsoleException::valueRejected($command->name, $name, 'a whole number', 'a flag'),
            'float' => \is_string($value)
                ? Coercion::toFloat($value) ?? throw ConsoleException::valueRejected(
                    $command->name,
                    $name,
                    'a number',
                    $value,
                )
                : throw ConsoleException::valueRejected($command->name, $name, 'a number', 'a flag'),
            'bool' => \is_bool($value)
                ? $value
                : Coercion::toBool($value) ?? throw ConsoleException::valueRejected(
                    $command->name,
                    $name,
                    'yes or no',
                    $value,
                ),
            'string' => \is_string($value)
                ? $value
                : throw ConsoleException::valueRejected($command->name, $name, 'text', 'a flag'),
            default => $value,
        };
    }

    /**
     * What a command returns, and what the shell sees.
     *
     *   int     the exit code, as given
     *   string  printed, then success
     *   null    success
     *
     * A bool is refused on purpose. Whether true means success or failure is a
     * coin toss -- PHP's own convention says true is success, the shell's says
     * 0 is -- and an exit code that gets it backwards turns a failed job into a
     * green tick.
     */
    private function status(Command $command, mixed $result, Output $output): int
    {
        if (\is_int($result)) {
            return $result;
        }

        if ($result === null) {
            return 0;
        }

        if (\is_string($result)) {
            $output->line($result);

            return 0;
        }

        throw ConsoleException::unexpectedResult($command->name, \get_debug_type($result));
    }

    /** @param callable|array{0: object|class-string, 1: string}|string $target */
    private function reflect(callable|array|string $target): \ReflectionFunctionAbstract
    {
        if ($target instanceof \Closure) {
            return new \ReflectionFunction($target);
        }

        if (\is_string($target) && \str_contains($target, '::')) {
            [$class, $method] = \explode('::', $target, 2);

            return new \ReflectionMethod($class, $method);
        }

        if (\is_string($target)) {
            return new \ReflectionFunction($target);
        }

        if (\is_array($target)) {
            return new \ReflectionMethod($target[0], $target[1]);
        }

        if (\is_object($target)) {
            return new \ReflectionMethod($target, '__invoke');
        }

        // Unreachable in practice: HandlerResolver has already refused
        // anything that is not one of the forms above.
        throw DispatchException::unresolvableHandler(
            \get_debug_type($target),
            'it is not something that can be reflected.',
        );
    }
}
