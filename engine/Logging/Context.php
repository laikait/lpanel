<?php

declare(strict_types=1);

namespace App\Engine\Logging;

use App\Engine\Support\Coercion;

/**
 * Turns whatever a caller passed as context into something safe to write down.
 *
 * Two jobs, and both exist because a log line is written at the worst possible
 * moment: something has already gone wrong, and this must not be the thing that
 * goes wrong next.
 *
 * **Normalising.** Context is an arbitrary array. It can contain a closure, a
 * PDO handle, a model with a circular reference to its own collection, a
 * resource, or ten thousand rows. json_encode() on any of those either fails,
 * throws, or writes a megabyte into the log. So values are reduced to something
 * printable first: scalars survive, throwables become class, message and
 * position, objects become their class name unless they can say more for
 * themselves, and depth and length are capped.
 *
 * **Redacting.** A small, exact, configurable list of key names is replaced
 * with a marker. This is deliberately *not* the same thing as filtering a
 * message, which this framework refuses to do on the grounds that guessing
 * which substrings are secret is wrong eventually. A key is not a substring: it
 * is structured data, matched exactly, against a list somebody wrote down. It
 * catches the case that actually happens -- a request payload logged whole,
 * with "password" still in it -- and claims nothing about the rest.
 *
 * A log file is still sensitive. Redaction makes an accident less likely; it
 * does not make the file safe to publish.
 */
final class Context
{
    public const REDACTED = '[redacted]';

    public const MAX_DEPTH = 4;

    public const MAX_STRING = 2048;

    public const MAX_ITEMS = 50;

    /** Key names replaced wherever they appear, compared case-insensitively. */
    public const SENSITIVE = [
        'password',
        'passwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'credentials',
        'private_key',
        'card_number',
        'cvv',
    ];

    /** @var list<string> */
    private readonly array $sensitive;

    /** @param list<string> $sensitive extra key names, added to the defaults */
    public function __construct(array $sensitive = [])
    {
        $this->sensitive = \array_values(\array_unique(\array_map(
            \strtolower(...),
            [...self::SENSITIVE, ...$sensitive],
        )));
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function normalise(array $context): array
    {
        return $this->walk($context, 0);
    }

    public function isSensitive(string $key): bool
    {
        return \in_array(\strtolower($key), $this->sensitive, true);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function walk(array $values, int $depth): array
    {
        $result = [];
        $seen = 0;

        foreach ($values as $key => $value) {
            if (++$seen > self::MAX_ITEMS) {
                $result['...'] = \sprintf('%d more', \count($values) - self::MAX_ITEMS);

                break;
            }

            $result[$key] = \is_string($key) && $this->isSensitive($key)
                ? self::REDACTED
                : $this->value($value, $depth);
        }

        return $result;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return $value;
        }

        if (\is_float($value)) {
            // NAN and INF are not representable in JSON and would either throw
            // or silently become null. Named rather than cast, because a logger
            // that raises a warning while recording one has failed at the one
            // thing it exists for. See Coercion::fromFloat().
            return \is_finite($value) ? $value : Coercion::fromFloat($value);
        }

        if (\is_string($value)) {
            return \strlen($value) > self::MAX_STRING
                ? \substr($value, 0, self::MAX_STRING) . '...'
                : $value;
        }

        if ($value instanceof \Throwable) {
            return $this->throwable($value, $depth);
        }

        if (\is_array($value)) {
            return $depth >= self::MAX_DEPTH ? '[array]' : $this->walk($value, $depth + 1);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if ($value instanceof \JsonSerializable) {
            $data = $value->jsonSerialize();

            return \is_array($data) && $depth < self::MAX_DEPTH ? $this->walk($data, $depth + 1) : '[' . $value::class . ']';
        }

        if ($value instanceof \Stringable) {
            return $this->value((string) $value, $depth);
        }

        if (\is_object($value)) {
            // The class name and nothing else. An object that has not said how
            // it wants to be logged does not get guessed at -- var_export on a
            // model with relations loaded is how a log file becomes a gigabyte.
            return '[' . $value::class . ']';
        }

        // Resources and anything else with no printable form.
        return '[' . \get_debug_type($value) . ']';
    }

    /** @return array<string, mixed> */
    private function throwable(\Throwable $e, int $depth): array
    {
        $described = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile() . ':' . $e->getLine(),
        ];

        $previous = $e->getPrevious();

        if ($previous !== null && $depth < self::MAX_DEPTH) {
            $described['previous'] = $this->throwable($previous, $depth + 1);
        }

        return $described;
    }
}
