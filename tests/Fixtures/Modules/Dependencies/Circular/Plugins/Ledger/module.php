<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

return static function (ModuleContext $module): void {
    $module->name('Ledger')->version('1.0.0');

    $module->requires('Invoices');
};
