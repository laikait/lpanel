<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Update\Manifest;
use App\Engine\Update\UpdateException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ManifestTest extends UpdateTestCase
{
    public function test_a_release_tree_is_recorded_with_hashes_and_roles(): void
    {
        $directory = $this->tree('release', [
            'engine/Http/Request.php' => '<?php // request',
            'laika' => '#!/usr/bin/env php',
            'modules/Shared/module.php' => '<?php // shared',
            'templates/home.twig' => 'home',
            'public/index.php' => '<?php // front',
            'public/assets/css/app.css' => 'body {}',
            'composer.json' => '{"require": {"php": "^8.2"}}',
            'system/Cache/.gitignore' => '*',
            '.env.example' => 'APP_ENV=production',
        ]);

        $manifest = Manifest::build($directory, '3.1.0');

        self::assertSame('3.1.0', $manifest->version);
        self::assertSame(\hash('sha256', '<?php // request'), $manifest->hash('engine/Http/Request.php'));
        self::assertSame(Manifest::OWNED, $manifest->role('engine/Http/Request.php'));
        self::assertSame(Manifest::OWNED, $manifest->role('laika'));
        self::assertSame(Manifest::OWNED, $manifest->role('public/index.php'));
        self::assertSame(Manifest::OWNED, $manifest->role('.env.example'));
        self::assertSame(Manifest::SEED, $manifest->role('modules/Shared/module.php'));
        self::assertSame(Manifest::SEED, $manifest->role('templates/home.twig'));
        self::assertSame(Manifest::SEED, $manifest->role('public/assets/css/app.css'));
        self::assertSame(Manifest::MERGED, $manifest->role('composer.json'));
        self::assertFalse($manifest->has('system/Cache/.gitignore'), 'system/ is never shipped by an update');
        self::assertSame(['require' => ['php' => '^8.2']], $manifest->composer);
    }

    public function test_it_survives_a_round_trip(): void
    {
        $directory = $this->tree('release', ['engine/A.php' => 'a', 'lang/en.php' => 'en', 'composer.json' => '{}']);
        $manifest = Manifest::build($directory, '3.1.0');
        $manifest->write($this->root . '/framework.json');

        $read = Manifest::read($this->root . '/framework.json');

        self::assertSame($manifest->files, $read->files);
        self::assertSame('3.1.0', $read->version);
        self::assertSame($manifest->toJson(), $read->toJson());
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'parent directory' => ['../outside.php'],
            'nested parent' => ['engine/../../outside.php'],
            'absolute' => ['/etc/passwd'],
            'windows drive' => ['C:/x.php'],
            'backslash' => ['engine\\x.php'],
            'vendor' => ['vendor/autoload.php'],
            'system' => ['system/Cache/config.php'],
            'git' => ['.git/config'],
            'env' => ['.env'],
            'empty segment' => ['engine//x.php'],
        ];
    }

    /** A manifest is downloaded; a path in it must never reach outside the application. */
    #[DataProvider('unsafePaths')]
    public function test_a_manifest_naming_an_unsafe_path_is_refused(string $path): void
    {
        $json = \json_encode(['version' => '3.1.0', 'composer' => [], 'files' => [$path => ['sha256' => \str_repeat('a', 64), 'role' => 'owned']]]);

        $this->expectException(UpdateException::class);
        Manifest::fromJson((string) $json);
    }

    public function test_a_malformed_manifest_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        Manifest::fromJson('{"version": "3.1.0", "files": {"engine/A.php": {"sha256": "nope", "role": "owned"}}}');
    }
}
