<?php

declare(strict_types=1);

namespace App\Tests\Unit\Update;

use App\Engine\Update\UpgradeNotes;
use App\Tests\Support\TestCase;

final class UpgradeNotesTest extends TestCase
{
    public function test_only_the_sections_between_the_two_versions(): void
    {
        $markdown = "# Upgrading\n\nIntro.\n\n## To 4.0.0, from 3.2.0\n\nfour\n\n## To 3.2.0, from 3.1.0\n\nthree two\n\n"
            . "## To 3.1.0, from 3.0.0\n\nthree one\n\n## To 3.0.0, from 2.1.2\n\nthree\n";

        self::assertSame(
            ["## To 3.2.0, from 3.1.0\n\nthree two\n", "## To 3.1.0, from 3.0.0\n\nthree one\n"],
            UpgradeNotes::between($markdown, '3.0.0', '3.2.0'),
        );
        self::assertSame([], UpgradeNotes::between($markdown, '3.2.0', '3.2.0'));
    }
}
