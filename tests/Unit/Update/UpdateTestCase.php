<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Update\Manifest;
use App\Engine\Update\Release;
use App\Tests\Support\TestCase;

/**
 * Small trees on disk: an installed application, the release it came from,
 * and the release it is updated to.
 */
abstract class UpdateTestCase extends TestCase
{
    protected string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/lphp-update-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root);
    }

    protected function tearDown(): void
    {
        self::delete($this->root);

        parent::tearDown();
    }

    /** @param array<string, string> $files path => contents */
    protected function tree(string $name, array $files): string
    {
        $directory = $this->root . '/' . $name;

        foreach ($files as $path => $contents) {
            if (!\is_dir(\dirname($directory . '/' . $path))) {
                \mkdir(\dirname($directory . '/' . $path), 0o755, true);
            }

            \file_put_contents($directory . '/' . $path, $contents);
        }

        if (!\is_dir($directory)) {
            \mkdir($directory);
        }

        return $directory;
    }

    /** @param array<string, string> $files */
    protected function release(string $name, string $version, array $files): Release
    {
        $directory = $this->tree($name, $files);
        Manifest::build($directory, $version)->write($directory . '/' . Manifest::FILE);

        return Release::open($directory);
    }

    /** @return array<string, string> path => contents, for every file under $directory */
    protected function contents(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $found[\substr(\str_replace('\\', '/', $file->getPathname()), \strlen($directory) + 1)] = (string) \file_get_contents($file->getPathname());
            }
        }

        \ksort($found);

        return $found;
    }

    protected static function delete(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            \unlink($path);

            return;
        }

        foreach (\scandir($path) ?: [] as $child) {
            if ($child !== '.' && $child !== '..') {
                self::delete($path . '/' . $child);
            }
        }

        if (\is_dir($path)) {
            \rmdir($path);
        }
    }
}
