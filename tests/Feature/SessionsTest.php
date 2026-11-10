<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Core\Application;
use App\Engine\Security\Secret;
use App\Modules\Identity\Kind;
use App\Modules\Identity\PanelUsers;
use App\Modules\Resellers\Resellers;
use App\Tests\Support\FakeServer;
use App\Tests\Support\HostingFixtures;
use App\Tests\Support\TestCase;

/**
 * Session epochs ("sign out everywhere") and "log in as".
 */
final class SessionsTest extends TestCase
{
    use HostingFixtures;

    private Application $app;

    private PanelUsers $users;

    private int $rex;

    private int $rita;

    protected function setUp(): void
    {
        $this->app = $this->app();
        $this->rootPassword($this->app, 'root-password-here');
        $this->agent($this->app, (new FakeServer())->agent());
        $this->users = $this->app->container()->get(PanelUsers::class);
        $this->users->create(Kind::Admin, 'alice', 'Alice', null, new Secret('alice-password-123'));
        $resellers = $this->app->container()->get(Resellers::class);
        [$all] = $resellers->validateLimits(['acl' => Resellers::ACL]);
        $this->rex = (int) $resellers->create('rex', 'Rex', null, new Secret('rex-password-1234'), $all);
        $this->rita = (int) $resellers->create('rita', 'Rita', null, new Secret('rita-password-123'), $all);
    }

    public function test_a_new_password_ends_every_other_session_but_this_one(): void
    {
        $here = $this->signedIn('admin', 'alice', 'alice-password-123');
        $laptop = $this->signedIn('admin', 'alice', 'alice-password-123');

        $here->post('/admin/security/password', ['current_password' => 'alice-password-123', 'new_password' => 'a-brand-new-password', 'repeat_password' => 'a-brand-new-password']);

        self::assertSame(200, $here->get('/admin')->status());
        self::assertSame(302, $laptop->get('/admin')->status());
        self::assertSame(303, $this->browser($this->app)->signIn('admin', 'alice', 'a-brand-new-password')->status());
    }

    public function test_sign_out_everywhere_else(): void
    {
        $here = $this->signedIn('admin', 'alice', 'alice-password-123');
        $phone = $this->signedIn('admin', 'alice', 'alice-password-123');

        $here->post('/admin/security/sessions');

        self::assertSame(200, $here->get('/admin')->status());
        self::assertSame(302, $phone->get('/admin')->status());
        self::assertContains('auth.sign_out_elsewhere', \array_column($this->auditRows(), 'action'));
    }

    public function test_a_password_reset_by_an_administrator_signs_the_login_out(): void
    {
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $root->post('/admin/resellers/' . $this->rex . '/password', ['password' => 'reset-by-root-123']);

        self::assertSame(302, $rex->get('/admin')->status());
    }

    public function test_root_logs_in_as_a_reseller_and_returns(): void
    {
        $root = $this->signedIn('admin', 'root', 'root-password-here');

        $start = $root->post('/admin/login-as/' . $this->rex);
        $dashboard = $root->get('/admin')->body();

        self::assertSame(303, $start->status());
        self::assertStringContainsString('You are signed in as rex. You are really root', $dashboard);
        self::assertStringContainsString('Your allowance', $dashboard);

        // Changes are recorded as rex, on behalf of root.
        $package = $root->post('/admin/packages', ['name' => 'Made by support', 'disk_mb' => '100', 'features' => ['email']]);
        $row = \array_values(\array_filter($this->auditRows(), static fn(array $r): bool => $r['action'] === 'package.create'))[0];
        self::assertSame(303, $package->status());
        self::assertSame('rex', $row['actor_name']);
        self::assertSame('root', $row['on_behalf_of']);

        // Rex's own password, two-factor and tokens are rex's.
        self::assertSame(403, $root->post('/admin/security/password', ['current_password' => 'x', 'new_password' => 'y', 'repeat_password' => 'y'])->status());
        self::assertSame(403, $root->post('/admin/tokens', ['name' => 'backdoor', 'days' => '30'])->status());

        $back = $root->post('/admin/login-as/return');
        $after = $root->get('/admin')->body();

        self::assertSame('/admin', $back->header('Location'));
        self::assertStringNotContainsString('You are signed in as', $after);
        self::assertStringContainsString('Welcome, root', $after);
        self::assertSame(['auth.login_as', 'auth.login_as_end'], \array_values(\array_intersect(\array_column($this->auditRows(), 'action'), ['auth.login_as', 'auth.login_as_end'])));
    }

