<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Showcase\Plugins\Example\Hooks;

use App\Tests\Fixtures\Showcase\Plugins\Example\Model\Customer;

/**
 * This module's own reactions to its own events.
 *
 * A hook is an announcement: return values are ignored, and nothing here can
 * change the customer that was created. The owning module can type the argument
 * as its own model, because it is the module that fired the hook.
 */
final class CustomerHooks
{
    /** @var list<int> ids seen this process, for the demo and its tests */
    public static array $created = [];

    public static function onCreated(Customer $customer): void
    {
        $id = $customer->identity();

        if ($id !== null) {
            self::$created[] = $id;
        }
    }

    public static function reset(): void
    {
        self::$created = [];
    }
}
