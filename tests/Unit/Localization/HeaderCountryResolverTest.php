<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Http\Request;
use App\Engine\Localization\HeaderCountryResolver;
use App\Engine\Localization\NullCountryResolver;
use App\Tests\Support\TestCase;

final class HeaderCountryResolverTest extends TestCase
{
    /** @param list<string> $trusted */
    private function request(string $country, array $trusted, string $remote = '10.0.0.1'): Request
    {
        return Request::create('GET', '/', [
            'headers' => ['CF-IPCountry' => $country],
            'server' => ['REMOTE_ADDR' => $remote],
            'trustedProxies' => $trusted,
        ]);
    }

    public function test_the_header_is_read_from_a_trusted_proxy(): void
    {
        self::assertSame('BD', (new HeaderCountryResolver('CF-IPCountry'))->country($this->request('bd', ['10.0.0.1'])));
    }

    public function test_the_header_is_ignored_from_anyone_else(): void
    {
        $resolver = new HeaderCountryResolver('CF-IPCountry');

        self::assertNull($resolver->country($this->request('BD', [])), 'no trusted proxies configured');
        self::assertNull($resolver->country($this->request('BD', ['10.0.0.2'])), 'a different proxy');
    }

    public function test_an_invalid_or_unknown_code_is_no_country(): void
    {
        $resolver = new HeaderCountryResolver('CF-IPCountry');

        self::assertNull($resolver->country($this->request('XX', ['10.0.0.1'])));
        self::assertNull($resolver->country($this->request('T1', ['10.0.0.1'])));
        self::assertNull($resolver->country($this->request('Bangladesh', ['10.0.0.1'])));
    }

    public function test_the_null_resolver_knows_nothing(): void
    {
        self::assertNull((new NullCountryResolver())->country($this->request('BD', ['10.0.0.1'])));
    }
}
