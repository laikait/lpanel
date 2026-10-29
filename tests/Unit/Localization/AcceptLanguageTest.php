<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Localization\AcceptLanguage;
use App\Tests\Support\TestCase;

final class AcceptLanguageTest extends TestCase
{
    public function test_entries_are_sorted_by_quality(): void
    {
        self::assertSame(
            ['en-US', 'en', 'bn'],
            AcceptLanguage::parse('bn;q=0.8,en-US,en;q=0.9'),
        );
    }

    public function test_a_missing_quality_is_one_and_ties_keep_the_clients_order(): void
    {
        self::assertSame(['de-DE', 'de', 'en'], AcceptLanguage::parse('de-DE, de, en'));
    }

    public function test_tags_are_normalized_and_duplicates_dropped(): void
    {
        self::assertSame(['bn-BD', 'bn'], AcceptLanguage::parse('BN-bd,bn;q=0.9,bn-bd;q=0.5'));
    }

    public function test_zero_quality_means_not_acceptable(): void
    {
        self::assertSame(['en'], AcceptLanguage::parse('fr;q=0,en;q=0.1'));
    }

    public function test_a_malformed_quality_drops_the_entry(): void
    {
        self::assertSame(['en'], AcceptLanguage::parse('fr;q=high,de;q=1.5,bn;q=-1,en'));
    }

    public function test_other_parameters_are_ignored(): void
    {
        self::assertSame(['en'], AcceptLanguage::parse('en;level=1'));
    }

    public function test_wildcards_and_invalid_tags_are_ignored(): void
    {
        self::assertSame(['en'], AcceptLanguage::parse('*,../../etc,<script>,en'));
    }

    public function test_nothing_usable_is_an_empty_list(): void
    {
        self::assertSame([], AcceptLanguage::parse(null));
        self::assertSame([], AcceptLanguage::parse(''));
        self::assertSame([], AcceptLanguage::parse(';;;,,,=q'));
    }

    public function test_an_oversized_header_is_bounded(): void
    {
        $header = \implode(',', \array_fill(0, 5000, 'fr')) . ',en';

        self::assertSame(['fr'], AcceptLanguage::parse($header));
        self::assertSame([], AcceptLanguage::parse(\str_repeat('a', 100_000)));
    }

    public function test_only_the_first_twenty_entries_are_read(): void
    {
        $header = \implode(',', \array_fill(0, 20, 'xx-invalid')) . ',en';

        self::assertSame([], AcceptLanguage::parse($header));
    }
}
