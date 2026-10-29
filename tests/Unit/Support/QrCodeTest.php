<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Support\QrCode;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The expected matrices come from segno 1.6.6, an established encoder, with
 * one change to it: segno pads a byte-aligned bit stream with a whole extra
 * zero byte, which the standard does not ask for (both scan). Every module
 * matches, mask choice included, from version 1 to version 40.
 */
final class QrCodeTest extends TestCase
{
    public function test_hello_module_for_module(): void
    {
        $qr = QrCode::encode('hello');

        self::assertSame(1, $qr->version());
        self::assertSame([
            '111111100110001111111',
            '100000100110001000001',
            '101110100100101011101',
            '101110100011001011101',
            '101110100110101011101',
            '100000101001101000001',
            '111111101010101111111',
            '000000000001100000000',
            '100101101100010100000',
            '001011000010001000011',
            '000110111100110001101',
            '111011001001000001011',
            '011010110010101010000',
            '000000001101000110101',
            '111111100010010101110',
            '100000101011110110000',
            '101110100001001110001',
            '101110101101000101111',
            '101110100110100010101',
            '100000100110011000000',
            '111111101111100101010',
        ], self::rows($qr));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function larger(): iterable
    {
        yield 'an otpauth URI, version 7 (version information)' => [
            'otpauth://totp/Shop:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Shop&algorithm=SHA1&digits=6&period=30',
            7,
            '10a4abfa5c1b1a2cc12e44bfeb2e689b4ea279ba242f7b1a4436b0ff2f429642',
        ];
        yield 'version 15 (two block groups)' => [\str_repeat('A', 400), 15, 'eda2ec9be68bd7bea8c13e155c62a0d5c6b453443237fd7a35b93dbe08caeb61'];
        yield 'version 40, every byte value' => [\str_repeat(\implode('', \array_map('chr', \range(0, 255))), 9), 40, 'd783c17933379036cc1fdc5b50ad153dde839de35cea494c83ab77ceefc8c79f'];
    }

    #[DataProvider('larger')]
    public function test_larger_symbols(string $data, int $version, string $sha256): void
    {
        $qr = QrCode::encode($data);

        self::assertSame($version, $qr->version());
        self::assertSame($sha256, \hash('sha256', \implode("\n", self::rows($qr))));
    }

    public function test_the_svg_is_the_matrix_with_a_quiet_zone(): void
    {
        $svg = QrCode::svg('hello');

        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29"', $svg);
        // The top-left finder's corner, four modules in.
        self::assertStringContainsString('M4,4h1v1h-1z', $svg);
        self::assertSame(\array_sum(\array_map(static fn(string $row): int => \substr_count($row, '1'), self::rows(QrCode::encode('hello')))), \substr_count($svg, 'h1v1'));
    }

    public function test_too_much_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QrCode::encode(\str_repeat('x', 2400));
    }

    /** @return list<string> */
    private static function rows(QrCode $qr): array
    {
        return \array_values(\array_map(static fn(array $row): string => \implode('', \array_map(static fn(bool $m): string => $m ? '1' : '0', $row)), $qr->matrix()));
    }
}
