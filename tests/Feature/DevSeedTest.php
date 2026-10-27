<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Modules\Identity\Commands\DevSeed;
use App\Modules\Identity\Users\UserStore;
use App\Tests\Support\ApplicationTestCase;

/** dev:seed's passwords are public, so it must never run outside local and testing. */
final class DevSeedTest extends ApplicationTestCase
{
    private function seed(string $env): int
    {
        $config = $this->service(Config::class);
        $seed = new DevSeed($this->service(UserStore::class), $this->service(Password::class), new Config(['app' => ['env' => $env]] + $config->all()));
        $stream = \fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        return $seed(new Output($stream, $stream));
    }

    public function test_it_creates_the_sample_accounts_once(): void
    {
        self::assertSame(0, $this->seed('testing'));
        self::assertSame(0, $this->seed('testing'), 'a second run leaves them alone');

        self::assertSame(303, $this->browser()->post('/login', ['login' => 'admin', 'password' => DevSeed::ACCOUNTS['admin'][3]])->status());
        self::assertSame(['customer'], $this->service(UserStore::class)->byLogin('customer')?->effectiveRoles());
    }

    public function test_it_refuses_in_production(): void
    {
        self::assertSame(1, $this->seed('production'));
        self::assertNull($this->service(UserStore::class)->byLogin('admin'));
    }
}
