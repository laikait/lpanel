<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

/**
 * Dashboard tiles other modules contribute through `panel.dashboard`: a
 * total, its trend over the last month and what was added in it.
 */
final class Tiles
{
    /**
     * A stat tile.
     *
     * @param string       $label language key of what is counted
     * @param string       $tone  accent, ok, violet or warn
     * @param list<string> $dates when each counted thing was made ('Y-m-d H:i:s', any order)
     *
     * @return array<string, mixed> a dashboard card
     */
    public static function stat(string $label, string $icon, string $tone, int $total, array $dates, string $url, int $order = 5, int $days = 30): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $today->modify('-' . ($days - 1) . ' days');
        $perDay = \array_fill(0, $days, 0);
        $before = 0;

        foreach ($dates as $date) {
            try {
                $day = (new \DateTimeImmutable(\substr($date, 0, 10), new \DateTimeZone('UTC')));
            } catch (\Exception) {
                continue;
            }

            if ($day < $from) {
                $before++;
            } elseif ($day <= $today) {
                $perDay[(int) $from->diff($day)->days]++;
            }
        }

        // The running total at the end of each day, ending at what there is now.
        $added = \array_sum($perDay);
        $running = \max(0, $total - $added);
        $series = [];

        foreach ($perDay as $n) {
            $running += $n;
            $series[] = $running;
        }

        return [
            'template' => 'partials/stat',
            'slot' => 'stat',
            'order' => $order,
            'data' => ['label' => $label, 'icon' => $icon, 'tone' => $tone, 'value' => $total, 'series' => $series, 'added' => $added, 'days' => $days, 'url' => $url],
        ];
    }
}
