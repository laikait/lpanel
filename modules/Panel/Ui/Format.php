<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

/**
 * Template filters for numbers people read: `{{ size|bytes }}`, `{{ s|duration }}`.
 */
final class Format
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * A stored UTC time to the minute: "2026-11-10 11:12:05.000000" →
     * "2026-11-10 11:12". Empty stays empty, so `|when|default('Never')` works.
     */
    public static function when(mixed $value): string
    {
        $text = \is_string($value) ? \trim($value) : '';

        return \preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d/', $text) === 1 ? \str_replace('T', ' ', \substr($text, 0, 16)) : $text;
    }

    /** 1536 → "1.5 KB" (binary multiples, as disk tools show them). */
    public static function bytes(mixed $bytes): string
    {
        $value = \is_numeric($bytes) ? (float) $bytes : 0.0;
        $unit = 0;

        while ($value >= 1024 && $unit < \count(self::UNITS) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return ($unit === 0 ? (string) (int) $value : \number_format($value, $value < 10 ? 1 : 0)) . ' ' . self::UNITS[$unit];
    }

    /** 273600 → "3d 4h"; under an hour → "12m". */
    public static function duration(mixed $seconds): string
    {
        $s = \is_numeric($seconds) ? \max(0, (int) $seconds) : 0;
        $days = \intdiv($s, 86400);
        $hours = \intdiv($s % 86400, 3600);
        $minutes = \intdiv($s % 3600, 60);

        return match (true) {
            $days > 0 => $days . 'd ' . $hours . 'h',
            $hours > 0 => $hours . 'h ' . $minutes . 'm',
            default => $minutes . 'm',
        };
    }

    /** Used/total as a whole percentage, 0–100. */
    public static function percent(mixed $part, mixed $whole): int
    {
        $whole = \is_numeric($whole) ? (float) $whole : 0.0;

        return $whole > 0 ? (int) \min(100, \max(0, \round((\is_numeric($part) ? (float) $part : 0.0) / $whole * 100))) : 0;
    }
}
