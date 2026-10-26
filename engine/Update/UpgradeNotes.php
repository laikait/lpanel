<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * The sections of UPGRADING.md that apply to one update.
 *
 * UPGRADING.md has a "## To X.Y.Z, from ..." section per release. An update
 * from A to B needs every section for a version after A, up to and including B.
 */
final class UpgradeNotes
{
    /** @return list<string> the matching sections, heading included, newest first as the file has them */
    public static function between(string $markdown, string $from, string $to): array
    {
        $sections = [];
        $parts = \preg_split('/^(?=## )/m', $markdown) ?: [];

        foreach ($parts as $part) {
            if (\preg_match('/^## To v?(\d+\.\d+\.\d+)\b/', $part, $match) !== 1) {
                continue;
            }

            if (\version_compare($match[1], $from, '>') && \version_compare($match[1], $to, '<=')) {
                $sections[] = \rtrim($part) . "\n";
            }
        }

        return $sections;
    }
}
