<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * Holds relation declarations and links loaded models together.
 *
 * Note what it cannot do: load. There is no database handle here and there will
 * not be one. The division is the point --
 *
 *     $customers = $repository->recentlyActive();                     // one query
 *     $owners    = $users->findAll($relations->keysFor($customers, 'owner'));  // one more
 *     $relations->link($customers, 'owner', $owners);                 // no query
 *
 * -- because the alternative, a relation that fetches itself when touched, is
 * how a page that runs two queries in development runs two thousand in
 * production. Here the loading is written out in the repository where it can be
 * seen, profiled and changed, and this class does the bookkeeping that is
 * genuinely mechanical.
 *
 * Declarations are made from a module's onBoot callback, which is the stage at
 * which every module is registered. That matters for relations that cross a
 * module boundary: a module can relate its own model to a shared one only once
 * the shared module exists.
 */
final class RelationManager
{
    /** @var array<class-string<Model>, array<string, Relation>> */
    private array $relations = [];

    /**
     * Declare one or more relations for a model.
     *
     * @param class-string<Model> $model
     */
    public function declare(string $model, Relation ...$relations): void
    {
        if (!\is_a($model, Model::class, true)) {
            throw ModelException::notAModel($model);
        }

        foreach ($relations as $relation) {
            $this->relations[$model][$relation->name] = $relation;
        }
    }

    /**
     * @param class-string<Model> $model
     *
     * @return array<string, Relation>
     */
    public function for(string $model): array
    {
        return $this->relations[$model] ?? [];
    }

    /** @param class-string<Model> $model */
    public function has(string $model, string $relation): bool
    {
        return isset($this->relations[$model][$relation]);
    }

    /** @param class-string<Model> $model */
    public function get(string $model, string $relation): Relation
    {
        return $this->relations[$model][$relation] ?? throw ModelException::unknownRelation(
            $model,
            $relation,
            \implode(', ', \array_keys($this->for($model))),
        );
    }

    /**
     * The distinct local-key values a batch load needs.
     *
     * Feed these straight into a single "WHERE foreign_key IN (...)" query.
     * Nulls are dropped, because a parent with no key has nothing to match.
     *
     * @return list<int|string>
     */
    public function keysFor(ModelCollection $parents, string $relation): array
    {
        $declaration = $this->get($parents->type(), $relation);
        $keys = [];

        foreach ($parents as $parent) {
            /** @var mixed $key */
            $key = $parent->attribute($declaration->localKey);

            if (\is_int($key) || \is_string($key)) {
                $keys[$key] = $key;
            }
        }

        return \array_values($keys);
    }

    /**
     * Attach already-loaded children to their parents.
     *
     * Every parent is attached to, including the ones with nothing to attach:
     * a to-one relation with no match gets null and a to-many gets an empty
     * collection. That is what makes related() able to tell "loaded, empty"
     * apart from "never loaded" -- without it the distinction is lost and the
     * loud error this design depends on becomes a false alarm.
     */
    public function link(ModelCollection $parents, string $relation, ModelCollection $children): void
    {
        $declaration = $this->get($parents->type(), $relation);

        if ($children->type() !== $declaration->related) {
            throw ModelException::relationTypeMismatch($relation, $declaration->related, $children->type());
        }

        $grouped = $this->group($children, $declaration->foreignKey);

        foreach ($parents as $parent) {
            /** @var mixed $key */
            $key = $parent->attribute($declaration->localKey);
            $matches = (\is_int($key) || \is_string($key)) ? ($grouped[$key] ?? []) : [];

            $parent->attachRelated(
                $relation,
                $declaration->isMany()
                    ? ModelCollection::of($declaration->related, $matches)
                    : ($matches[0] ?? null),
            );
        }
    }

    /**
     * Record that a relation was loaded and found nothing, without a query.
     *
     * A repository that already knows a set is empty -- no parent had a key,
     * say -- can say so rather than issuing a query to prove it.
     */
    public function linkEmpty(ModelCollection $parents, string $relation): void
    {
        $declaration = $this->get($parents->type(), $relation);

        $this->link($parents, $relation, ModelCollection::empty($declaration->related));
    }

    /**
     * @return array<int|string, list<Model>>
     */
    private function group(ModelCollection $children, string $foreignKey): array
    {
        $grouped = [];

        foreach ($children as $child) {
            /** @var mixed $key */
            $key = $child->attribute($foreignKey);

            if (\is_int($key) || \is_string($key)) {
                $grouped[$key][] = $child;
            }
        }

        return $grouped;
    }
}
