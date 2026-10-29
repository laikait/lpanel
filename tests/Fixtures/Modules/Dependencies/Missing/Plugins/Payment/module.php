<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/** Requires a module that is not installed. */
return static function (ModuleContext $module): void {
    $module->name('Payment')->version('1.0.0');

    $module->requires('Billing', '^1.0');
};
