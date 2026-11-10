<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Audit\AuditListeners;
use App\Modules\Audit\AuditLog;
use App\Modules\Audit\Http\AuditPages;

/**
 * The audit log: every change and every sign-in, kept for a year.
 *
 * Other modules call AuditLog::record() for their own changes. This module
 * also listens to the framework's sign-in failures, which no handler sees.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Audit')
        ->version('0.1.0')
        ->description('The audit log of changes and sign-ins.');

    $module->requires('Panel', '^0.1');

    $module->config(['retention_days' => 365]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(AuditLog::class);
        $services->singleton(AuditListeners::class);
        $services->bind(AuditPages::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('audit.view', 'See every entry in the audit log.');
        $access->capability('audit.own', 'See your own entries in the audit log.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/admin/audit', [AuditPages::class, 'admin'])->name('admin.audit')->meta(['can' => 'audit.own']);
        $routes->get('/panel/activity', [AuditPages::class, 'user'])->name('user.audit')->meta(['can' => 'audit.own']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('audit:prune', static function (Output $output, AuditLog $log, Config $config): int {
            $days = (int) $config->get('Audit.retention_days', 365);
            $output->line(sprintf('%d entries older than %d days removed.', $log->prune(max(30, $days)), $days));

            return 0;
        })->describe('Remove audit log entries older than Audit.retention_days.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('audit:prune')->dailyAt('03:10');
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_security', 'key' => 'audit', 'label' => 'Audit.audit_log', 'icon' => 'list', 'route' => 'admin.audit', 'can' => 'audit.own', 'order' => 90];
        $items[] = ['area' => 'user', 'section' => 'Panel.section_security', 'key' => 'audit', 'label' => 'Audit.my_activity', 'icon' => 'activity', 'route' => 'user.audit', 'can' => 'audit.own', 'order' => 90];

        return $items;
    });

    // Listeners that need services are added once everything is registered.
    $module->onBoot(static function (AuditListeners $listeners, HookEngine $hooks, FilterEngine $filters): void {
        $hooks->add('auth.failed', [$listeners, 'signInFailed'], 10, 'Audit');
        $hooks->add('auth.throttled', [$listeners, 'throttled'], 10, 'Audit');
        $hooks->add('auth.two_factor_failed', [$listeners, 'codeFailed'], 10, 'Audit');
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 80, 'Audit', 3);
    });
};
