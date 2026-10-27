<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;
use App\Tests\Support\Browser;

/** Bearer tokens: shown once, stored as a fingerprint, answered only on /api/token/v1. */
final class ApiTokenTest extends ApplicationTestCase
{
    private const PASSWORD = 'an admin password';

    private function admin(): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create('ops', 'Ops Bot Owner', null, $hash, ['admin']);
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'ops', 'password' => self::PASSWORD]);

        return $browser;
    }

    private function issue(Browser $browser, string $name = 'deploy', int $days = 90): string
    {
        $page = $browser->post('/account/tokens', ['name' => $name, 'days' => (string) $days]);
        self::assertSame(201, $page->status());
        if (\preg_match('/id="lp-new-token">(lpat_[A-Za-z0-9_-]{43})</', $page->body(), $m) !== 1) {
            self::fail('The new token is not shown.');
        }

        return $m[1];
    }

    private function db(): Connection
    {
        return $this->service(ConnectionManager::class)->connection();
    }

    public function test_a_token_acts_as_its_owner_and_is_stored_only_as_a_fingerprint(): void
    {
        $browser = $this->admin();
        $token = $this->issue($browser);

        $session = $browser->bearer('GET', '/api/token/v1/session', $token);
        self::assertSame(200, $session->status());
        $data = \json_decode($session->body(), true)['data'];
        self::assertSame('api_token', $data['via']);
        self::assertSame('ops', $data['user']['login']);

        $row = $this->db()->table('api_tokens')->first();
        self::assertIsArray($row);
        self::assertSame(\hash('sha256', $token), $row['fingerprint']);
        self::assertStringNotContainsString(\substr($token, 12), \json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertNotNull($row['last_used_at']);

        self::assertStringNotContainsString($token, $browser->get('/account/tokens')->body(), 'never shown again');
    }

    public function test_token_routes_ignore_the_session_cookie(): void
    {
        $browser = $this->admin();

        self::assertSame(401, $browser->get('/api/token/v1/session', ['Accept' => 'application/json'])->status());
        self::assertSame(401, $browser->bearer('GET', '/api/token/v1/users', 'lpat_' . \str_repeat('A', 43), withCookies: true)->status());
    }

    public function test_a_token_can_write_without_csrf_and_its_changes_are_audited_as_the_token(): void
    {
        $token = $this->issue($this->admin());

        $created = $this->browser()->bearer('POST', '/api/token/v1/users', $token, [
            'login' => 'cust1', 'name' => 'Customer One', 'password' => 'customer password 1', 'roles' => ['customer'],
        ]);
        self::assertSame(201, $created->status());

        $admin = $this->browser()->bearer('POST', '/api/token/v1/users', $token, [
            'login' => 'boss', 'name' => 'Boss', 'password' => 'boss password 123', 'roles' => ['admin'],
        ]);
        self::assertSame(403, $admin->status(), 'a token holds its owner\'s rights, and an admin cannot make admins');

        $event = $this->db()->table('audit_events')->where('action', 'user.create')->where('outcome', 'succeeded')->first();
        self::assertIsArray($event);
        self::assertSame('token', $event['actor_type']);
        self::assertStringContainsString('"via":"api:', (string) $event['metadata']);
    }

    public function test_revoked_expired_and_suspended_tokens_stop_working(): void
    {
        $browser = $this->admin();
        $revoked = $this->issue($browser, 'one');
        $expired = $this->issue($browser, 'two');
        $suspended = $this->issue($browser, 'three');

        $uid = $this->db()->table('api_tokens')->where('name', 'one')->first()['uid'] ?? '';
        self::assertSame(303, $browser->post('/account/tokens/' . $uid . '/revoke')->status());
        self::assertSame(401, $this->browser()->bearer('GET', '/api/token/v1/session', $revoked)->status());

        $this->db()->table('api_tokens')->where('name', 'two')->update(['expires_at' => new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC'))]);
        self::assertSame(401, $this->browser()->bearer('GET', '/api/token/v1/session', $expired)->status());

        self::assertSame(200, $this->browser()->bearer('GET', '/api/token/v1/session', $suspended)->status());
        $ops = $this->service(UserStore::class)->byLogin('ops');
        self::assertNotNull($ops);
        $this->service(UserStore::class)->setStatus($ops->uid, 'suspended');
        self::assertSame(401, $this->browser()->bearer('GET', '/api/token/v1/session', $suspended)->status());
    }

    public function test_root_cannot_have_a_token(): void
    {
        $browser = $this->browser();
        $browser->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        self::assertSame(403, $browser->post('/account/tokens', ['name' => 'root token', 'days' => '30'])->status());
        self::assertSame(0, $this->db()->table('api_tokens')->count());
    }

    public function test_a_token_must_have_a_name_and_a_permitted_lifetime(): void
    {
        $browser = $this->admin();

        self::assertSame(422, $browser->post('/account/tokens', ['name' => '', 'days' => '30'])->status());
        self::assertSame(422, $browser->post('/account/tokens', ['name' => 'forever', 'days' => '36500'])->status());
        self::assertSame(0, $this->db()->table('api_tokens')->count());
    }
}
