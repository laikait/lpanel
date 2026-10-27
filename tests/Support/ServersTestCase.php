<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Modules\Servers\Agent\OperationKey;
use App\Modules\Servers\Store\Server;
use App\Modules\Servers\Store\ServerStore;

/**
 * A panel that is ready to pair servers: an agent URL, panel Nginx's
 * certificate (tests/Fixtures/lpa/certs/panel.pem, so a known pin) and a
 * fresh operation-signing key in a temporary directory.
 */
abstract class ServersTestCase extends ApplicationTestCase
{
    /** OpenSSL's answer for panel.pem, not this code's. */
    protected const PANEL_PIN = 'sha256/uWs5MRbGDLmCp-6h-Ff_rbVRIKZs3HVZc5JFdyRgO84';

    private ?string $keyDir = null;

    private bool $booted = false;

    /**
     * The panel, booted once. Overrides boot a new one, with a new database.
     *
     * @param array<string, mixed> $servers Servers.* overrides
     */
    protected function panel(array $servers = []): Application
    {
        if ($this->booted && $servers === []) {
            return $this->app();
        }

        $this->booted = true;
        $this->keyDir ??= \sys_get_temp_dir() . '/lpanel-test-' . \bin2hex(\random_bytes(6));
        $key = $this->keyDir . '/operations.key';

        if (!\is_file($key)) {
            OperationKey::generate($key);
        }

        return $this->app(['Servers' => $servers + [
            'agent_url' => 'https://panel.test:8443',
            'tls_certificate' => \dirname(__DIR__) . '/Fixtures/lpa/certs/panel.pem',
            'operation_key' => $key,
        ]]);
    }

    protected function agent(string $cert = 'agent-a', string $ip = '198.51.100.20'): FakeAgent
    {
        return new FakeAgent($this->panel(), $cert, $ip);
    }

    /** @return array{Server, string} a pending server and a pairing token for it */
    protected function pendingServer(string $name = 'web-01'): array
    {
        $this->panel();
        $store = $this->service(ServerStore::class);
        $server = $store->create($name, null);

        return [$server, $store->createEnrollment($server, null, 15)];
    }

    /** An agent that has paired. */
    protected function enrolledAgent(string $cert = 'agent-a'): FakeAgent
    {
        [, $token] = $this->pendingServer();
        $agent = $this->agent($cert);
        self::assertSame(200, $agent->enroll($token)->status());

        return $agent;
    }

    protected function server(): Server
    {
        $all = $this->service(ServerStore::class)->all();
        self::assertNotEmpty($all);

        return $all[0];
    }

    protected function tearDown(): void
    {
        if ($this->keyDir !== null) {
            @\unlink($this->keyDir . '/operations.key');
            @\rmdir($this->keyDir);
        }

        parent::tearDown();
    }
}
