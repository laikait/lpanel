<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\Output;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Localization\Localization;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Http\PackageApi;
use App\Modules\Packages\Http\PackagePages;
use App\Modules\Packages\Packages;

/**
 * Hosting packages: disk, bandwidth and count limits plus a feature list.
 * The server's packages belong to root and administrators; each reseller has
 * their own.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Packages')
        ->version('0.1.0')
        ->description('Hosting packages: limits and feature lists.');

    $module->requires('Panel', '^0.1')->requires('Audit', '^0.1')->requires('Identity', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Packages::class);
        $services->bind(PackagePages::class);
        $services->bind(PackageApi::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('packages.manage', 'Create and change hosting packages.');
        $access->capability('packages.all', 'See and change every package, not only your own.');

        // One per feature. Owners and sub-users hold feature.*; the Subusers
        // module narrows it to the account's package and the sub-user's grants.
        foreach (Packages::FEATURES as $feature) {
            $access->capability('feature.' . $feature, 'Use the "' . $feature . '" feature of the hosting account.');
        }
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/admin/packages', static function (RouteCollector $routes): void {
            $routes->get('', [PackagePages::class, 'index'])->name('');
            $routes->post('', [PackagePages::class, 'create'])->name('.create');
            $routes->get('/{id}', [PackagePages::class, 'edit'])->name('.edit')->where('id', '\d+');
            $routes->post('/{id}', [PackagePages::class, 'update'])->name('.update')->where('id', '\d+');
            $routes->post('/{id}/delete', [PackagePages::class, 'delete'])->name('.delete')->where('id', '\d+');
        }, name: 'admin.packages', meta: ['can' => 'packages.manage']);

        // Bearer tokens only: csrf => false makes the framework ignore the
        // session cookie on these routes.
        $routes->group('/admin/api/v1/packages', static function (RouteCollector $routes): void {
            $routes->get('', [PackageApi::class, 'index'])->name('');
            $routes->post('', [PackageApi::class, 'create'])->name('.create');
            $routes->get('/{id}', [PackageApi::class, 'show'])->name('.show')->where('id', '\d+');
        }, name: 'api.packages', meta: ['can' => 'packages.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('packages:list', static function (Output $output, Packages $packages, PanelUsers $users): int {
            $output->line(sprintf('%-5s %-24s %-12s %10s %10s %8s', 'ID', 'NAME', 'OWNER', 'DISK MB', 'BW MB', 'DOMAINS'));

            foreach ($packages->visibleTo($users->root()->identity) as $p) {
                $owner = $p['owner_id'] === null ? '(server)' : (string) ($users->row((string) $p['owner_id'])['username'] ?? '?');
                $output->line(sprintf('%-5d %-24s %-12s %10s %10s %8s', $p['id'], $p['name'], $owner, $p['disk_mb'] ?: '∞', $p['bandwidth_mb'] ?: '∞', $p['domains'] ?: '∞'));
            }

            return 0;
        })->describe('List every hosting package.');

        $create = $commands->add('packages:create', static function (Output $output, Packages $packages, PanelUsers $users, AuditLog $audit, Localization $localization, string $name, ?string $owner = null, ?string $features = null, ?string $disk = null, ?string $inodes = null, ?string $bandwidth = null, ?string $domains = null, ?string $subdomains = null, ?string $mailboxes = null, ?string $databases = null, ?string $ftp = null, ?string $bandwidthAction = null): int {
            $ownerId = null;

            if ($owner !== null) {
                $row = $users->find($owner);

                if ($row === null || $row['kind'] !== 'reseller') {
                    $output->error('--owner must name a reseller.');

                    return 2;
                }

                $ownerId = (int) $row['id'];
            }

            $input = [
                'name' => $name, 'disk_mb' => $disk, 'inodes' => $inodes, 'bandwidth_mb' => $bandwidth, 'domains' => $domains,
                'subdomains' => $subdomains, 'mailboxes' => $mailboxes, 'databases' => $databases, 'ftp_accounts' => $ftp,
                'bandwidth_action' => $bandwidthAction,
                'features' => $features === null ? Packages::DEFAULT_FEATURES : array_filter(array_map('trim', explode(',', $features))),
            ];
            [$clean, $errors] = $packages->validate($input, $ownerId);

            if ($errors !== []) {
                foreach ($errors as $field => $key) {
                    $output->error($field . ': ' . $localization->get($key));
                }

                return 2;
            }

            $id = $packages->create($clean, $ownerId);
            $audit->record('package.create', null, null, $clean['name'], AuditLog::OK, ['id' => $id, 'via' => 'cli'] + $clean);
            $output->success(sprintf('Package "%s" created (id %s).', $clean['name'], $id));

            return 0;
        })->describe('Create a hosting package. Limits are numbers; 0 or nothing means unlimited.')
            ->argument('name', 'The package name.')
            ->option('owner', 'The reseller who owns it (default: the server).')
            ->option('features', 'Comma-separated: ' . implode(',', Packages::FEATURES) . ' (default: all but ssh).')
            ->option('bandwidth-action', 'When the month\'s bandwidth is used: block (the sites stop until next month; default) or warn.');

        foreach (['disk' => 'Disk space in MB.', 'inodes' => 'Files and folders.', 'bandwidth' => 'Monthly bandwidth in MB.', 'domains' => 'Domains.', 'subdomains' => 'Subdomains.', 'mailboxes' => 'Mailboxes.', 'databases' => 'Databases.', 'ftp' => 'FTP accounts.'] as $option => $text) {
            $create->option($option, $text);
        }
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_hosting', 'key' => 'packages', 'label' => 'Packages.packages', 'icon' => 'box', 'route' => 'admin.packages', 'can' => 'packages.manage', 'order' => 6];

        return $items;
    });
};
