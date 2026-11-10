<?php

declare(strict_types=1);

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\Identity;
use App\Engine\Container\ServiceRegistrar;
use App\Engine\Filter\FilterEngine;
use App\Engine\Hook\HookEngine;
use App\Engine\Module\ModuleContext;
use App\Engine\Routing\RouteCollector;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Subusers\Http\SubuserPages;
use App\Modules\Subusers\Subusers;

/**
 * Sub-users: logins an account owner makes for helpers, each granted some of
 * the account's features. Also the gate on feature.* for owners and sub-users.
 */
return static function (ModuleContext $module): void {
    $module
        ->name('Subusers')
        ->version('0.1.0')
        ->description('Sub-users of hosting accounts, and what each may use.');

    $module->requires('Panel', '^0.1')
        ->requires('Audit', '^0.1')
        ->requires('Identity', '^0.1')
        ->requires('Packages', '^0.1')
        ->requires('Accounts', '^0.1');

    $module->services(static function (ServiceRegistrar $services): void {
        $services->singleton(Subusers::class);
        $services->bind(SubuserPages::class);
    });

    $module->access(static function (AccessCollector $access): void {
        $access->capability('subusers.manage', 'Make sub-users of your hosting account and choose what they may use.');
    });

    $module->routes(static function (RouteCollector $routes): void {
        $routes->group('/panel/users', static function (RouteCollector $routes): void {
            $routes->get('', [SubuserPages::class, 'index'])->name('');
            $routes->post('', [SubuserPages::class, 'create'])->name('.create');
            $routes->post('/{id}/grants', [SubuserPages::class, 'grants'])->name('.grants')->where('id', '\d+');
            $routes->post('/{id}/password', [SubuserPages::class, 'password'])->name('.password')->where('id', '\d+');
            $routes->post('/{id}/active', [SubuserPages::class, 'setActive'])->name('.active')->where('id', '\d+');
            $routes->post('/{id}/delete', [SubuserPages::class, 'delete'])->name('.delete')->where('id', '\d+');
        }, name: 'user.subusers', meta: ['can' => 'subusers.manage']);
    });

    $module->filter('panel.nav', static function (array $items): array {
        $items[] = ['area' => 'user', 'section' => 'Panel.section_account', 'key' => 'subusers', 'label' => 'Subusers.users', 'icon' => 'users', 'route' => 'user.subusers', 'can' => 'subusers.manage', 'order' => 50];

        return $items;
    });

    $module->onBoot(static function (Subusers $subusers, PanelUsers $users, FilterEngine $filters, HookEngine $hooks): void {
        $filters->add('authorization.decision', [$subusers, 'enforceFeatures'], 30, 'Subusers', 3);
        $hooks->add('identity.deleted', [$subusers, 'forget'], 10, 'Subusers', 1);

        // A sub-user's dashboard says what they may use, and for whom.
        $filters->add('panel.dashboard', static function (array $cards, string $area, Identity $identity) use ($subusers, $users): array {
            if ($area !== 'user' || $identity->attribute('kind') !== Kind::Subuser->value) {
                return $cards;
            }

            $account = $subusers->accountOf($identity);
            $features = $account === null ? [] : array_values(array_intersect($subusers->grants((int) $identity->id), $subusers->packageFeatures($account)));
            $owner = (string) ($users->row((string) $identity->attribute('parent_id'))['username'] ?? '');
            $cards[] = ['template' => '@Subusers/access-card', 'order' => 15, 'data' => ['features' => $features, 'owner' => $owner]];

            return $cards;
        }, 15, 'Subusers', 3);
    });
};
