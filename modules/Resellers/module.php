<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\Identity;
use App\Engine\Cli\CommandCollector;
use App\Engine\Cli\Output;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Localization\Localization;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Kind;
use App\Modules\Resellers\Http\ResellerApi;
use App\Modules\Resellers\Http\ResellerPages;
use App\Modules\Resellers\Resellers;

/**
 * Resellers: their allowance (accounts, disk, bandwidth, overselling) and ACL,
 * managed by root and administrators. The rules are enforced in Resellers.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Resellers')
        ->version('0.1.0')
        ->description('Resellers, their allowance and what they may do.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Resellers::class);
        $services->bind(ResellerPages::class);
        $services->bind(ResellerApi::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('resellers.manage', 'Create resellers and set their allowance and what they may do.');
        $access->capability('resellers.login_as', 'Sign in to the Admin panel as a reseller.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/admin/resellers', static function (RouteCollector $routes): void {
            $routes->get('', [ResellerPages::class, 'index'])->name('');
            $routes->post('', [ResellerPages::class, 'create'])->name('.create');
            $routes->get('/{id}', [ResellerPages::class, 'show'])->name('.show')->where('id', '\d+');
            $routes->post('/{id}/limits', [ResellerPages::class, 'limits'])->name('.limits')->where('id', '\d+');
            $routes->post('/{id}/active', [ResellerPages::class, 'setActive'])->name('.active')->where('id', '\d+');
            $routes->post('/{id}/password', [ResellerPages::class, 'password'])->name('.password')->where('id', '\d+');
            $routes->post('/{id}/delete', [ResellerPages::class, 'delete'])->name('.delete')->where('id', '\d+');
        }, name: 'admin.resellers', meta: ['can' => 'resellers.manage']);

        $routes->group('/admin/api/v1/resellers', static function (RouteCollector $routes): void {
            $user = '[a-z][a-z0-9._-]{2,31}';
            $routes->get('', [ResellerApi::class, 'index'])->name('');
            $routes->post('', [ResellerApi::class, 'create'])->name('.create');
            $routes->get('/{username}', [ResellerApi::class, 'show'])->name('.show')->where('username', $user);
            $routes->put('/{username}/limits', [ResellerApi::class, 'limits'])->name('.limits')->where('username', $user);
        }, name: 'api.resellers', meta: ['can' => 'resellers.manage', 'csrf' => false, 'rate_limit' => '120/1m']);
    });

    $module->commands(static function (CommandCollector $commands): void {
        $commands->add('resellers:list', static function (Output $output, Resellers $resellers): int {
            $output->line(sprintf('%-20s %-9s %-14s %-16s %-16s %s', 'USERNAME', 'ACTIVE', 'ACCOUNTS', 'DISK MB', 'BANDWIDTH MB', 'OVERSELL'));

            foreach ($resellers->all() as $r) {
                $l = $r['limits'];
                $u = $r['usage'];
                $output->line(sprintf(
                    '%-20s %-9s %-14s %-16s %-16s %s',
                    $r['username'],
                    $r['active'] ? 'yes' : 'no',
                    $u['accounts'] . '/' . ($l['max_accounts'] ?: '∞'),
                    $u['disk_mb'] . '/' . ($l['disk_mb'] ?: '∞'),
                    $u['bandwidth_mb'] . '/' . ($l['bandwidth_mb'] ?: '∞'),
                    $l['oversell'] ? 'yes' : 'no',
                ));
            }

            return 0;
        })->describe('List resellers with their allowance and what they use of it.');

        $limitOptions = ['accounts' => 'How many accounts (0: unlimited).', 'disk' => 'Disk allowance in MB (0: unlimited).', 'bandwidth' => 'Monthly bandwidth allowance in MB (0: unlimited).'];

        $create = $commands->add('resellers:create', static function (Output $output, ResellerPages $pages, Resellers $resellers, AuditLog $audit, Localization $localization, string $username, ?string $name = null, ?string $email = null, ?string $accounts = null, ?string $disk = null, ?string $bandwidth = null, bool $oversell = false): int {
            [$clean, $errors] = $pages->validateNew([
                'username' => $username, 'name' => $name ?? '', 'email' => $email ?? '', 'password' => rtrim((string) fgets(\STDIN), "\r\n"),
                'max_accounts' => $accounts ?? '0', 'disk_mb' => $disk ?? '0', 'bandwidth_mb' => $bandwidth ?? '0', 'oversell' => $oversell,
            ]);

            if ($errors !== []) {
                foreach ($errors as $field => $key) {
                    $output->error($field . ': ' . $localization->get($key, ['min' => 12]));
                }

                return 2;
            }

            $id = $resellers->create($clean['username'], $clean['name'], $clean['email'], $clean['password'], $clean['limits']);
            $audit->record('reseller.create', null, null, $clean['username'], AuditLog::OK, ['id' => $id, 'via' => 'cli'] + $clean['limits']);
            $output->success(sprintf('Reseller "%s" created (id %s).', $clean['username'], $id));

            return 0;
        })->describe('Create a reseller; the password is read from standard input.')
            ->argument('username', 'The login name.')
            ->option('name', 'Their name.')
            ->option('email', 'Their email address.')
            ->flag('oversell', 'Let their packages add up to more disk and bandwidth than the allowance.');

        $limits = $commands->add('resellers:limits', static function (Output $output, Resellers $resellers, AuditLog $audit, string $username, ?string $accounts = null, ?string $disk = null, ?string $bandwidth = null, ?string $oversell = null, ?string $acl = null): int {
            $reseller = $resellers->findByUsername($username);

            if ($reseller === null) {
                $output->error(sprintf('There is no reseller named %s.', $username));

                return 1;
            }

            $input = array_filter([
                'max_accounts' => $accounts, 'disk_mb' => $disk, 'bandwidth_mb' => $bandwidth,
                'oversell' => $oversell === null ? null : in_array($oversell, ['1', 'yes', 'on', 'true'], true),
                'acl' => $acl === null ? null : array_filter(array_map('trim', explode(',', $acl))),
            ], static fn(mixed $v): bool => $v !== null) + $reseller['limits'];
            [$clean, $errors] = $resellers->validateLimits($input);

            if ($errors !== []) {
                $output->error('Limits are whole numbers; 0 means unlimited.');

                return 2;
            }

            $resellers->saveLimits((int) $reseller['id'], $clean);
            $audit->record('reseller.limits', null, null, $username, AuditLog::OK, ['via' => 'cli'] + $clean);
            $output->success(sprintf('Limits of %s saved.', $username));

            return 0;
        })->describe('Change a reseller\'s allowance or ACL; options left out keep their value.')
            ->argument('username', 'The reseller.')
            ->option('oversell', 'yes or no.')
            ->option('acl', 'Comma-separated: ' . implode(',', Resellers::ACL) . '.');

        foreach ($limitOptions as $option => $text) {
            $create->option($option, $text);
            $limits->option($option, $text);
        }
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'admin', 'section' => 'Panel.section_people', 'key' => 'resellers', 'label' => 'Resellers.resellers', 'icon' => 'users', 'route' => 'admin.resellers', 'can' => 'resellers.manage', 'order' => 9];

        return $items;
    });

    $module->onBoot(static function (Resellers $resellers, FilterEngine $filters): void {
        $filters->add('authorization.decision', [$resellers, 'enforceAcl'], 20, 'Resellers', 3);
        $filters->add('accounts.limits', [$resellers, 'checkAccount'], 10, 'Resellers', 4);
        $filters->add('packages.validate', [$resellers, 'checkPackage'], 10, 'Resellers', 4);

        // A reseller's dashboard shows their allowance.
        $filters->add('panel.dashboard', static function (array $cards, string $area, Identity $identity) use ($resellers): array {
            if ($area === 'admin' && $identity->attribute('kind') === Kind::Reseller->value) {
                $reseller = $resellers->find($identity->id);

                if ($reseller !== null) {
                    $cards[] = ['template' => '@Resellers/card', 'order' => 5, 'width' => 'full', 'data' => ['r' => $reseller]];
                }
            }

            return $cards;
        }, 6, 'Resellers', 3);
    });
};
