<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;
use App\Tests\Fixtures\Modules\Plugins\Alpha\Handlers\Recorder;

return static function (ModuleContext $module): void {
    $module->name('Beta');

    $module->onBoot(static function (Recorder $recorder): void {
        $recorder->booted[] = 'Beta';
    });
};
