<?php

declare(strict_types=1);

namespace App\Modules\Panel\Ui;

/**
 * Small charts drawn as inline SVG on the server, for dashboards:
 * `{{ spark(values) }}`, `{{ gauge(percent) }}` and `{{ chart(points) }}`.
 *
 * No chart library and no script: they colour themselves from the template's
 * tokens (`var(--accent)` and friends), so they follow the light and dark
 * themes. Every number is cast and formatted here, so nothing a caller passes
 * can reach the markup unescaped.
 */
final class Charts
{
    /**
     * A filled line with no axes, for a tile.
     *
     * @param list<int|float> $values
     * @param string          $tone   accent, ok, violet, warn or bad
     */
    public function spark(array $values, string $tone = 'accent', int $width = 140, int $height = 44): string
    {
        $values = \array_values(\array_map(static fn(mixed $v): float => (float) $v, $values));

        if (\count($values) < 2) {
            $values = [0.0, 0.0];
        }

        $color = self::color($tone);
        $points = $this->points($values, $width, $height, 4);
        $line = self::path($points);
        $area = $line . \sprintf(' L%.1f,%d L%.1f,%d Z', $points[\count($points) - 1][0], $height, $points[0][0], $height);
        $id = 'sp' . \substr(\md5($line . $tone), 0, 8);

        return \sprintf(
            '<svg class="spark" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" preserveAspectRatio="none" aria-hidden="true">'
            . '<defs><linearGradient id="%3$s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="%4$s" stop-opacity=".28"/><stop offset="1" stop-color="%4$s" stop-opacity="0"/></linearGradient></defs>'
            . '<path d="%5$s" fill="url(#%3$s)"/><path d="%6$s" fill="none" stroke="%4$s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/></svg>',
            $width,
            $height,
            $id,
            $color,
            $area,
            $line,
        );
    }

    /**
     * A ring that fills to a percentage, with the number inside.
     *
     * @param string $tone accent, ok, violet, warn or bad; empty picks one by the percentage
     */
    public function gauge(float|int $percent, string $tone = '', int $size = 132): string
    {
        $percent = \max(0.0, \min(100.0, (float) $percent));
        $tone = $tone !== '' ? $tone : ($percent >= 90 ? 'bad' : ($percent >= 75 ? 'warn' : 'accent'));
        $radius = 54;
        $circumference = 2 * \M_PI * $radius;
        $filled = $circumference * $percent / 100;

        return \sprintf(
            '<svg class="gauge" viewBox="0 0 132 132" width="%1$d" height="%1$d" role="img" aria-label="%2$s%%">'
            . '<circle cx="66" cy="66" r="%3$d" fill="none" stroke="var(--border)" stroke-width="12"/>'
            . '<circle cx="66" cy="66" r="%3$d" fill="none" stroke="%4$s" stroke-width="12" stroke-linecap="round" stroke-dasharray="%5$.2f %6$.2f" transform="rotate(-90 66 66)"/>'
            . '<text x="66" y="66" text-anchor="middle" dominant-baseline="central" font-size="30" font-weight="700" fill="var(--text)">%2$s%%</text></svg>',
            $size,
            \number_format($percent, 0, '.', ''),
            $radius,
            self::color($tone),
            $filled,
            $circumference,
        );
    }

