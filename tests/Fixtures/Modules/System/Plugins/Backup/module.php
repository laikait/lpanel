<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Module\ModuleContext;
use App\Engine\System\Security\SystemCapability;

/**
 * A module that owns WHAT -- "make a backup" -- and asks engine/System for HOW.
 *
 * It declares the system capabilities its service checks, and a role that
 * grants them. It registers no system manager of its own: the configured ones
 * are injected, with the application's policies, limits and audit applied.
 */
return static function (ModuleContext $module): void {
    $module->name('Backup');

    $module->config(['directory' => null]);

    $module->access(static function (AccessCollector $access): void {
        SystemCapability::declare($access, SystemCapability::FilesystemWrite, SystemCapability::CommandExecute);

        $access->role('backup-operator', [
            SystemCapability::FilesystemWrite->value,
            SystemCapability::CommandExecute->value,
        ]);
    });
};
