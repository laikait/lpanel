<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/** Owns the customers table, which Billing's invoices point at. */
return static function (ModuleContext $module): void {
    $module->name('Customers')->version('1.0.0');
};
