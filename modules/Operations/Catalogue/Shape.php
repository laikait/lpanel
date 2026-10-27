<?php

declare(strict_types=1);

namespace App\Modules\Operations\Catalogue;

/**
 * A strict JSON shape: the payload_schema and result_schema of
 * AGENT_PROTOCOL.md §6.2, as small as they need to be.
 *
 * Values are checked as json_decode(..., false) gives them, so a JSON object
 * (\stdClass) and a JSON list (array) are never confused. Objects have
 * exactly their properties: a missing one and an unknown one are both errors.
 */
final class Shape
{
    /**
     * @param array<string, Shape> $properties object
     */
    private function __construct(
        private readonly string $type,
        private readonly bool $nullable = false,
        private readonly array $properties = [],
        private readonly ?Shape $items = null,
        private readonly int $maxItems = 0,
        private readonly ?string $pattern = null,
        private readonly int|float $min = 0,
        private readonly int|float $max = 0,
    ) {}

    /** @param array<string, Shape> $properties every one required, nothing else allowed */
    public static function object(array $properties = []): self
    {
        return new self('object', properties: $properties);
    }

    public static function list(self $items, int $maxItems): self
    {
        return new self('list', items: $items, maxItems: $maxItems);
    }

    /** An object used as a map: any keys matching $keyPattern, each value $values. */
    public static function map(self $values, int $maxItems, string $keyPattern): self
    {
        return new self('map', items: $values, maxItems: $maxItems, pattern: $keyPattern);
    }

    /** A string of at most $maxLength bytes, matching $pattern when one is given. */
    public static function string(int $maxLength, ?string $pattern = null): self
    {
        return new self('string', pattern: $pattern, max: $maxLength);
    }

    public static function int(int $min = 0, int $max = \PHP_INT_MAX): self
    {
        return new self('int', min: $min, max: $max);
    }

    public static function number(float $min = 0.0, float $max = \PHP_FLOAT_MAX): self
    {
        return new self('number', min: $min, max: $max);
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    /** The same shape, or null. Go encodes an empty slice or map as null. */
    public function orNull(): self
    {
        return new self($this->type, true, $this->properties, $this->items, $this->maxItems, $this->pattern, $this->min, $this->max);
    }

    /** Null when $value has this shape, else what is wrong and where. */
    public function check(mixed $value, string $at = 'result'): ?string
    {
        if ($value === null) {
            return $this->nullable ? null : $at . ' must not be null';
        }

        switch ($this->type) {
            case 'object':
                if (!$value instanceof \stdClass) {
                    return $at . ' must be an object';
                }

                $given = \get_object_vars($value);

                foreach (\array_keys($given) as $key) {
                    if (!isset($this->properties[(string) $key])) {
                        return \sprintf('%s has an unknown field "%s"', $at, \substr((string) $key, 0, 64));
                    }
                }

                foreach ($this->properties as $key => $shape) {
                    if (!\array_key_exists($key, $given)) {
                        return \sprintf('%s is missing "%s"', $at, $key);
                    }

                    $error = $shape->check($given[$key], $at . '.' . $key);

                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            case 'list':
                if (!\is_array($value) || !\array_is_list($value)) {
                    return $at . ' must be a list';
                }

                if (\count($value) > $this->maxItems) {
                    return \sprintf('%s has more than %d items', $at, $this->maxItems);
                }

                foreach ($value as $i => $item) {
                    $error = $this->items?->check($item, \sprintf('%s[%d]', $at, $i));

                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            case 'map':
                if (!$value instanceof \stdClass) {
                    return $at . ' must be an object';
                }

                $given = \get_object_vars($value);

                if (\count($given) > $this->maxItems) {
                    return \sprintf('%s has more than %d entries', $at, $this->maxItems);
                }

                foreach ($given as $key => $item) {
                    if (\preg_match((string) $this->pattern, (string) $key) !== 1) {
                        return \sprintf('%s has a key that is not allowed', $at);
                    }

                    $error = $this->items?->check($item, $at . '.' . $key);

                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            case 'string':
                if (!\is_string($value)) {
                    return $at . ' must be a string';
                }

                if (\strlen($value) > $this->max || ($this->pattern !== null && \preg_match($this->pattern, $value) !== 1)) {
                    return $at . ' is not an allowed value';
                }

                return null;

            case 'int':
                return \is_int($value) && $value >= $this->min && $value <= $this->max ? null : \sprintf('%s must be a whole number from %d', $at, $this->min);

            case 'number':
                return (\is_int($value) || \is_float($value)) && \is_finite((float) $value) && $value >= $this->min && $value <= $this->max
                    ? null
                    : $at . ' must be a number in range';

            case 'bool':
                return \is_bool($value) ? null : $at . ' must be true or false';
        }

        throw new \LogicException('Unknown shape type ' . $this->type);
    }
}
