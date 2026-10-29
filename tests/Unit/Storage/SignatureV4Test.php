<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Engine\Storage\S3\SignatureV4;
use App\Tests\Support\TestCase;

/**
 * Against AWS's own published examples: the "get-vanilla" case of the
 * Signature Version 4 test suite, and the two worked examples in the S3
 * documentation ("Authenticating Requests: Using the Authorization Header"
 * and "Query String Authentication").
 */
final class SignatureV4Test extends TestCase
{
    private const SUITE_KEY = 'AKIDEXAMPLE';

    private const SUITE_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    private const S3_KEY = 'AKIAIOSFODNN7EXAMPLE';

    private const S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    public function test_the_test_suites_get_vanilla(): void
    {
        $headers = (new SignatureV4(self::SUITE_KEY, self::SUITE_SECRET, 'us-east-1', 'service'))->authorize(
            'GET',
            'https://example.amazonaws.com/',
            [],
            \hash('sha256', ''),
            new \DateTimeImmutable('2015-08-30T12:36:00Z'),
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $headers['Authorization'],
        );
        self::assertSame('20150830T123600Z', $headers['X-Amz-Date']);
    }

    public function test_the_s3_documentations_get_object(): void
    {
        $empty = \hash('sha256', '');
        $headers = (new SignatureV4(self::S3_KEY, self::S3_SECRET, 'us-east-1'))->authorize(
            'GET',
            'https://examplebucket.s3.amazonaws.com/test.txt',
            ['Range' => 'bytes=0-9', 'x-amz-content-sha256' => $empty],
            $empty,
            new \DateTimeImmutable('2013-05-24T00:00:00Z'),
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization'],
        );
        self::assertSame('bytes=0-9', $headers['Range']);
    }

    public function test_the_s3_documentations_presigned_url(): void
    {
        $url = (new SignatureV4(self::S3_KEY, self::S3_SECRET, 'us-east-1'))->presign(
            'GET',
            'https://examplebucket.s3.amazonaws.com/test.txt',
            86400,
            new \DateTimeImmutable('2013-05-24T00:00:00Z'),
        );

        self::assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256'
            . '&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request'
            . '&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host'
            . '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url,
        );
    }

    public function test_the_query_is_sorted_and_encoded_once(): void
    {
        $sig = new SignatureV4(self::S3_KEY, self::S3_SECRET, 'us-east-1');
        $now = new \DateTimeImmutable('2013-05-24T00:00:00Z');

        $a = $sig->authorize('GET', 'https://b.example/?prefix=a%20b&list-type=2', [], 'x', $now);
        $b = $sig->authorize('GET', 'https://b.example/?list-type=2&prefix=a%20b', [], 'x', $now);

        self::assertSame($a['Authorization'], $b['Authorization']);
    }

    public function test_a_presigned_url_lives_seven_days_at_most(): void
    {
        $url = (new SignatureV4(self::S3_KEY, self::S3_SECRET, 'us-east-1'))->presign('GET', 'https://b.example/x', 10 ** 9);

        self::assertStringContainsString('X-Amz-Expires=604800&', $url);
    }

    public function test_a_port_is_part_of_the_host(): void
    {
        $headers = (new SignatureV4(self::S3_KEY, self::S3_SECRET, 'us-east-1'))
            ->authorize('GET', 'http://127.0.0.1:9000/bucket/x', ['host' => 'ignored'], 'x');

        self::assertSame('127.0.0.1:9000', $headers['Host']);
        self::assertArrayNotHasKey('host', $headers);
    }
}