    /**
     * A line chart with gridlines and labelled axes.
     *
     * @param list<array{label: string, value: int|float}> $points oldest first
     * @param string                                       $unit   appended to the value labels on the y axis
     */
    public function chart(array $points, string $tone = 'accent', string $unit = ''): string
    {
        $width = 640;
        $height = 240;
        $left = 52;
        $right = 12;
        $top = 14;
        $bottom = 30;
        $values = \array_map(static fn(array $p): float => (float) $p['value'], $points);

        if (\count($values) < 2) {
            return '';
        }

        $max = \max($values);
        $step = self::niceStep($max);
        $top_value = \max($step, \ceil($max / $step) * $step);
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;
        $color = self::color($tone);
        $n = \count($values);
        $xy = [];

        foreach ($values as $i => $v) {
            $xy[] = [$left + $plotW * $i / ($n - 1), $top + $plotH * (1 - $v / $top_value)];
        }

        $line = self::path($xy);
        $area = $line . \sprintf(' L%.1f,%.1f L%.1f,%.1f Z', $xy[$n - 1][0], $top + $plotH, $xy[0][0], $top + $plotH);
        $id = 'ch' . \substr(\md5($line), 0, 8);
        $svg = \sprintf('<svg class="chart" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="chart">', $width, $height);
        $svg .= \sprintf('<defs><linearGradient id="%s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="%s" stop-opacity=".25"/><stop offset="1" stop-color="%s" stop-opacity="0"/></linearGradient></defs>', $id, $color, $color);

        for ($g = 0; $g <= 4; $g++) {
            $v = $top_value * $g / 4;
            $y = $top + $plotH * (1 - $g / 4);
            $svg .= \sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="var(--border)" stroke-width="1"/>', $left, $y, $width - $right, $y);
            $svg .= \sprintf('<text x="%d" y="%.1f" text-anchor="end" dominant-baseline="central" font-size="12" fill="var(--text-3)">%s</text>', $left - 8, $y, \htmlspecialchars(self::short($v) . $unit, \ENT_QUOTES));
        }

        $labels = \min($n, 5);

        for ($l = 0; $l < $labels; $l++) {
            $i = (int) \round(($n - 1) * $l / \max(1, $labels - 1));
            $svg .= \sprintf(
                '<text x="%.1f" y="%d" text-anchor="%s" font-size="12" fill="var(--text-3)">%s</text>',
                $xy[$i][0],
                $height - 8,
                $l === 0 ? 'start' : ($l === $labels - 1 ? 'end' : 'middle'),
                \htmlspecialchars((string) $points[$i]['label'], \ENT_QUOTES),
            );
        }

        $svg .= \sprintf('<path d="%s" fill="url(#%s)"/><path d="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>', $area, $id, $line, $color);

        foreach ($xy as $i => [$x, $y]) {
            if ($n <= 14 || $i % \max(1, (int) \floor($n / 14)) === 0 || $i === $n - 1) {
                $svg .= \sprintf('<circle cx="%.1f" cy="%.1f" r="3.5" fill="var(--surface)" stroke="%s" stroke-width="2"/>', $x, $y, $color);
            }
        }

        return $svg . '</svg>';
    }

    /**
     * @param non-empty-list<float> $values at least two points
     *
     * @return list<array{0: float, 1: float}>
     */
    private function points(array $values, int $width, int $height, int $pad): array
    {
        $min = \min($values);
        $max = \max($values);
        $range = $max - $min;
        $n = \count($values);
        $out = [];

        foreach ($values as $i => $v) {
            $y = $range > 0 ? $height - $pad - ($v - $min) / $range * ($height - 2 * $pad) : $height / 2;
            $out[] = [$width * $i / ($n - 1), $y];
        }

        return $out;
    }

    /**
     * A smooth path through the points (Catmull-Rom as cubic curves).
     *
     * @param list<array{0: float, 1: float}> $p
     */
    private static function path(array $p): string
    {
        $d = \sprintf('M%.1f,%.1f', $p[0][0], $p[0][1]);
        $n = \count($p);

        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $p[\max(0, $i - 1)];
            $p1 = $p[$i];
            $p2 = $p[$i + 1];
            $p3 = $p[\min($n - 1, $i + 2)];
            $d .= \sprintf(
                ' C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
                $p1[0] + ($p2[0] - $p0[0]) / 6,
                $p1[1] + ($p2[1] - $p0[1]) / 6,
                $p2[0] - ($p3[0] - $p1[0]) / 6,
                $p2[1] - ($p3[1] - $p1[1]) / 6,
                $p2[0],
                $p2[1],
            );
        }

        return $d;
    }

    private static function color(string $tone): string
    {
        return match ($tone) {
            'ok' => 'var(--ok)',
            'violet' => 'var(--violet)',
            'warn' => 'var(--warn)',
            'bad' => 'var(--bad)',
            default => 'var(--accent)',
        };
    }

    private static function niceStep(float $max): float
    {
        if ($max <= 0) {
            return 1.0;
        }

        $raw = $max / 4;
        $magnitude = 10 ** \floor(\log10($raw));
        $f = $raw / $magnitude;

        return ($f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10))) * $magnitude;
    }

    private static function short(float $v): string
    {
        return match (true) {
            $v >= 1_000_000_000 => \rtrim(\rtrim(\number_format($v / 1_000_000_000, 1, '.', ''), '0'), '.') . 'G',
            $v >= 1_000_000 => \rtrim(\rtrim(\number_format($v / 1_000_000, 1, '.', ''), '0'), '.') . 'M',
            $v >= 1_000 => \rtrim(\rtrim(\number_format($v / 1_000, 1, '.', ''), '0'), '.') . 'k',
            default => \rtrim(\rtrim(\number_format($v, 1, '.', ''), '0'), '.') ?: '0',
        };
    }
}
