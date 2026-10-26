<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Config\Config;
use App\Engine\System\Command\CommandExecutor;
use App\Tests\Support\ApplicationTestCase;

/** ADR-0005 and THREAT_MODEL.md test 8: the panel has no command runner to reach. */
final class SystemDisabledTest extends ApplicationTestCase
{
    public function test_the_system_layer_is_switched_off(): void
    {
        self::assertFalse($this->service(Config::class)->bool('system.enabled', true));

        $this->expectException(\Throwable::class);
        $this->app()->container()->get(CommandExecutor::class);
    }

    public function test_no_module_uses_the_system_layer(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath('modules'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php'
                && \str_contains((string) \file_get_contents($file->getPathname()), 'App\\Engine\\System\\')) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders, 'Modules must not use engine/System: every system change is an LPA operation.');
    }
}
