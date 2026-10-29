<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Gateways\ExampleGateway\Hooks;

use App\Engine\Model\Model;

/**
 * A gateway observing an event owned by a different module.
 *
 * Neither module references the other. That is the point of hooks being the
 * primary extension mechanism: a gateway can react to a customer being created
 * without the customer module knowing gateways exist.
 *
 * Note the parameter type. The gateway takes the engine's Model, not the
 * plugin's Customer, so it can read the identity of a thing it has no business
 * knowing the class of.
 */
final class AuditHooks
{
    /** @var list<string> */
    public static array $records = [];

    public static function recordCustomer(Model $customer): void
    {
        $identity = $customer->identity();

        self::$records[] = \sprintf('customer.created:%s', $identity ?? '?');
    }

    public static function reset(): void
    {
        self::$records = [];
    }
}
