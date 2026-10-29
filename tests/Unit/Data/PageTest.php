<?php

declare(strict_types=1);

namespace App\Tests\Unit\Data;

use App\Engine\Data\Page;
use App\Tests\Support\TestCase;

final class PageTest extends TestCase
{
    public function test_a_page_reports_its_position_within_the_whole(): void
    {
        $page = new Page(['d', 'e', 'f'], total: 10, page: 2, perPage: 3);

        self::assertCount(3, $page);
        self::assertSame(4, $page->from());
        self::assertSame(6, $page->to());
        self::assertSame(4, $page->pages());
        self::assertTrue($page->hasMore());
        self::assertFalse($page->isFirst());
        self::assertFalse($page->isLast());
    }

    public function test_the_first_and_last_pages_know_which_they_are(): void
    {
        $first = new Page(['a', 'b'], total: 4, page: 1, perPage: 2);
        $last = new Page(['c', 'd'], total: 4, page: 2, perPage: 2);

        self::assertTrue($first->isFirst());
        self::assertFalse($first->isLast());
        self::assertFalse($last->isFirst());
        self::assertTrue($last->isLast());
        self::assertFalse($last->hasMore());
    }

    public function test_a_partial_last_page_still_counts_as_a_page(): void
    {
        $page = new Page(['g'], total: 7, page: 3, perPage: 3);

        self::assertSame(3, $page->pages());
        self::assertSame(7, $page->from());
        self::assertSame(7, $page->to());
    }

    public function test_an_empty_page_reports_nothing_rather_than_a_range(): void
    {
        $page = new Page([], total: 0, page: 1, perPage: 10);

        self::assertTrue($page->isEmpty());
        self::assertSame(0, $page->from());
        self::assertSame(0, $page->to());
        self::assertSame(0, $page->pages());
        self::assertFalse($page->hasMore());
    }

    public function test_it_is_iterable(): void
    {
        $seen = [];

        foreach (new Page(['a', 'b'], 2, 1, 2) as $item) {
            $seen[] = $item;
        }

        self::assertSame(['a', 'b'], $seen);
    }

    /** For already-loaded items. To avoid loading them, ask for read models. */
    public function test_mapping_keeps_the_metadata(): void
    {
        $mapped = (new Page([1, 2], total: 9, page: 1, perPage: 2))
            ->map(static fn(mixed $n): string => 'n' . (\is_int($n) ? $n : 0));

        self::assertSame(['n1', 'n2'], $mapped->items());
        self::assertSame(9, $mapped->total);
        self::assertSame(5, $mapped->pages());
    }

    public function test_the_metadata_is_ready_for_a_response(): void
    {
        self::assertSame(
            ['total' => 9, 'page' => 2, 'per_page' => 4, 'pages' => 3, 'count' => 4],
            (new Page(['a', 'b', 'c', 'd'], total: 9, page: 2, perPage: 4))->meta(),
        );
    }
}
