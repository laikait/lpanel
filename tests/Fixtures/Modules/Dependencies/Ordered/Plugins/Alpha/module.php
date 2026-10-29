<?php

declare(strict_types=1);

use App\Engine\Module\ModuleContext;

/**
 * Alphabetically first, and required to wait.
 *
 * Without the declaration this module would register before Zulu, and its
 * listener below would run first at equal priority. The test asserts the
 * opposite, which is the dependency doing its job.
 */
return static function (ModuleContext $module): void {
    $module->name('Alpha')->version('1.0.0');

    $module->requires('Zulu', '^1.0');

    $module->hook('fixture.ping', static function (): void {
        $_SERVER['fixture.order'][] = 'Alpha';
    });
};
