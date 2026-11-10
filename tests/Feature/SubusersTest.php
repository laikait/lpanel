<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Authorizer;
use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Packages\Packages;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

final class SubusersTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private PanelUsers $users;

    private int $package;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->agent($this->app, (new FakeServer())->agent());
        $this->users = $this->app->container()->get(PanelUsers::class);
        $this->package = $this->packageFor(null, 'Starter', 1024, ['email', 'databases', 'ftp']);
        $this->makeAccount('shop', $this->package, null);
    }

    public function test_an_owner_makes_a_sub_user_who_gets_only_what_was_granted(): void
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');

        // "dns" is not in the package, so it cannot be granted even if asked for.
        $made = $owner->post('/panel/users', ['suffix' => 'dev', 'name' => 'Dev', 'password' => 'developer-password', 'grants' => ['email', 'dns']]);
        $dev = $this->signedIn('user', 'shop.dev', 'developer-password');

        self::assertSame(303, $made->status());
        self::assertTrue($this->can('shop.dev', 'feature.email'));
        self::assertFalse($this->can('shop.dev', 'feature.databases'), 'in the package, not granted');
        self::assertFalse($this->can('shop.dev', 'feature.dns'), 'not in the package');
        self::assertTrue($this->can('shop', 'feature.databases'), 'the owner has the whole package');
        self::assertFalse($this->can('shop', 'feature.ssh'));
        self::assertStringContainsString('What you can use', $dev->get('/panel')->body());
        self::assertSame(403, $dev->get('/panel/users')->status(), 'a sub-user does not manage users');
        self::assertContains('subuser.create', \array_column($this->auditRows(), 'action'));
    }

    public function test_grants_follow_the_package(): void
    {
        $this->subuser('dev', ['email', 'ftp']);
        self::assertTrue($this->can('shop.dev', 'feature.ftp'));

        $packages = $this->app->container()->get(Packages::class);
        [$clean] = $packages->validate(['name' => 'Starter', 'disk_mb' => 1024, 'features' => ['email']], null, $this->package);
        $packages->update($this->package, $clean);

        self::assertFalse($this->can('shop.dev', 'feature.ftp'), 'the package no longer has it');
        self::assertTrue($this->can('shop.dev', 'feature.email'));
    }

    public function test_the_owner_changes_grants_disables_and_deletes(): void
    {
        $id = $this->subuser('dev', ['email']);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $dev = $this->signedIn('user', 'shop.dev', 'developer-password');

        $owner->post('/panel/users/' . $id . '/grants', ['grants' => ['databases']]);
        self::assertTrue($this->can('shop.dev', 'feature.databases'));
        self::assertFalse($this->can('shop.dev', 'feature.email'));

        $owner->post('/panel/users/' . $id . '/active', ['active' => '0']);
        self::assertSame(302, $dev->get('/panel')->status());

        $owner->post('/panel/users/' . $id . '/delete');
        self::assertNull($this->users->find('shop.dev'));
        self::assertSame([], $this->db()->table('subuser_grants')->where('user_id', (int) $id)->get());
    }

    public function test_an_owner_cannot_touch_another_accounts_sub_users(): void
    {
        $this->makeAccount('cafe', $this->package, null);
        $theirs = $this->users->create(Kind::Subuser, 'cafe.dev', '', null, new Secret('developer-password'), (int) $this->account('cafe')['login_id']);
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');

        self::assertSame(404, $owner->post('/panel/users/' . $theirs . '/delete')->status());
        self::assertNotNull($this->users->find('cafe.dev'));
    }

    public function test_staff_hold_no_features(): void
    {
        $this->users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));

        self::assertFalse($this->can('alice', 'feature.email'));
    }

    /** @param list<string> $grants */
    private function subuser(string $suffix, array $grants): string
    {
        $owner = $this->signedIn('user', 'shop', 'shop-owner-password');
        $owner->post('/panel/users', ['suffix' => $suffix, 'password' => 'developer-password', 'grants' => $grants]);

        return (string) ($this->users->find('shop.' . $suffix)['id'] ?? self::fail('no sub-user'));
    }

    private function can(string $username, string $capability): bool
    {
        $account = $this->users->byLogin($username) ?? self::fail('no login ' . $username);

        return $this->app->container()->get(Authorizer::class)->allows($account->identity, $capability);
    }
}
