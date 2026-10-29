<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\Totp;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TotpTest extends TestCase
{
    /**
     * RFC 6238, appendix B: eight digits, the three seeds, six moments.
     *
     * @return iterable<string, array{int, string, string, string}>
     */
    public static function rfc6238(): iterable
    {
        $seeds = [
            'sha1' => '12345678901234567890',
            'sha256' => '12345678901234567890123456789012',
            'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
        ];
        $vectors = [
            [59, '94287082', '46119246', '90693936'],
            [1111111109, '07081804', '68084774', '25091201'],
            [1111111111, '14050471', '67062674', '99943326'],
            [1234567890, '89005924', '91819424', '93441116'],
            [2000000000, '69279037', '90698825', '38618901'],
            [20000000000, '65353130', '77737706', '47863826'],
        ];

        foreach ($vectors as [$time, $sha1, $sha256, $sha512]) {
            foreach (['sha1' => $sha1, 'sha256' => $sha256, 'sha512' => $sha512] as $algorithm => $code) {
                yield $algorithm . ' at ' . $time => [$time, $algorithm, Totp::base32Encode($seeds[$algorithm]), $code];
            }
        }
    }

    #[DataProvider('rfc6238')]
    public function test_the_rfc_6238_vectors(int $time, string $algorithm, string $secret, string $code): void
    {
        self::assertSame($code, Totp::code($secret, $time, 8, $algorithm));
    }

    public function test_base32_round_trips(): void
    {
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode('12345678901234567890'));

        for ($i = 0; $i < 50; ++$i) {
            $bytes = \random_bytes(20);
            self::assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }

        self::assertSame('12345678901234567890', Totp::base32Decode('gezd gnbv-gy3t qojq gezd gnbv gy3t qojq'));
    }

    public function test_a_code_from_the_neighbouring_steps_counts(): void
    {
        $secret = Totp::secret();
        $now = 1_700_000_000;
        $step = \intdiv($now, 30);

        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $now), time: $now));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $now - 30), time: $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), time: $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now - 60), time: $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now + 60), time: $now));
    }

    public function test_a_used_code_does_not_work_again(): void
    {
        $secret = Totp::secret();
        $now = 1_700_000_000;
        $code = Totp::code($secret, $now);

        $step = Totp::verify($secret, $code, null, $now);
        self::assertNotNull($step);
        self::assertNull(Totp::verify($secret, $code, $step, $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now - 30), $step, $now), 'An older code after a newer one.');
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), $step, $now + 30));
    }

    public function test_spaces_are_forgiven_anything_else_is_not(): void
    {
        $secret = Totp::secret();
        $code = Totp::code($secret, 1_700_000_000);

        self::assertNotNull(Totp::verify($secret, \substr($code, 0, 3) . ' ' . \substr($code, 3), time: 1_700_000_000));

        foreach (['', '12345', '1234567', 'abcdef', $code . 'x'] as $bad) {
            self::assertNull(Totp::verify($secret, $bad, time: 1_700_000_000), $bad);
        }
    }

    public function test_the_otpauth_uri(): void
    {
        self::assertSame(
            'otpauth://totp/Big%20Shop:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Big%20Shop&algorithm=SHA1&digits=6&period=30',
            Totp::uri('JBSWY3DPEHPK3PXP', 'ada@example.com', 'Big Shop'),
        );
    }
}
