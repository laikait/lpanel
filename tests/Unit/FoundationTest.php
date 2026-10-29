<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Engine\Support\Path;
use App\Tests\Support\TestCase;

/**
 * Phase 0 definition of done: composer installs, PHPUnit runs, PSR-4 resolves,
 * and what composer.json claims about the platform is actually true here.
 *
 * The platform assertions deliberately read the manifest rather than hard-coding
 * a version, so they catch drift between what we require and what we run on.
 */
final class FoundationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = \file_get_contents($this->basePath('composer.json'));
        self::assertIsString($json);

        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, string> */
    private function requirements(): array
    {
        $manifest = $this->manifest();
        self::assertArrayHasKey('require', $manifest);
        self::assertIsArray($manifest['require']);

        /** @var array<string, string> $require */
        $require = $manifest['require'];

        return $require;
    }

    public function test_the_runtime_satisfies_the_declared_php_constraint(): void
    {
        $require = $this->requirements();

        self::assertArrayHasKey('php', $require);

        $minimum = \ltrim($require['php'], '^~>=');
        self::assertTrue(
            \version_compare(\PHP_VERSION, $minimum, '>='),
            \sprintf('composer.json requires php %s, running %s', $require['php'], \PHP_VERSION),
        );
    }

    public function test_every_declared_extension_is_loaded(): void
    {
        $extensions = [];

        foreach (\array_keys($this->requirements()) as $package) {
            if (\str_starts_with($package, 'ext-')) {
                $extensions[] = \substr($package, 4);
            }
        }

        self::assertNotEmpty($extensions, 'composer.json should declare its extension requirements');

        foreach ($extensions as $extension) {
            self::assertTrue(
                \extension_loaded($extension),
                \sprintf('ext-%s is required by composer.json but is not loaded', $extension),
            );
        }
    }

    public function test_engine_classes_autoload_under_the_app_engine_namespace(): void
    {
        self::assertSame('App\Engine\Support\Path', Path::class);
        self::assertFileExists($this->basePath('engine/Support/Path.php'));
    }

    public function test_the_helpers_file_is_autoloaded(): void
    {
        /** @var array<string, string> $autoload */
        $autoload = require $this->basePath('vendor/composer/autoload_files.php');

        $files = \array_map(
            static fn(string $file): string => Path::normalize($file),
            \array_values($autoload),
        );

        self::assertContains(Path::join($this->basePath(), 'engine/Support/helpers.php'), $files);
    }
}
