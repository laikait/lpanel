<?php

declare(strict_types=1);

namespace App\Engine\System\Security;

use App\Engine\Auth\AccessCollector;

/**
 * Every capability a system operation can require, named.
 *
 * These are ordinary capabilities in the auth layer's sense -- dotted nouns,
 * granted by roles, checked by Authorizer, printed by `auth:access` -- and not a
 * second permission system. What this enum adds is the list, spelled once, and
 * a description for each that says what granting it really means.
 *
 * **Nothing is declared unless a module asks.** An application that uses no
 * system operation should not see system.* in its access list, so the module
 * that enforces them declares the ones it checks:
 *
 *     $module->access(static function (AccessCollector $access): void {
 *         SystemCapability::declare($access, SystemCapability::ServiceRead, SystemCapability::ServiceRestart);
 *         $access->role('operator', [SystemCapability::ServiceRead->value, SystemCapability::ServiceRestart->value]);
 *     });
 *
 * **Grant them one at a time.** The auth model allows `system.*` and `*`, and
 * both would include system.shell.execute; a role that holds either can run
 * whatever the application's shell scripts do.
 */
enum SystemCapability: string
{
    case CommandExecute = 'system.command.execute';
    case ShellExecute = 'system.shell.execute';

    case ProcessStart = 'system.process.start';
    case ProcessTerminate = 'system.process.terminate';

    case CronRead = 'system.cron.read';
    case CronManage = 'system.cron.manage';

    case ServiceRead = 'system.service.read';
    case ServiceStart = 'system.service.start';
    case ServiceStop = 'system.service.stop';
    case ServiceRestart = 'system.service.restart';
    case ServiceReload = 'system.service.reload';
    case ServiceEnable = 'system.service.enable';
    case ServiceDisable = 'system.service.disable';

    case FilesystemRead = 'system.filesystem.read';
    case FilesystemWrite = 'system.filesystem.write';
    case FilesystemDelete = 'system.filesystem.delete';

    case PermissionChmod = 'system.permission.chmod';
    case PermissionChown = 'system.permission.chown';

    case InfoRead = 'system.info.read';

    /** What granting this lets somebody do, for a role screen and `auth:access`. */
    public function description(): string
    {
        return match ($this) {
            self::CommandExecute => 'Run the programs the application runs on the server.',
            self::ShellExecute => 'Run the application\'s shell scripts on the server, with whatever those scripts can do.',
            self::ProcessStart => 'Start long-running programs on the server.',
            self::ProcessTerminate => 'Stop programs the application started on the server.',
            self::CronRead => 'See the application\'s scheduled jobs in the server crontab.',
            self::CronManage => 'Install and remove the application\'s jobs in the server crontab.',
            self::ServiceRead => 'See whether server services are running.',
            self::ServiceStart => 'Start server services the policy allows.',
            self::ServiceStop => 'Stop server services the policy allows, which takes them offline.',
            self::ServiceRestart => 'Restart server services the policy allows.',
            self::ServiceReload => 'Reload the configuration of server services the policy allows.',
            self::ServiceEnable => 'Make server services the policy allows start at boot.',
            self::ServiceDisable => 'Stop server services the policy allows from starting at boot.',
            self::FilesystemRead => 'Read files in the server directories the policy allows.',
            self::FilesystemWrite => 'Create and change files in the server directories the policy allows.',
            self::FilesystemDelete => 'Delete files in the server directories the policy allows.',
            self::PermissionChmod => 'Change file permissions in the server directories the policy allows.',
            self::PermissionChown => 'Change the owner or group of files in the server directories the policy allows.',
            self::InfoRead => 'See the server\'s operating system, kernel, memory, disks and load.',
        };
    }

    /** Declare these capabilities as the calling module's, with their descriptions. */
    public static function declare(AccessCollector $access, self $capability, self ...$more): void
    {
        foreach ([$capability, ...$more] as $each) {
            $access->capability($each->value, $each->description());
        }
    }
}
