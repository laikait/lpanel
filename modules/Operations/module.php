<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Engine\Scheduler\ScheduleCollector;
use App\Modules\Operations\Commands\CronCommands;
use App\Modules\Operations\Commands\ServiceCommands;
use App\Modules\Operations\Commands\TaskCommands;
use App\Modules\Operations\Cron\CronJobs;
use App\Modules\Operations\Http\CronApi;
use App\Modules\Operations\Http\CronPages;
use App\Modules\Operations\Http\ServiceApi;
use App\Modules\Operations\Http\ServicePages;
use App\Modules\Operations\Http\TaskApi;
use App\Modules\Operations\Http\TaskPages;
use App\Modules\Operations\OperationsListeners;
use App\Modules\Operations\Services\Services;
use App\Modules\Operations\Services\Watchdog;
use App\Modules\Operations\Tasks\Tasks;

/**
 * Operations: looking after the server once it is running. First the services
 * (how each is doing, start, stop, restart, and a watchdog that restarts what
 * falls over); cron jobs, updates, logs and alerts follow.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Operations')
        ->version('0.1.0')
        ->description('Services and the watchdog that keeps them up.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Agent', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1')
        ->requires('Subusers', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Services::class);
        $services->singleton(Watchdog::class);
        $services->singleton(CronJobs::class);
        $services->singleton(Tasks::class);
        $services->bind(TaskPages::class);
        $services->bind(TaskApi::class);
        $services->bind(TaskCommands::class);
        $services->bind(CronPages::class);
        $services->bind(CronApi::class);
        $services->bind(CronCommands::class);
        $services->singleton(OperationsListeners::class);
        $services->bind(ServicePages::class);
        $services->bind(ServiceApi::class);
        $services->bind(ServiceCommands::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('server.services', 'See how the server\'s services are doing, start, stop and restart them, and set which ones the watchdog restarts.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $unit = '[A-Za-z0-9._@-]{1,64}';
        $action = 'start|stop|restart|reload|enable|disable';

        $routes->group('/admin/services', static function (RouteCollector $routes) use ($unit, $action): void {
            $routes->get('', [ServicePages::class, 'index'])->name('');
            $routes->post('/{unit}/watch', [ServicePages::class, 'watch'])->name('.watch')->where('unit', $unit);
            $routes->post('/{unit}/{action}', [ServicePages::class, 'act'])->name('.act')->where('unit', $unit)->where('action', $action);
        }, name: 'admin.services', meta: ['can' => 'server.services']);

        $routes->group('/panel/cron', static function (RouteCollector $routes): void {
            $routes->get('', [CronPages::class, 'index'])->name('');
            $routes->post('', [CronPages::class, 'add'])->name('.add');
            $routes->post('/{id}/delete', [CronPages::class, 'remove'])->name('.delete')->where('id', '\d+');
        }, name: 'user.cron', meta: ['can' => 'feature.cron']);

        $routes->group('/admin/api/v1/accounts/{username}/cron', static function (RouteCollector $routes): void {
            $routes->get('', [CronApi::class, 'index'])->name('');
            $routes->post('', [CronApi::class, 'add'])->name('.add');
            $routes->delete('/{id}', [CronApi::class, 'remove'])->name('.remove')->where('id', '\d+');
        }, name: 'api.cron', meta: ['can' => 'accounts.modify', 'csrf' => false, 'rate_limit' => '60/1m']);

        $routes->get('/admin/tasks', [TaskPages::class, 'index'])->name('admin.tasks')->meta(['can' => 'server.services']);
        $routes->get('/admin/api/v1/tasks', [TaskApi::class, 'index'])->name('api.tasks')->meta(['can' => 'server.services', 'csrf' => false, 'rate_limit' => '60/1m']);

        $routes->group('/admin/api/v1/services', static function (RouteCollector $routes) use ($unit, $action): void {
            $routes->get('', [ServiceApi::class, 'index'])->name('');
            $routes->get('/events', [ServiceApi::class, 'events'])->name('.events');
            $routes->put('/{unit}/watch', [ServiceApi::class, 'watch'])->name('.watch')->where('unit', $unit);
            $routes->post('/{unit}/{action}', [ServiceApi::class, 'act'])->name('.act')->where('unit', $unit)->where('action', $action);
        }, name: 'api.services', meta: ['can' => 'server.services', 'csrf' => false, 'rate_limit' => '60/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('services:status', [ServiceCommands::class, 'status'])
            ->describe('Show the server\'s services: how each is doing, whether it starts at boot, whether the watchdog restarts it.');

        foreach (['start' => 'Start', 'stop' => 'Stop (the watchdog leaves it alone until it is started again)', 'restart' => 'Restart', 'reload' => 'Reload the configuration of', 'enable' => 'Make it start at boot:', 'disable' => 'Stop it starting at boot:'] as $name => $what) {
            $commands->add('services:' . $name, [ServiceCommands::class, $name])
                ->describe($what . ' a service.')
                ->argument('unit', 'The service, such as httpd or exim (see services:status).');
        }

        $commands->add('services:watch', [ServiceCommands::class, 'watch'])
            ->describe('Have the watchdog restart a service when it goes down (or not, with --off).')
            ->argument('unit', 'The service.')
            ->flag('off', 'Stop watching it.');
        $commands->add('cron:list', [CronCommands::class, 'list'])
            ->describe('Show an account\'s scheduled jobs.')
            ->argument('username', 'The account.');
        $commands->add('cron:add', [CronCommands::class, 'add'])
            ->describe('Schedule a command for an account: it runs as the account, at most once in five minutes.')
            ->argument('username', 'The account.')
            ->argument('schedule', 'Five fields in quotes ("*/15 * * * *") or @hourly, @daily, @weekly, @monthly, @yearly.')
            ->argument('command', 'The command, one line.');
        $commands->add('cron:remove', [CronCommands::class, 'remove'])
            ->describe('Remove one of an account\'s scheduled jobs.')
            ->argument('username', 'The account.')
            ->argument('id', 'The job\'s id (see cron:list).');
        $commands->add('tasks:list', [TaskCommands::class, 'list'])
            ->describe('Show what the server\'s agent has done and is doing (newest first).')
            ->option('state', 'running, done, failed or interrupted.')
            ->option('limit', 'How many (default 30).');
        $commands->add('watchdog:run', [ServiceCommands::class, 'watchdog'])
            ->describe('Look at the services once, and restart the ones that fell over.')
            ->option('grace', 'Seconds a service must have been found down before it is restarted (default 20).');
    });

    $module->schedules(static function (ScheduleCollector $schedules): void {
        $schedules->command('watchdog:run')->everyMinute()->withoutOverlapping(600);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'user', 'section' => 'Panel.section_hosting', 'key' => 'cron', 'label' => 'Operations.cron_title', 'icon' => 'clock', 'route' => 'user.cron', 'can' => 'feature.cron', 'order' => 40];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'tasks', 'label' => 'Operations.tasks_title', 'icon' => 'activity', 'route' => 'admin.tasks', 'can' => 'server.services', 'order' => 6];
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_server', 'key' => 'services', 'label' => 'Operations.services_title', 'icon' => 'server', 'route' => 'admin.services', 'can' => 'server.services', 'order' => 5];

        return $items;
    });

    $module->onBoot(static function (OperationsListeners $listeners, FilterEngine $filters, HookEngine $hooks): void {
        $hooks->add('accounts.changed', [$listeners, 'accountChanged'], 40, 'Operations', 2);
        $hooks->add('packages.updated', [$listeners, 'packageUpdated'], 40, 'Operations', 1);
        $filters->add('panel.attention', [$listeners, 'attention'], 5, 'Operations', 3);
        $filters->add('panel.dashboard', [$listeners, 'dashboard'], 60, 'Operations', 3);
    });
};
