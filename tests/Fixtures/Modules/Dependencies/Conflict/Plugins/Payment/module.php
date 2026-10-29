<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/** Written against Billing 2.x; the installed Billing is 1.4.0. */
return static function (ModuleContext $module): void {
    $module->name('Payment')->version('1.0.0');

    $module->requires('Billing', '^2.0');
};
