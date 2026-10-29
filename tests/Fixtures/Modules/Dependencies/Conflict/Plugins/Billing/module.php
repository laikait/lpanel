<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

return static function (ModuleContext $module): void {
    $module->name('Billing')->version('1.4.0');
};
