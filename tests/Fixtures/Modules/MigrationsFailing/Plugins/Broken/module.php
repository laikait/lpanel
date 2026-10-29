<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/** A migration that fails halfway, to see what each database keeps of it. */
return static function (ModuleContext $module): void {
    $module->name('Broken')->version('1.0.0');
};
