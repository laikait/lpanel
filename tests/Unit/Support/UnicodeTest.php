<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Support\Unicode;
use App\Tests\Support\TestCase;

final class UnicodeTest extends TestCase
{
    public function test_ascii_is_returned_as_it_is(): void
    {
        self::assertSame('/customers/42', Unicode::nfc('/customers/42'));
    }

    public function test_a_combining_accent_is_composed(): void
    {
        self::assertSame("caf\u{E9}", Unicode::nfc("cafe\u{301}"));
    }

    public function test_composed_text_is_unchanged(): void
    {
        self::assertSame('ঢাকা-শহর', Unicode::nfc('ঢাকা-শহর'));
    }

    /**
     * Bengali য় is one of the characters NFC never composes: both spellings
     * become য followed by the nukta. What matters is that they become the same.
     */
    public function test_both_spellings_of_bengali_ya_become_the_same_bytes(): void
    {
        self::assertSame(Unicode::nfc("\u{09DF}"), Unicode::nfc("\u{09AF}\u{09BC}"));
    }

    public function test_text_that_is_not_utf8_comes_back_unchanged(): void
    {
        self::assertSame("\xFF\xFE", Unicode::nfc("\xFF\xFE"));
    }
}
