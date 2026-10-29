<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Schema;

use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;

/**
 * The customer contract, in both directions.
 *
 * Two things to notice. The first is that this is not a class anybody extends
 * and not something a handler signature pulls in automatically: it is a plain
 * class with static factories returning a value, and the API handler calls it
 * where it chooses to.
 *
 * The second is that the resource contract is derived from the input one rather
 * than written out again. A customer that comes back has everything a customer
 * that goes in has, plus an identity, so saying that once is both shorter and
 * impossible to get out of step.
 */
final class CustomerSchema
{
    /** What a client may send to create a customer. */
    public static function input(): Schema
    {
        return Schema::of(
            'customer.input',
            Field::string('name')
                ->length(1, 120)
                ->describedAs('The customer name, as it appears on documents.'),
            Field::string('email')
                ->check(
                    'an email address',
                    // The engine ships no email rule on purpose. One line here
                    // beats a validator vocabulary nobody can finish.
                    static fn(mixed $value): bool => \is_string($value)
                        && \filter_var($value, \FILTER_VALIDATE_EMAIL) !== false,
                )
                ->describedAs('Where correspondence is sent.'),
        );
    }

    /** What a customer looks like on the way back out. */
    public static function resource(): Schema
    {
        return self::input()
            ->with(
                Field::int('id'),
                Field::string('owner')->optional()->nullable()->describedAs('The account owner, if one is set.'),
            )
            ->named('customer.resource');
    }
}
