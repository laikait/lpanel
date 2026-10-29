<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Shared\Schema;

use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;

/**
 * The page and page-size contract every list endpoint shares.
 *
 * This is what belongs in shared: one rule about how large a page may be, in
 * one place, so a new list endpoint cannot quietly allow per_page=100000 and
 * take the database down with it.
 *
 * Note the input this describes. A query string is entirely strings, so
 * "page=2" has to become 2 before anything can compare it to a bound -- which
 * is exactly what a schema does, and why the same declaration serves a query
 * string and a JSON body without knowing which it was given.
 */
final class PaginationSchema
{
    public const MAX_PER_PAGE = 100;

    public static function schema(): Schema
    {
        return Schema::of(
            'pagination',
            Field::int('page')
                ->default(1)
                ->range(1, null)
                ->describedAs('Which page to return, counting from one.'),
            Field::int('per_page')
                ->default(25)
                ->range(1, self::MAX_PER_PAGE)
                ->describedAs('How many records per page.'),
        );
    }
}
