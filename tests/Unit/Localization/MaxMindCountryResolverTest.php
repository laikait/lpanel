<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Http\Request;
use App\Engine\Localization\ChainCountryResolver;
use App\Engine\Localization\HeaderCountryResolver;
use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\MaxMindCountryResolver;
use App\Engine\Localization\NullCountryResolver;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MaxMindCountryResolverTest extends TestCase
{
    private const COUNTRY = 'tests/Fixtures/MaxMind/GeoLite2-Country-Test.mmdb';

    private const CITY = 'tests/Fixtures/MaxMind/GeoLite2-City-Test.mmdb';

    /**
     * @param array<string, string> $headers
     * @param list<string>          $trusted
     */
    private function request(string $remote, array $headers = [], array $trusted = []): Request
    {
        return Request::create('GET', '/', [
            'server' => ['REMOTE_ADDR' => $remote],
            'headers' => $headers,
            'trustedProxies' => $trusted,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function databases(): iterable
    {
        yield 'Country' => [self::COUNTRY];
        yield 'City' => [self::CITY];
    }

    #[DataProvider('databases')]
    public function test_the_country_is_looked_up_in_a_country_or_city_database(string $database): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath($database));

        self::assertSame('GB', $resolver->country($this->request('81.2.69.160')));
        self::assertSame('SE', $resolver->country($this->request('89.160.20.112')), 'where it is, not where it is registered (DE)');
        self::assertSame('US', $resolver->country($this->request('216.160.83.56')));
        self::assertSame('JP', $resolver->country($this->request('2001:218::1')), 'IPv6');
    }

    public function test_an_address_the_database_does_not_know_is_no_country(): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath(self::COUNTRY));

        self::assertNull($resolver->country($this->request('127.0.0.1')));
        self::assertNull($resolver->country($this->request('10.0.0.1')));
    }

    public function test_a_missing_or_invalid_address_is_no_country(): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath(self::COUNTRY));

        self::assertNull($resolver->country(Request::create('GET', '/')));
        self::assertNull($resolver->country($this->request('not-an-ip')));
    }

    public function test_the_forwarded_address_is_used_only_from_a_trusted_proxy(): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath(self::COUNTRY));

        self::assertSame(
            'GB',
            $resolver->country($this->request('10.0.0.1', ['X-Forwarded-For' => '81.2.69.160'], ['10.0.0.1'])),
        );
        self::assertNull(
            $resolver->country($this->request('10.0.0.1', ['X-Forwarded-For' => '81.2.69.160'])),
            'an untrusted client cannot claim another address',
        );
    }

    public function test_a_missing_database_is_reported_on_first_use(): void
    {
        $resolver = new MaxMindCountryResolver('/no/such/GeoLite2-Country.mmdb');

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('does not exist or cannot be read');

        $resolver->country($this->request('81.2.69.160'));
    }

    public function test_a_file_that_is_not_a_database_is_refused(): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath('composer.json'));

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('is not a MaxMind database');

        $resolver->country($this->request('81.2.69.160'));
    }

    public function test_a_database_without_countries_is_refused(): void
    {
        $resolver = new MaxMindCountryResolver($this->basePath('tests/Fixtures/MaxMind/GeoLite2-ASN-Test.mmdb'));

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('is a GeoLite2-ASN database, which has no countries');

        $resolver->country($this->request('81.2.69.160'));
    }

    public function test_a_chain_asks_each_resolver_until_one_knows(): void
    {
        $chain = new ChainCountryResolver(
            new HeaderCountryResolver('CF-IPCountry'),
            new MaxMindCountryResolver($this->basePath(self::COUNTRY)),
        );

        self::assertSame('BD', $chain->country($this->request('10.0.0.1', ['CF-IPCountry' => 'BD'], ['10.0.0.1'])), 'the trusted header first');
        self::assertSame('GB', $chain->country($this->request('81.2.69.160', ['CF-IPCountry' => 'BD'])), 'an untrusted header falls through to the database');
        self::assertSame('GB', $chain->country($this->request('10.0.0.1', ['CF-IPCountry' => 'XX', 'X-Forwarded-For' => '81.2.69.160'], ['10.0.0.1'])), 'an unknown header falls through');
        self::assertNull((new ChainCountryResolver(new NullCountryResolver()))->country($this->request('81.2.69.160')));
    }
}
