<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\Recorder;

return static function (ModuleContext $module): void {
    $module->name('Zeta Gateway');

    // A gateway observing an event a different module owns: this is the
    // cross-module extension the hook engine exists for.
    $module->hook('order.recorded', static function (string $what) use ($module): void {
        $_SERVER['zeta.saw'] = $what . '@' . $module->id();
    }, priority: 50);

    $module->onBoot(static function (Recorder $recorder): void {
        $recorder->booted[] = 'Zeta';
    });
};
