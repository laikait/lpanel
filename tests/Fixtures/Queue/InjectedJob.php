<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Queue;

use App\Engine\Config\Config;
use App\Engine\Queue\Job;

/**
 * A job whose handle() takes a collaborator.
 *
 * The shape the framework asks for: data in the constructor, dependencies in
 * handle(), injected out of the container of whichever process runs it.
 */
final class InjectedJob implements Job
{
    public static ?string $sawEnvironment = null;

    public function __construct(private readonly int $id = 1) {}

    public function handle(Config $config): void
    {
        self::$sawEnvironment = $config->string('app.env') . ':' . $this->id;
    }

    public static function reset(): void
    {
        self::$sawEnvironment = null;
    }
}
