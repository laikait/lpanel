<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Model\Model;
use App\Engine\Model\ReadModel;
use App\Tests\Fixtures\Model\Customer;
use App\Tests\Fixtures\Model\CustomerListRecord;
use App\Tests\Support\TestCase;

final class ReadModelTest extends TestCase
{
    public function test_a_read_model_exposes_its_columns(): void
    {
        $record = new CustomerListRecord(1, 'Ada Lovelace', 'ada@example.test');

        self::assertSame(['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.test'], $record->toArray());
    }

    /**
     * A projection built for output is allowed to be output. A domain entity is
     * not, which is why Model deliberately lacks this.
     */
    public function test_a_read_model_is_serialisable(): void
    {
        $record = new CustomerListRecord(1, 'Ada Lovelace', 'ada@example.test');

        self::assertInstanceOf(\JsonSerializable::class, $record);
        self::assertSame(
            '{"id":1,"name":"Ada Lovelace","email":"ada@example.test"}',
            \json_encode($record, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_read_model_is_not_a_model(): void
    {
        $ancestors = \array_values(\class_parents(CustomerListRecord::class) ?: []);

        // Its only ancestor is ReadModel, so nothing can save it, mark it
        // clean, or attach a relation to it.
        self::assertSame([ReadModel::class], $ancestors);
        self::assertNotContains(Model::class, $ancestors);
    }

    public function test_the_base_class_contributes_nothing_to_the_output(): void
    {
        $methods = \get_class_methods(ReadModel::class);

        \sort($methods);

        self::assertSame(['jsonSerialize', 'toArray'], $methods);
    }

    public function test_a_domain_model_projects_into_one(): void
    {
        $customer = new Customer(7, 'Grace Hopper', 'grace@example.test', ownerId: 3, active: false, balance: 12.5);

        // The projection is explicit and drops what the list screen does not
        // need, which is the whole reason to have it.
        self::assertSame(
            ['id' => 7, 'name' => 'Grace Hopper', 'email' => 'grace@example.test'],
            CustomerListRecord::from($customer)->toArray(),
        );
    }
}
