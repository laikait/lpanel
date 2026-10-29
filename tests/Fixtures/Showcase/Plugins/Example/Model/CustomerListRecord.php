<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Model;

use App\Engine\Model\ReadModel;

/**
 * What a customer looks like on a list screen.
 *
 * Three columns, no behaviour, no relations, no change tracking. A list of ten
 * thousand of these costs a fraction of ten thousand domain models, and the
 * projection is written out where it can be seen rather than inferred from
 * whichever fields the serialiser happened to reach.
 */
final class CustomerListRecord extends ReadModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}

    public static function from(Customer $customer): self
    {
        return new self($customer->identity() ?? 0, $customer->name(), $customer->email());
    }
}
