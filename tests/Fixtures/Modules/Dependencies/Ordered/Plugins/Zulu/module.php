<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

return static function (ModuleContext $module): void {
    $module->name('Zulu')->version('1.2.0');

    $module->hook('fixture.ping', static function (): void {
        $_SERVER['fixture.order'][] = 'Zulu';
    });
};
