<?php

declare(strict_types=1);

namespace App\Modules\Security\Firewall;

/**
 * An address or a network, as the firewall wants it written: lower case, the
 * network's own address, and no /32 or /128 on a single host. The agent
 * checks the same things again; this makes the panel's copy equal to what it
 * keeps.
 */
final class Cidr
{
    /** The shortest prefixes allowed in a list: one entry is never the whole Internet. */
    public const MIN_V4 = 8;
    public const MIN_V6 = 16;

    /**
     * @return array{0: string|null, 1: string|null} the canonical text, or a language key saying what is wrong
     */
    public static function normalize(string $text): array
    {
        $text = \trim($text);

        if ($text === '' || \strlen($text) > 49) {
            return [null, 'Security.fw_error_address'];
        }

        $parts = \explode('/', $text, 2);
        $host = $parts[0];
        $bitsText = $parts[1] ?? null;
        $packed = \filter_var($host, \FILTER_VALIDATE_IP) === false ? false : @\inet_pton($host);

        if ($packed === false) {
            return [null, 'Security.fw_error_address'];
        }

        $size = \strlen($packed);
        $max = $size * 8;

        // An IPv4 address written in IPv6 (::ffff:1.2.3.4) is ambiguous: write it as IPv4.
        if ($size === 16 && \str_starts_with($packed, \str_repeat("\0", 10) . "\xff\xff")) {
            return [null, 'Security.fw_error_address'];
        }

        if ($bitsText !== null && (!\ctype_digit($bitsText) || \strlen($bitsText) > 3 || (int) $bitsText > $max)) {
            return [null, 'Security.fw_error_address'];
        }

        $bits = $bitsText === null ? $max : (int) $bitsText;

        if ($bits < ($size === 4 ? self::MIN_V4 : self::MIN_V6)) {
            return [null, 'Security.fw_error_wide'];
        }

        $masked = '';

        for ($i = 0; $i < $size; $i++) {
            $keep = \max(0, \min(8, $bits - $i * 8));
            $masked .= \chr(\ord($packed[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        $out = (string) \inet_ntop($masked);

        return [$bits === $max ? $out : $out . '/' . $bits, null];
    }

    /** Whether an address (or network) lies inside a network, both as normalize() writes them. */
    public static function contains(string $network, string $address): bool
    {
        $a = \explode('/', $network, 2);
        $b = \explode('/', $address, 2);
        $net = @\inet_pton($a[0]);
        $ip = @\inet_pton($b[0]);

        if ($net === false || $ip === false || \strlen($net) !== \strlen($ip)) {
            return false;
        }

        $bits = isset($a[1]) ? (int) $a[1] : \strlen($net) * 8;

        // The inner network must be at least as narrow as the outer one.
        if (isset($b[1]) && (int) $b[1] < $bits) {
            return false;
        }

        for ($i = 0; $i < \strlen($net); $i++) {
            $keep = \max(0, \min(8, $bits - $i * 8));
            $mask = (0xFF << (8 - $keep)) & 0xFF;

            if ((\ord($net[$i]) & $mask) !== (\ord($ip[$i]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the addresses and networks in a text, one per line or comma-separated */
    public static function split(string $text): array
    {
        return \array_values(\array_filter(\array_map('trim', \preg_split('/[\s,;]+/', $text) ?: []), static fn(string $s): bool => $s !== ''));
    }
}
