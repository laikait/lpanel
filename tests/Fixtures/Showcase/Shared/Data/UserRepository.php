<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Data;

use App\Engine\Data\Repository;
use App\Engine\Model\ModelCollection;
use App\Tests\Fixtures\Showcase\Shared\Model\User;

/**
 * A shared repository.
 *
 * Two declarations and then methods named after what the application asks for.
 * There is no inherited find(), save() or delete(): findAll() is here because
 * relation linking needs exactly this shape -- a set of keys in, everything at
 * once out -- and not because every repository ought to have one.
 */
final class UserRepository extends Repository
{
    protected function model(): string
    {
        return User::class;
    }

    protected function collection(): string
    {
        return 'users';
    }

    /**
     * Every user in one read.
     *
     * This is the far end of a batch relation load: hand it the keys a
     * RelationManager collected and it answers once, instead of a find() called
     * per parent inside a loop.
     *
     * @param list<int|string> $identities
     */
    public function findAll(array $identities): ModelCollection
    {
        if ($identities === []) {
            // An empty IN can never match, and the data layer says so rather
            // than running a read that cannot return anything.
            return ModelCollection::empty(User::class);
        }

        return $this->query()->whereIn('id', $identities)->get();
    }

    public function all(): ModelCollection
    {
        return $this->query()->orderBy('username')->get();
    }
}
