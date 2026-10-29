<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/**
 * Its invoices point at Customers' table, so it requires Customers -- and that
 * is what puts its migrations after Customers', although "Billing" sorts first.
 */
return static function (ModuleContext $module): void {
    $module->name('Billing')->version('1.0.0');
    $module->requires('Customers', '^1.0');
};
