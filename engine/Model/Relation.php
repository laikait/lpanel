<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * A declaration that two models are related, and how.
 *
 * This is metadata and nothing else. A Relation has no reference to a database,
 * a repository or a query, cannot load anything, and is not consulted when a
 * property is read. It exists so that a data layer can answer two mechanical
 * questions -- which values to look for, and which parent each result belongs
 * to -- without every repository reinventing the join by hand.
 *
 *     Relation::many('invoices', Invoice::class, localKey: 'id', foreignKey: 'customerId')
 *     Relation::one('owner', User::class, localKey: 'ownerId', foreignKey: 'id')
 *
 * Both directions use the same two keys: localKey names the attribute on the
 * parent that holds the linking value, foreignKey names the attribute on the
 * related model that holds it. Saying which side holds the key is clearer than
 * a method name that implies it.
 */
final class Relation
{
    /** @param class-string<Model> $related */
    private function __construct(
        public readonly string $name,
        public readonly RelationType $type,
        public readonly string $related,
        public readonly string $localKey,
        public readonly string $foreignKey,
    ) {
        if (!\is_a($related, Model::class, true)) {
            throw ModelException::notAModel($related);
        }
    }

    /** @param class-string<Model> $related */
    public static function one(string $name, string $related, string $localKey, string $foreignKey): self
    {
        return new self($name, RelationType::One, $related, $localKey, $foreignKey);
    }

    /** @param class-string<Model> $related */
    public static function many(string $name, string $related, string $localKey, string $foreignKey): self
    {
        return new self($name, RelationType::Many, $related, $localKey, $foreignKey);
    }

    public function isMany(): bool
    {
        return $this->type === RelationType::Many;
    }

    /** @return array{name: string, type: string, related: string, localKey: string, foreignKey: string} */
    public function describe(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'related' => $this->related,
            'localKey' => $this->localKey,
            'foreignKey' => $this->foreignKey,
        ];
    }
}