    public function test_a_reseller_logs_in_as_their_customer_with_a_one_time_ticket(): void
    {
        $shop = $this->makeAccount('shop', $this->packageFor($this->rex, 'Starter'), $this->rex);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');

        $start = $rex->post('/admin/login-as/' . $shop['login_id']);
        $ticketUrl = (string) $start->header('Location');

        self::assertSame(303, $start->status());
        self::assertMatchesRegularExpression('#^/panel/login-as\?ticket=[0-9a-f]{64}$#', $ticketUrl);

        // On a server the User panel is another port, so another browser session.
        $customer = $this->browser($this->app);
        $landed = $customer->get($ticketUrl);
        $dashboard = $customer->get('/panel')->body();

        self::assertSame('/panel', $landed->header('Location'));
        self::assertStringContainsString('shop.example.com', $dashboard);
        self::assertStringContainsString('You are signed in as shop. You are really rex', $dashboard);

        // Once only.
        $again = $this->browser($this->app)->get($ticketUrl);
        self::assertSame('/panel/login', $again->header('Location'));

        $end = $customer->post('/panel/login-as/end');
        self::assertSame('/admin', $end->header('Location'));
        self::assertSame(302, $customer->get('/panel')->status());
    }

    public function test_an_expired_ticket_is_refused(): void
    {
        $shop = $this->makeAccount('shop', $this->packageFor($this->rex, 'Starter'), $this->rex);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $ticketUrl = (string) $rex->post('/admin/login-as/' . $shop['login_id'])->header('Location');
        $this->db()->table('login_handoffs')->updateAll(['expires_at' => \gmdate('Y-m-d H:i:s', \time() - 1)]);

        self::assertSame('/panel/login', $this->browser($this->app)->get($ticketUrl)->header('Location'));
    }

    public function test_who_may_become_whom(): void
    {
        $theirs = $this->makeAccount('cafe', $this->packageFor($this->rita, 'Rita Basic'), $this->rita);
        $rex = $this->signedIn('admin', 'rex', 'rex-password-1234');
        $alice = $this->signedIn('admin', 'alice', 'alice-password-123');
        $aliceId = (string) ($this->users->find('alice') ?? self::fail('no alice'))['id'];
        $rootId = (string) $this->users->root()->identity->id;

        self::assertSame(404, $rex->post('/admin/login-as/' . $theirs['login_id'])->status(), 'another reseller\'s customer');
        self::assertSame(404, $rex->post('/admin/login-as/' . $this->rita)->status(), 'another reseller');
        self::assertSame(404, $alice->post('/admin/login-as/' . $rootId)->status(), 'root');
        self::assertSame(404, $alice->post('/admin/login-as/' . $aliceId)->status(), 'yourself');
        self::assertSame(303, $alice->post('/admin/login-as/' . $theirs['login_id'])->status(), 'an administrator, any customer');

        // Without the ACL entry, a reseller cannot log in as even their own customer.
        $mine = $this->makeAccount('shop', $this->packageFor($this->rex, 'Starter'), $this->rex);
        $resellers = $this->app->container()->get(Resellers::class);
        [$limited] = $resellers->validateLimits(['acl' => ['accounts.create']]);
        $resellers->saveLimits($this->rex, $limited);
        self::assertSame(404, $rex->post('/admin/login-as/' . $mine['login_id'])->status());
    }
}
