<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * Reads the declared properties of a model as a name => value map.
 *
 * "Attributes" here means the model's own typed properties, not PHP attributes
 * and not database columns. There is no attribute bag and no __get: a model is
 * an ordinary object whose state lives in real, typed, IDE-visible properties.
 * This class simply looks at them, which is what makes change tracking possible
 * without asking authors to route every write through a magic setter.
 *
 * Properties declared by the abstract base itself are excluded, so Model's own
 * bookkeeping never leaks into a row destined for storage.
 *
 * Reflection is memoised per class. It is the dominant cost here, and a heavy
 * request hydrates the same class thousands of times.
 */
final class Attributes
{
    /** @var array<string, list<\ReflectionProperty>> keyed by "class|boundary" */
    private static array $properties = [];

    /**
     * The declared properties of an object, child class first.
     *
     * @param class-string $boundary the abstract base whose own properties are excluded
     *
     * @return list<\ReflectionProperty>
     */
    public static function properties(object $object, string $boundary): array
    {
        $key = $object::class . '|' . $boundary;

        if (isset(self::$properties[$key])) {
            return self::$properties[$key];
        }

        $properties = [];
        $seen = [];
        $class = new \ReflectionClass($object);

        // Walk up from the concrete class and stop at the boundary, so a model
        // hierarchy contributes every level and the engine contributes none.
        while ($class !== false && $class->getName() !== $boundary) {
            foreach ($class->getProperties() as $property) {
                $name = $property->getName();

                // The concrete class wins if a name is shadowed further up.
                if ($property->isStatic() || isset($seen[$name])) {
                    continue;
                }

                $seen[$name] = true;
                $properties[] = $property;
            }

            $class = $class->getParentClass();
        }

        return self::$properties[$key] = $properties;
    }

    /**
     * The current value of every declared property.
     *
     * Uninitialised typed properties are skipped rather than read, because
     * reading one is a fatal Error and "not set yet" is a legitimate state for
     * a model that has not been persisted.
     *
     * @param class-string $boundary
     *
     * @return array<string, mixed>
     */
    public static function values(object $object, string $boundary): array
    {
        $values = [];

        foreach (self::properties($object, $boundary) as $property) {
            if ($property->isInitialized($object)) {
                /** @var mixed $value */
                $value = $property->getValue($object);
                $values[$property->getName()] = $value;
            }
        }

        return $values;
    }

    /** @param class-string $boundary */
    public static function has(object $object, string $boundary, string $name): bool
    {
        foreach (self::properties($object, $boundary) as $property) {
            if ($property->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $boundary
     *
     * @return list<string>
     */
    public static function names(object $object, string $boundary): array
    {
        return \array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            self::properties($object, $boundary),
        );
    }
}
