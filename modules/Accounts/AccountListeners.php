<?php

declare(strict_types=1);

namespace App\Modules\Accounts;

use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Identity;
use App\Engine\Routing\Router;
use App\Modules\Identity\Kind;

/**
 * Accounts' answers to other modules: dashboard cards, and how many accounts
 * use a package.
 */
final class AccountListeners
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly AccountView $view,
        private readonly Provisioner $provisioner,
        private readonly Authorizer $authorizer,
        private readonly Router $router,
    ) {}

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    public function dashboard(array $cards, string $area, Identity $identity): array
    {
        if ($area === 'admin' && $this->authorizer->allows($identity, 'accounts.manage')) {
            $cards[] = ['template' => '@Accounts/admin-card', 'order' => 10, 'data' => [
                'counts' => $this->accounts->counts($identity),
                'recent' => \array_map($this->view->enrich(...), \array_slice($this->accounts->visibleTo($identity), 0, 5)),
                'link' => $this->router->url('admin.accounts'),
                'new_url' => $this->router->url('admin.accounts.new'),
            ]];
        }

        if ($area === 'user') {
            $account = $this->accountOf($identity);

            if ($account !== null) {
                \array_unshift($cards, ['template' => '@Accounts/user-card', 'order' => 0, 'width' => 'full', 'data' => [
                    'account' => $this->view->enrich($account),
                ]]);
            }
        }

        return $cards;
    }

    public function packageInUse(int $count, int $packageId): int
    {
        return $count + $this->accounts->countOnPackage($packageId);
    }

    /** @param array<string, mixed> $package */
    public function packageUpdated(array $package): void
    {
        $this->provisioner->applyPackage($package);
    }

    /**
     * The hosting account an owner or sub-user login belongs to.
     *
     * @return array<string, mixed>|null
     */
    private function accountOf(Identity $identity): ?array
    {
        $kind = Kind::tryFrom((string) $identity->attribute('kind'));
        $loginId = match ($kind) {
            Kind::User => $identity->id,
            Kind::Subuser => $identity->attribute('parent_id'),
            default => null,
        };

        return \is_string($loginId) && $loginId !== '' ? $this->accounts->byLogin($loginId) : null;
    }
}
