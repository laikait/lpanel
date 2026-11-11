<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Engine\Core\Application;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionManager;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Security\Secret;
use App\Modules\Accounts\Accounts;
use App\Modules\Accounts\Provisioner;
use App\Modules\Packages\Packages;

/**
 * Packages, accounts and sign-ins for feature tests of hosting. The using
 * test sets $app (with a FakeServer agent) in setUp().
 *
 * @property Application $app
 */
trait HostingFixtures
{
    protected function signedIn(string $area, string $username, string $password): Browser
    {
        $browser = new Browser($this->app);
        $browser->signIn($area, $username, $password);

        return $browser;
    }

    /** @param list<string> $features */
    protected function packageFor(?int $owner, string $name, int $diskMb = 1024, array $features = ['email'], int $bandwidthMb = 0): int
    {
        $packages = $this->app->container()->get(Packages::class);
        [$clean, $errors] = $packages->validate(['name' => $name, 'disk_mb' => $diskMb, 'bandwidth_mb' => $bandwidthMb, 'features' => $features], $owner);
        self::assertSame([], $errors, 'the fixture package is valid');

        return (int) $packages->create($clean, $owner);
    }

    /** @return array<string, mixed> the account's row */
    protected function makeAccount(string $username, int $package, ?int $owner): array
    {
        $root = $this->app->container()->get(\App\Modules\Identity\PanelUsers::class)->root()->identity;
        $outcome = $this->app->container()->get(Provisioner::class)->create($root, null, [
            'username' => $username, 'domain' => $username . '.example.com', 'email' => '', 'name' => '',
            'password' => new Secret($username . '-owner-password'), 'owner_id' => $owner, 'package_id' => $package,
        ]);
        self::assertSame('done', $outcome->status, 'the fixture account was made');

        return $this->account($username);
    }

    /** @return array<string, mixed> */
    protected function account(string $username): array
    {
        return $this->app->container()->get(Accounts::class)->findByUsernameUnscoped($username) ?? self::fail('no account ' . $username);
    }

    /** @param array<int|string, mixed>|null $body */
    protected function api(string $method, string $path, string $token, ?array $body = null): Response
    {
        return $this->app->handle(Request::create($method, $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => $body === null ? '' : \json_encode($body, \JSON_THROW_ON_ERROR),
        ]));
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        $data = \json_decode($response->body(), true);

        return \is_array($data) ? $data : self::fail('not JSON: ' . $response->body());
    }

    /** @return list<array<string, mixed>> the audit log, oldest first */
    protected function auditRows(): array
    {
        return \array_values($this->db()->table('audit_log')->orderBy('id')->get());
    }

    protected function db(): Connection
    {
        return $this->app->container()->get(ConnectionManager::class)->connection();
    }
}
