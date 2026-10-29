<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Support\Path;
use App\Tests\Support\TestCase;

final class PathTest extends TestCase
{
    public function test_normalize_converts_backslashes(): void
    {
        self::assertSame('C:/xampp/htdocs', Path::normalize('C:\\xampp\\htdocs'));
    }

    public function test_normalize_collapses_repeated_separators(): void
    {
        self::assertSame('/a/b/c', Path::normalize('/a//b///c'));
    }

    public function test_normalize_strips_a_trailing_separator(): void
    {
        self::assertSame('/a/b', Path::normalize('/a/b/'));
    }

    public function test_normalize_preserves_root_paths(): void
    {
        self::assertSame('/', Path::normalize('/'));
        self::assertSame('C:/', Path::normalize('C:\\'));
    }

    public function test_join_uses_a_single_separator(): void
    {
        self::assertSame('/a/b/c', Path::join('/a/', '/b/', 'c'));
    }

    public function test_join_skips_empty_segments(): void
    {
        self::assertSame('/a/b', Path::join('/a', '', 'b', ''));
    }

    public function test_join_with_only_a_base_returns_the_normalized_base(): void
    {
        self::assertSame('/a/b', Path::join('/a/b/', ''));
    }

    public function test_is_absolute_recognises_posix_and_windows_roots(): void
    {
        self::assertTrue(Path::isAbsolute('/var/www'));
        self::assertTrue(Path::isAbsolute('C:\\xampp'));
        self::assertFalse(Path::isAbsolute('engine/Support'));
    }

    public function test_within_accepts_a_real_child(): void
    {
        self::assertTrue(Path::within($this->basePath(), $this->basePath('engine/Support/Path.php')));
    }

    public function test_within_accepts_the_root_itself(): void
    {
        self::assertTrue(Path::within($this->basePath(), $this->basePath()));
    }

    public function test_within_rejects_a_traversal_escape(): void
    {
        self::assertFalse(Path::within($this->basePath('engine'), $this->basePath('engine/../composer.json')));
    }

    public function test_within_rejects_a_path_that_does_not_exist(): void
    {
        self::assertFalse(Path::within($this->basePath(), $this->basePath('does/not/exist')));
    }

    public function test_within_rejects_a_sibling_with_a_shared_prefix(): void
    {
        self::assertFalse(Path::within($this->basePath('engine'), $this->basePath('engine-other')));
    }
}
