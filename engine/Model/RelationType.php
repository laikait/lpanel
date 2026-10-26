<?php

declare(strict_types=1);

namespace App\Engine\Model;

/**
 * How many related models a relation holds.
 *
 * Two cases, not a vocabulary. There is no hasOne/hasMany/belongsTo/
 * belongsToMany taxonomy here, because those names describe where a foreign key
 * lives, and a Relation already says that outright by naming both keys. What is
 * left is the only thing the framework has to branch on: does the parent end up
 * with one model or a collection.
 */
enum RelationType: string
{
    case One = 'one';
    case Many = 'many';
}
