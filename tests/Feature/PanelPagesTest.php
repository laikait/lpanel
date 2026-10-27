<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;
use App\Tests\Support\Browser;

/** Every screen renders for each kind of person, and shows only what they may use. */
final class PanelPagesTest extends ApplicationTestCase
{
    private const PASSWORD = 'a perfectly fine password';

    /** @param list<string> $roles */
    private function as(string $login, array $roles): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create($login, \ucfirst($login), null, $hash, $roles);
        $browser = $this->browser();
        $browser->post('/login', ['login' => $login, 'password' => self::PASSWORD]);

        return $browser;
    }

    public function test_an_admin_sees_the_whole_panel(): void
    {
        $admin = $this->as('alice', ['admin']);

        foreach (['/', '/users', '/users/new', '/audit', '/audit?group=auth.&outcome=succeeded', '/account/security', '/account/tokens'] as $path) {
            $page = $admin->get($path);
            self::assertSame(200, $page->status(), $path);
            self::assertStringContainsString('class="lp-shell"', $page->body(), $path);
            self::assertStringContainsString('no-store', (string) $page->header('Cache-Control'), $path);
        }

        $home = $admin->get('/')->body();
        self::assertStringContainsString('Recent activity', $home);
        self::assertStringContainsString('>Users</span>', $home, 'the sidebar offers Users');
        self::assertStringContainsString('>Audit log</span>', $home);
        self::assertStringContainsString('Signed in', $admin->get('/audit')->body());
    }

    public function test_a_customer_sees_only_their_side(): void
    {
        $customer = $this->as('carol', ['customer']);
        $home = $customer->get('/');

        self::assertSame(200, $home->status());
        self::assertStringContainsString('Your websites will live here', $home->body());
        self::assertStringNotContainsString('>Users</span>', $home->body());
        self::assertStringNotContainsString('>Audit log</span>', $home->body());
        self::assertSame(403, $customer->get('/audit')->status());
        self::assertSame(200, $customer->get('/account/tokens')->status());
    }

    public function test_root_sees_everything_and_no_password_or_token_forms(): void
    {
        $root = $this->browser();
        $root->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(200, $root->get('/')->status());
        $security = $root->get('/account/security')->body();
        self::assertStringContainsString('passwd', $security);
        self::assertStringNotContainsString('name="current_password"', $security);
        self::assertStringContainsString('Root has no API tokens', $root->get('/account/tokens')->body());
    }

    public function test_the_sign_in_pages_and_errors_render_without_a_session(): void
    {
        $guest = $this->browser();

        foreach (['/login', '/password/forgot'] as $path) {
            $page = $guest->get($path);
            self::assertSame(200, $page->status(), $path);
            self::assertStringContainsString('lp-auth', $page->body(), $path);
        }

        $missing = $guest->get('/no-such-page');
        self::assertSame(404, $missing->status());
        self::assertStringContainsString('That page is not here', $missing->body());
    }

    public function test_the_page_assets_are_served(): void
    {
        foreach (['css/app.css', 'js/app.js', 'js/theme.js'] as $asset) {
            self::assertSame(200, $this->browser()->get('/assets/core/' . $asset)->status(), $asset);
        }
    }
}
