<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Http\Request;
use App\Engine\Security\Secret;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;
use App\Tests\Support\Browser;

/** Authorization is enforced on the server, per capability, and root alone makes administrators. */
final class UsersApiTest extends ApplicationTestCase
{
    private const PASSWORD = 'a long enough password';

    private function as(string $login, string $role): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create($login, \ucfirst($login), null, $hash, [$role]);
        $browser = $this->browser();
        self::assertSame(303, $browser->post('/login', ['login' => $login, 'password' => self::PASSWORD])->status());

        return $browser;
    }

    private function asRoot(): Browser
    {
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        return $browser;
    }

    /**
     * @param list<string> $roles
     *
     * @return array<string, mixed>
     */
    private static function newUser(string $login, array $roles): array
    {
        return ['login' => $login, 'name' => 'New ' . $login, 'password' => 'another long password', 'roles' => $roles];
    }

    public function test_listing_users_needs_the_capability(): void
    {
        self::assertSame(401, $this->browser()->get('/api/v1/users', ['Accept' => 'application/json'])->status());
        self::assertSame(403, $this->as('cara', 'customer')->get('/api/v1/users', ['Accept' => 'application/json'])->status());

        $list = $this->as('adam', 'admin')->json('/api/v1/users');
        self::assertSame(['adam', 'cara'], \array_column($list['data'], 'login'));
        foreach ($list['data'] as $user) {
            self::assertArrayNotHasKey('password_hash', $user);
        }
    }

    public function test_an_admin_creates_customers_but_not_admins(): void
    {
        $admin = $this->as('adam', 'admin');

        self::assertSame(201, $admin->postJson('/api/v1/users', self::newUser('carl', ['customer']))->status());

        $refused = $admin->postJson('/api/v1/users', self::newUser('eve', ['admin']));
        self::assertSame(403, $refused->status());
        self::assertNull($this->service(UserStore::class)->byLogin('eve'));
        self::assertSame('denied', $this->service(AuditLog::class)->latest()[0]['outcome']);
    }

    public function test_root_creates_admins(): void
    {
        $created = $this->asRoot()->postJson('/api/v1/users', self::newUser('ann', ['admin']));

        self::assertSame(201, $created->status());
        self::assertSame(['admin'], $this->service(UserStore::class)->byLogin('ann')?->effectiveRoles());
    }

    public function test_root_and_unknown_roles_cannot_be_assigned(): void
    {
        $root = $this->asRoot();

        self::assertSame(400, $root->postJson('/api/v1/users', self::newUser('mallory', ['root']))->status());
        self::assertSame(400, $root->postJson('/api/v1/users', self::newUser('mallory', ['superuser']))->status());
        self::assertSame(409, $root->postJson('/api/v1/users', self::newUser('root', ['customer']))->status());
    }

    public function test_extra_fields_cannot_escalate(): void
    {
        $payload = self::newUser('sam', ['customer']) + ['kind' => 'system', 'status' => 'active', 'roles_extra' => ['root']];
        self::assertSame(201, $this->asRoot()->postJson('/api/v1/users', $payload)->status());

        $sam = $this->service(UserStore::class)->byLogin('sam');
        self::assertSame('panel', $sam?->kind);
        self::assertSame(['customer'], $sam->effectiveRoles());
    }

    public function test_a_duplicate_login_is_a_conflict_and_validation_is_reported_per_field(): void
    {
        $root = $this->asRoot();
        $root->postJson('/api/v1/users', self::newUser('dup', ['customer']));

        self::assertSame(409, $root->postJson('/api/v1/users', self::newUser('dup', ['customer']))->status());

        $bad = $root->postJson('/api/v1/users', ['login' => 'X', 'name' => '', 'password' => 'short', 'roles' => []]);
        self::assertSame(400, $bad->status());
        $fields = \json_decode($bad->body(), true)['error']['fields'] ?? [];
        self::assertSame(['login', 'name', 'password', 'roles'], \array_keys($fields));
    }

    public function test_a_json_mutation_without_its_csrf_header_is_refused(): void
    {
        // A signed-in admin's session exists, but this request, like a forged
        // cross-site one, carries no X-CSRF-TOKEN.
        $this->as('adam', 'admin');
        $response = $this->app()->handle(Request::create('POST', '/api/v1/users', [
            'body' => \json_encode(self::newUser('x', ['customer']), \JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]));

        self::assertSame(403, $response->status());
    }
}
