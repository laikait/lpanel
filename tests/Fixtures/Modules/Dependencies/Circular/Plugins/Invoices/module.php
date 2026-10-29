<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/** Ledger needs Invoices and Invoices needs Ledger: no order exists. */
return static function (ModuleContext $module): void {
    $module->name('Invoices')->version('1.0.0');

    $module->requires('Ledger');
};
