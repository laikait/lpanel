<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

/**
 * Agent bodies are decoded strictly, as the agent decodes operations: an
 * object with exactly the expected keys. A field the panel does not know is
 * an error, never something to ignore.
 */
final class StrictJson
{
    /**
     * @param list<string> $keys  exactly these, no more and no fewer
     * @param int<1, max>  $depth how deep the body may nest
     *
     * @return array<string, mixed>
     *
     * @throws AgentException bad_request
     */
    public static function object(string $json, array $keys, string $what, int $depth = 8): array
    {
        try {
            $value = \json_decode($json, true, $depth, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw AgentException::badRequest(\sprintf('malformed %s body', $what));
        }

        return self::shape($value, $keys, $what);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     *
     * @throws AgentException bad_request
     */
    public static function shape(mixed $value, array $keys, string $what): array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            throw AgentException::badRequest(\sprintf('%s must be a JSON object', $what));
        }

        $given = \array_map('strval', \array_keys($value));
        \sort($given);
        $expected = $keys;
        \sort($expected);

        if ($given !== $expected) {
            throw AgentException::badRequest(\sprintf('%s must have exactly the fields %s', $what, \implode(', ', $keys)));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @throws AgentException bad_request */
    public static function string(mixed $value, string $pattern, string $what): string
    {
        if (!\is_string($value) || \preg_match($pattern, $value) !== 1) {
            throw AgentException::badRequest(\sprintf('%s is not valid', $what));
        }

        return $value;
    }

    /** @throws AgentException bad_request */
    public static function int(mixed $value, int $min, int $max, string $what): int
    {
        if (!\is_int($value) || $value < $min || $value > $max) {
            throw AgentException::badRequest(\sprintf('%s must be a whole number from %d to %d', $what, $min, $max));
        }

        return $value;
    }

    /**
     * @return list<int>
     *
     * @throws AgentException bad_request
     */
    public static function ints(mixed $value, int $min, int $max, int $count, string $what): array
    {
        if (!\is_array($value) || !\array_is_list($value) || \count($value) > $count) {
            throw AgentException::badRequest(\sprintf('%s must be a list of at most %d numbers', $what, $count));
        }

        return \array_map(static fn(mixed $v): int => self::int($v, $min, $max, $what), $value);
    }
}
