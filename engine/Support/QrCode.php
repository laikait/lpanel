<?php

declare(strict_types=1);

namespace App\Engine\Support;

/**
 * A QR code, as SVG: enough of ISO/IEC 18004 to put an otpauth:// URI on a
 * page for an authenticator app to scan.
 *
 *     echo QrCode::svg('otpauth://totp/Shop:ada@example.com?secret=…');
 *
 * Byte mode, error correction level M, the smallest version that fits, and
 * the mask with the lowest penalty -- the choices every scanner expects. It is
 * written out rather than taken from a library because it is two hundred
 * lines that never change, and it is checked module for module against an
 * established encoder (tests/Unit/Support/QrCodeTest.php).
 *
 * The SVG draws dark modules only, with the four-module quiet zone the
 * standard requires, and scales to whatever size the page gives it.
 */
final class QrCode
{
    /** version => [EC codewords per block, [[blocks, data codewords per block], …]], level M */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
        11 => [30, [[1, 50], [4, 51]]],
        12 => [22, [[6, 36], [2, 37]]],
        13 => [22, [[8, 37], [1, 38]]],
        14 => [24, [[4, 40], [5, 41]]],
        15 => [24, [[5, 41], [5, 42]]],
        16 => [28, [[7, 45], [3, 46]]],
        17 => [28, [[10, 46], [1, 47]]],
        18 => [26, [[9, 43], [4, 44]]],
        19 => [26, [[3, 44], [11, 45]]],
        20 => [26, [[3, 41], [13, 42]]],
        21 => [26, [[17, 42]]],
        22 => [28, [[17, 46]]],
        23 => [28, [[4, 47], [14, 48]]],
        24 => [28, [[6, 45], [14, 46]]],
        25 => [28, [[8, 47], [13, 48]]],
        26 => [28, [[19, 46], [4, 47]]],
        27 => [28, [[22, 45], [3, 46]]],
        28 => [28, [[3, 45], [23, 46]]],
        29 => [28, [[21, 45], [7, 46]]],
        30 => [28, [[19, 47], [10, 48]]],
        31 => [28, [[2, 46], [29, 47]]],
        32 => [28, [[10, 46], [23, 47]]],
        33 => [28, [[14, 46], [21, 47]]],
        34 => [28, [[14, 46], [23, 47]]],
        35 => [28, [[12, 47], [26, 48]]],
        36 => [28, [[6, 47], [34, 48]]],
        37 => [28, [[29, 46], [14, 47]]],
        38 => [28, [[13, 46], [32, 47]]],
        39 => [28, [[40, 47], [7, 48]]],
        40 => [28, [[18, 47], [31, 48]]],
    ];

    /** @var array<int, array<int, bool>> rows of modules, true for dark */
    private array $modules = [];

    /** @var array<int, array<int, bool>> function patterns, which masking leaves alone */
    private array $reserved = [];

    private int $size;

    /** @param ?int $mask 0-7 to force one; null to choose the best, as scanners expect */
    private function __construct(private readonly string $data, private readonly int $version, ?int $mask)
    {
        $this->size = $version * 4 + 17;
        $this->modules = \array_fill(0, $this->size, \array_fill(0, $this->size, false));
        $this->reserved = $this->modules;

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->codewords());

        if ($mask === null) {
            $best = \PHP_INT_MAX;

            for ($candidate = 0; $candidate < 8; ++$candidate) {
                $this->applyMask($candidate);
                $this->drawFormat($candidate);
                $penalty = $this->penalty();

                if ($penalty < $best) {
                    $best = $penalty;
                    $mask = $candidate;
                }

                $this->applyMask($candidate);
            }
        }

        \assert($mask !== null);
        $this->applyMask($mask);
        $this->drawFormat($mask);
    }

    /**
     * @param ?int $version 1-40 to force one; null for the smallest that fits
     *
     * @throws \InvalidArgumentException when the text does not fit a QR code
     */
    public static function encode(string $data, ?int $version = null, ?int $mask = null): self
    {
        for ($v = $version ?? 1; $v <= ($version ?? 40); ++$v) {
            if (\strlen($data) <= self::capacity($v)) {
                return new self($data, $v, $mask);
            }
        }

        throw new \InvalidArgumentException(\sprintf('%d bytes do not fit in a QR code%s.', \strlen($data), $version === null ? '' : ' of version ' . $version));
    }

    public static function svg(string $data): string
    {
        return self::encode($data)->toSvg();
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<int, array<int, bool>> rows of modules from the top, left to right; true for dark */
    public function matrix(): array
    {
        return $this->modules;
    }

    public function toSvg(int $quietZone = 4): string
    {
        $path = '';

        foreach ($this->modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $quietZone) . ',' . ($y + $quietZone) . 'h1v1h-1z';
                }
            }
        }

        $full = $this->size + 2 * $quietZone;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges" role="img">'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /** Bytes that fit at this version: data codewords, less the mode and count. */
    private static function capacity(int $version): int
    {
        $data = 0;

        foreach (self::BLOCKS[$version][1] as [$blocks, $perBlock]) {
            $data += $blocks * $perBlock;
        }

        return \intdiv($data * 8 - 4 - ($version < 10 ? 8 : 16), 8);
    }

    // ---- data -----------------------------------------------------------------

    /** @return list<int> data and error correction codewords, interleaved */
    private function codewords(): array
    {
        [$ecPerBlock, $groups] = self::BLOCKS[$this->version];
        $capacity = 0;

        foreach ($groups as [$blocks, $perBlock]) {
            $capacity += $blocks * $perBlock;
        }

        $bits = '0100' . \str_pad(\decbin(\strlen($this->data)), $this->version < 10 ? 8 : 16, '0', \STR_PAD_LEFT);

        foreach (\str_split($this->data) as $byte) {
            $bits .= \str_pad(\decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $bits .= \str_repeat('0', \min(4, $capacity * 8 - \strlen($bits)));
        $bits .= \str_repeat('0', (8 - \strlen($bits) % 8) % 8);

        $data = \array_map('bindec', \str_split($bits, 8));

        for ($pad = 0; \count($data) < $capacity; ++$pad) {
            $data[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        /** @var list<int> $data */
        $blocks = [];
        $offset = 0;

        foreach ($groups as [$count, $perBlock]) {
            for ($i = 0; $i < $count; ++$i) {
                $blocks[] = \array_slice($data, $offset, $perBlock);
                $offset += $perBlock;
            }
        }

        $divisor = self::generator($ecPerBlock);
        $ec = \array_map(static fn(array $block): array => self::remainder($block, $divisor), $blocks);
        $out = [];
        $longest = \max(\array_map('count', $blocks));

        for ($i = 0; $i < $longest; ++$i) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; ++$i) {
            foreach ($ec as $block) {
                $out[] = $block[$i];
            }
        }

        return $out;
    }

    /** @return array<int, int> the Reed-Solomon generator polynomial's coefficients */
    private static function generator(int $degree): array
    {
        $result = \array_fill(0, $degree - 1, 0);
        $result[] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; ++$i) {
            for ($j = 0; $j < $degree; ++$j) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param list<int>       $data
     * @param array<int, int> $divisor
     *
     * @return array<int, int>
     */
    private static function remainder(array $data, array $divisor): array
    {
        $result = \array_fill(0, \count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ \array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /** Multiplication in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1. */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; --$i) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // ---- placement ------------------------------------------------------------

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; ++$i) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }

        $this->finder(3, 3);
        $this->finder($this->size - 4, 3);
        $this->finder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $last = \count($positions) - 1;

        foreach ($positions as $i => $x) {
            foreach ($positions as $j => $y) {
                // Not over the three finders.
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0))) {
                    for ($dy = -2; $dy <= 2; ++$dy) {
                        for ($dx = -2; $dx <= 2; ++$dx) {
                            $this->set($x + $dx, $y + $dy, \max(\abs($dx), \abs($dy)) !== 1);
                        }
                    }
                }
            }
        }

        // Reserve the format areas now; drawFormat() fills them per mask.
        $this->drawFormat(0);

        if ($this->version >= 7) {
            $remainder = $this->version;

            for ($i = 0; $i < 12; ++$i) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }

            $bits = ($this->version << 12) | $remainder;

            for ($i = 0; $i < 18; ++$i) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = \intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function finder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; ++$dy) {
            for ($dx = -4; $dx <= 4; ++$dx) {
                $x = $cx + $dx;
                $y = $cy + $dy;

                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $distance = \max(\abs($dx), \abs($dy));
                    $this->set($x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count = \intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) \ceil(($this->version * 4 + 4) / ($count * 2 - 2)) * 2;
        $positions = [6];

        for ($position = $this->size - 7; \count($positions) < $count; $position -= $step) {
            \array_splice($positions, 1, 0, [$position]);
        }

        return $positions;
    }

    private function drawFormat(int $mask): void
    {
        // Level M is 00; the mask follows.
        $data = $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; ++$i) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; ++$i) {
            $this->set(8, $i, $bit($i));
        }

        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));

        for ($i = 9; $i < 15; ++$i) {
            $this->set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; ++$i) {
            $this->set($this->size - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i < 15; ++$i) {
            $this->set(8, $this->size - 15 + $i, $bit($i));
        }

        $this->set(8, $this->size - 8, true);
    }

    /** @param list<int> $codewords */
    private function drawCodewords(array $codewords): void
    {
        $i = 0;
        $total = \count($codewords) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; ++$vertical) {
                for ($j = 0; $j < 2; ++$j) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if (!$this->reserved[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        ++$i;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; ++$y) {
            for ($x = 0; $x < $this->size; ++$x) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (\intdiv($x, 3) + \intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert && !$this->reserved[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The standard's four penalty rules: runs, blocks, finder look-alikes, balance. */
    private function penalty(): int
    {
        $penalty = 0;
        $size = $this->size;
        $dark = 0;

        for ($pass = 0; $pass < 2; ++$pass) {
            for ($a = 0; $a < $size; ++$a) {
                $line = [];

                for ($b = 0; $b < $size; ++$b) {
                    $line[] = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                }

                $run = 1;

                for ($b = 1; $b <= $size; ++$b) {
                    if ($b < $size && $line[$b] === $line[$b - 1]) {
                        ++$run;

                        continue;
                    }

                    if ($run >= 5) {
                        $penalty += $run - 2;
                    }

                    $run = 1;
                }

                // The quiet zone is light, so a finder look-alike at the edge counts.
                $bits = '0000' . \implode('', \array_map(static fn(bool $m): string => $m ? '1' : '0', $line)) . '0000';

                // Once per 1:1:3:1:1, when four light modules are on either side.
                for ($at = \strpos($bits, '1011101'); $at !== false; $at = \strpos($bits, '1011101', $at + 1)) {
                    if (\substr($bits, $at - 4, 4) === '0000' || \substr($bits, $at + 7, 4) === '0000') {
                        $penalty += 40;
                    }
                }
            }
        }

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                $module = $this->modules[$y][$x];
                $dark += $module ? 1 : 0;

                if ($x < $size - 1 && $y < $size - 1
                    && $module === $this->modules[$y][$x + 1]
                    && $module === $this->modules[$y + 1][$x]
                    && $module === $this->modules[$y + 1][$x + 1]
                ) {
                    $penalty += 3;
                }
            }
        }

        $total = $size * $size;
        // 10 for every whole 5% the dark share is away from half.
        $penalty += \intdiv(\abs($dark * 20 - $total * 10), $total) * 10;

        return $penalty;
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->reserved[$y][$x] = true;
    }
}
