<?php

declare(strict_types=1);

namespace App\Engine\Auth;

use App\Engine\Security\Signer;

/**
 * Time-based one-time passwords, RFC 6238: the six digits an authenticator app shows.
 *
 *     $secret = Totp::secret();                                     // Base32, for the app
 *     $uri    = Totp::uri($secret, 'ada@example.com', 'Shop');      // otpauth://… for the QR code
 *     $step   = Totp::verify($secret, '492039', $lastStep);         // the step it matched, or null
 *
 * HMAC-SHA1, six digits, thirty seconds: what every authenticator app does
 * without being told, and what the otpauth URI says anyway. A code from the
 * step before or after is accepted too, for a phone whose clock is a little
 * out and a person who types slowly.
 *
 * **A code works once.** verify() returns the time step it matched; store it,
 * pass it back next time, and a code from that step or an earlier one is
 * refused -- so a code read over somebody's shoulder is useless once used.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** Steps either side of now that still count. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 160 random bits, Base32, as RFC 4226 recommends. */
    public static function secret(int $bytes = 20): string
    {
        return self::base32Encode(\random_bytes(\max(16, $bytes)));
    }

    /**
     * The code for a moment.
     *
     * @param string $algorithm sha1, sha256 or sha512
     */
    public static function code(string $secret, ?int $time = null, int $digits = self::DIGITS, string $algorithm = 'sha1', int $period = self::PERIOD): string
    {
        return self::codeAt(self::base32Decode($secret), \intdiv($time ?? \time(), $period), $digits, $algorithm);
    }

    /**
     * The time step a code belongs to, when it is right and newer than $lastStep.
     *
     * @param ?int $lastStep the step returned by the last successful verify(), so a code cannot be used twice
     */
    public static function verify(string $secret, string $code, ?int $lastStep = null, ?int $time = null, int $window = self::WINDOW): ?int
    {
        $code = \preg_replace('/\s+/', '', $code) ?? '';

        if (\preg_match('/^\d{' . self::DIGITS . '}$/D', $code) !== 1) {
            return null;
        }

        $key = self::base32Decode($secret);
        $now = \intdiv($time ?? \time(), self::PERIOD);
        $matched = null;

        // Every step in the window is computed, match or not, so how long this
        // takes says nothing about which one matched.
        for ($step = $now - $window; $step <= $now + $window; ++$step) {
            if (Signer::matches(self::codeAt($key, $step, self::DIGITS, 'sha1'), $code) && ($lastStep === null || $step > $lastStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /**
     * What an authenticator app scans.
     *
     * @param string $account how the app labels it: usually the email address
     * @param string $issuer  the application's name
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = \rawurlencode($issuer) . ':' . \rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . \http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (\str_split($bytes) as $byte) {
            $bits .= \str_pad(\decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $out = '';

        foreach (\str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[(int) \bindec(\str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** @throws \InvalidArgumentException for anything that is not Base32 */
    public static function base32Decode(string $text): string
    {
        $text = \strtoupper(\rtrim(\preg_replace('/[\s-]+/', '', $text) ?? '', '='));
        $bits = '';

        foreach (\str_split($text) as $char) {
            $value = \strpos(self::BASE32, $char);

            if ($value === false) {
                throw new \InvalidArgumentException('A TOTP secret is Base32: A-Z and 2-7.');
            }

            $bits .= \str_pad(\decbin($value), 5, '0', \STR_PAD_LEFT);
        }

        $out = '';

        foreach (\str_split($bits, 8) as $byte) {
            if (\strlen($byte) === 8) {
                $out .= \chr((int) \bindec($byte));
            }
        }

        return $out;
    }

    /** RFC 4226's HOTP, at a counter. */
    private static function codeAt(string $key, int $counter, int $digits, string $algorithm): string
    {
        $hash = Signer::hmac($algorithm, \pack('J', $counter), $key, true);
        $offset = \ord($hash[\strlen($hash) - 1]) & 0x0F;
        $value = ((\ord($hash[$offset]) & 0x7F) << 24)
            | (\ord($hash[$offset + 1]) << 16)
            | (\ord($hash[$offset + 2]) << 8)
            | \ord($hash[$offset + 3]);

        return \str_pad((string) ($value % 10 ** $digits), $digits, '0', \STR_PAD_LEFT);
    }
}
