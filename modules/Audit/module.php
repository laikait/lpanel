<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Audit\AuditLog;
use App\Modules\Audit\AuthEvents;
use App\Modules\Audit\Commands\PruneAudit;
use App\Modules\Audit\Http\AuditApi;
use App\Modules\Audit\Http\AuditPage;
use App\Modules\Shared\Ui\Navigation;

/**
 * The audit trail: who did what, when, from where, and whether it worked.
 *
 * Kept for Audit.retention_days (365, product owner 2026-10-27) and then
 * deleted by a daily schedule. Distinct from the application log on purpose:
 * this is evidence, that is debugging.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Audit')
        ->version('0.1.0')
        ->description('Append-only audit events, their API and their retention.');

    $module->config(['retention_days' => 365]);

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(AuditLog::class);
        $services->singleton(AuthEvents::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('audit.read', 'Read the audit log.');
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('audit:prune', PruneAudit::class)
            ->describe('Delete audit events older than Audit.retention_days.');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('audit:prune')->dailyAt('03:30');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->get('/audit', AuditPage::class)->name('audit.index')->meta(['can' => 'audit.read']);
        $routes->get('/api/v1/audit', AuditApi::class)
            ->name('api.v1.audit.index')
            ->meta(['api' => true, 'can' => 'audit.read']);
        // Bearer tokens only; see Identity's /api/token/v1 group for why CSRF is off.
        $routes->get('/api/token/v1/audit', AuditApi::class)
            ->name('api.token.v1.audit.index')
            ->meta(['api' => true, 'csrf' => false, 'can' => 'audit.read', 'rate_limit' => '120/1m']);
    });

    $module->onBoot(static function (AuthEvents $events, HookEngine $hooks, Navigation $nav): void {
        $events->listen($hooks);
        $nav->add('admin', 'audit', 'Audit log', 'audit.index', 'activity', can: 'audit.read', order: 30);
    });
};
