<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Identity;
use App\Modules\Audit\AuditLog;
use App\Tests\Support\ApplicationTestCase;

final class AuditTest extends ApplicationTestCase
{
    public function test_metadata_that_looks_secret_is_dropped(): void
    {
        $audit = $this->service(AuditLog::class);
        $audit->record('test.event', 'succeeded', new Identity('01HZY0000000000000000000AA', 'Ada'), 'thing', '1', [
            'login' => 'ada',
            'password' => 'hunter2',
            'api_token' => 'abc',
            'nested' => ['totp_code' => '123456', 'kept' => true],
        ]);

        self::assertSame(['login' => 'ada', 'nested' => ['kept' => true]], $audit->latest()[0]['metadata']);
    }

    public function test_logins_are_recorded_with_the_actor_and_address(): void
    {
        $this->browser('198.51.100.20')->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);

        $event = $this->service(AuditLog::class)->latest()[0];
        self::assertSame('auth.login', $event['action']);
        self::assertSame('root', $event['actor']['name']);
        self::assertSame('198.51.100.20', $event['ip']);
    }

    public function test_retention_deletes_only_what_is_older(): void
    {
        $audit = $this->service(AuditLog::class);
        $audit->record('old.event', 'succeeded');

        self::assertSame(0, $audit->prune(365));
        self::assertSame(1, $audit->prune(365, new \DateTimeImmutable('+366 days', new \DateTimeZone('UTC'))));
        self::assertSame([], $audit->latest());
    }

    public function test_the_audit_api_needs_audit_read(): void
    {
        self::assertSame(401, $this->browser()->get('/api/v1/audit', ['Accept' => 'application/json'])->status());

        $root = $this->browser();
        $root->post('/login', ['login' => 'root', 'password' => self::ROOT_PASSWORD]);
        $page = $root->json('/api/v1/audit?limit=1');

        self::assertCount(1, $page['data']);
        self::assertSame('auth.login', $page['data'][0]['action']);
        self::assertIsInt($page['meta']['next_before']);
        self::assertSame(400, $root->get('/api/v1/audit?limit=999', ['Accept' => 'application/json'])->status());
    }
}
