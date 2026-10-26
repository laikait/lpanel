<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * Arithmetic on packed, big-endian addresses of any length.
 *
 * An IPv6 address is 128 bits and PHP's int is 64, so rather than require GMP
 * or BCMath everything is done a byte at a time on the inet_pton() string.
 * Two packed addresses of the same length compare with strcmp() in numeric
 * order, which is what makes range checks cheap.
 *
 * @internal
 */
final class Bytes
{
    /** A mask of $length bytes whose first $prefix bits are set. */
    public static function mask(int $prefix, int $length): string
    {
        $full = \intdiv($prefix, 8);
        $mask = \str_repeat("\xFF", $full);

        if ($full < $length) {
            $mask .= \chr((0xFF << (8 - $prefix % 8)) & 0xFF);
            $mask .= \str_repeat("\x00", $length - $full - 1);
        }

        return $mask;
    }

    /** The number of leading one bits, or null when the ones are not contiguous. */
    public static function prefixOf(string $mask): ?int
    {
        $bits = '';

        foreach (\str_split($mask) as $byte) {
            $bits .= \str_pad(\decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $prefix = \strspn($bits, '1');

        return \strpos($bits, '1', $prefix) === false ? $prefix : null;
    }

    /** Trailing zero bits: how large an aligned block may start here. */
    public static function trailingZeros(string $bytes): int
    {
        $count = 0;

        for ($i = \strlen($bytes) - 1; $i >= 0; --$i) {
            $byte = \ord($bytes[$i]);

            if ($byte === 0) {
                $count += 8;

                continue;
            }

            while (($byte & 1) === 0) {
                ++$count;
                $byte >>= 1;
            }

            break;
        }

        return $count;
    }

    /**
     * $amount, a non-negative int, as a big-endian string of $length bytes,
     * or null when it does not fit.
     */
    public static function fromInt(int $amount, int $length): ?string
    {
        $packed = \pack('J', $amount);

        if ($length >= 8) {
            return \str_repeat("\x00", $length - 8) . $packed;
        }

        $overflow = \substr($packed, 0, 8 - $length);

        return \trim($overflow, "\x00") === '' ? \substr($packed, 8 - $length) : null;
    }

    /** $a + $b, or null on overflow. Both the same length. */
    public static function add(string $a, string $b): ?string
    {
        $result = '';
        $carry = 0;

        for ($i = \strlen($a) - 1; $i >= 0; --$i) {
            $sum = \ord($a[$i]) + \ord($b[$i]) + $carry;
            $result = \chr($sum & 0xFF) . $result;
            $carry = $sum >> 8;
        }

        return $carry === 0 ? $result : null;
    }

    /** $a - $b, or null on underflow. Both the same length. */
    public static function subtract(string $a, string $b): ?string
    {
        $result = '';
        $borrow = 0;

        for ($i = \strlen($a) - 1; $i >= 0; --$i) {
            $difference = \ord($a[$i]) - \ord($b[$i]) - $borrow;
            $borrow = $difference < 0 ? 1 : 0;
            $result = \chr(($difference + 256) & 0xFF) . $result;
        }

        return $borrow === 0 ? $result : null;
    }

    /** 2 to the power $exponent, as a decimal string, for any exponent. */
    public static function powerOfTwo(int $exponent): string
    {
        $digits = '1';

        for ($i = 0; $i < $exponent; ++$i) {
            $doubled = '';
            $carry = 0;

            for ($j = \strlen($digits) - 1; $j >= 0; --$j) {
                $value = (int) $digits[$j] * 2 + $carry;
                $doubled = ($value % 10) . $doubled;
                $carry = \intdiv($value, 10);
            }

            $digits = ($carry > 0 ? (string) $carry : '') . $doubled;
        }

        return $digits;
    }
}
