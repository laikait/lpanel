<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Http\Request;
use App\Engine\Localization\LocaleResolver;
use App\Engine\Localization\LocalizationException;
use App\Engine\Localization\TranslationCatalog;

final class LocaleResolverTest extends LocalizationTestCase
{
    /**
     * @param list<string>          $locales
     * @param array<string, string> $countries
     */
    private function resolver(array $locales, ?string $country = null, array $countries = ['BD' => 'bn', 'US' => 'en']): LocaleResolver
    {
        $files = ['countries.php' => $countries];

        foreach ($locales as $locale) {
            $files[$locale . '.php'] = [];
        }

        $directory = $this->directory($files);

        return new LocaleResolver(new TranslationCatalog($directory), new FakeCountryResolver($country), $directory . '/countries.php');
    }

    private function request(?string $cookie = null, ?string $acceptLanguage = null): Request
    {
        return Request::create('GET', '/', [
            'cookies' => $cookie === null ? [] : ['language' => $cookie],
            'headers' => $acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage],
        ]);
    }

    // ---- each source ------------------------------------------------------

    public function test_the_cookie_is_used_when_its_file_exists(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'])->resolve($this->request(cookie: 'bn')));
    }

    public function test_a_cookie_is_matched_by_its_base_language(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'])->resolve($this->request(cookie: 'bn-BD')));
    }

    public function test_a_cookie_without_a_file_is_ignored(): void
    {
        self::assertSame('en', $this->resolver(['en', 'bn'])->resolve($this->request(cookie: 'xx')));
    }

    public function test_an_invalid_cookie_is_ignored(): void
    {
        foreach (['../../config', 'en/../../', '<script>', '', \str_repeat('b', 300)] as $cookie) {
            self::assertSame('en', $this->resolver(['en', 'bn'])->resolve($this->request(cookie: $cookie, acceptLanguage: 'en')), $cookie);
        }
    }

    public function test_the_country_map_is_used_when_there_is_no_cookie(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'], country: 'BD')->resolve($this->request()));
    }

    public function test_a_country_is_case_insensitive(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'], country: 'bd')->resolve($this->request()));
    }

    public function test_an_unknown_country_falls_through_to_the_browser(): void
    {
        self::assertSame('de', $this->resolver(['en', 'de'], country: 'JP')->resolve($this->request(acceptLanguage: 'de')));
        self::assertSame('de', $this->resolver(['en', 'de'], country: 'XX')->resolve($this->request(acceptLanguage: 'de')));
        self::assertSame('de', $this->resolver(['en', 'de'], country: null)->resolve($this->request(acceptLanguage: 'de')));
    }

    public function test_a_country_mapped_to_a_missing_language_falls_through_to_the_browser(): void
    {
        self::assertSame('de', $this->resolver(['en', 'de'], country: 'BD')->resolve($this->request(acceptLanguage: 'de')));
    }

    public function test_the_browser_is_used_in_its_order_of_preference(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'])->resolve($this->request(acceptLanguage: 'bn-BD,bn;q=0.9,en;q=0.8')));
    }

    public function test_an_unavailable_browser_language_moves_to_the_next(): void
    {
        self::assertSame('en', $this->resolver(['en'])->resolve($this->request(acceptLanguage: 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7')));
    }

    public function test_an_unavailable_browser_language_falls_back_to_english(): void
    {
        self::assertSame('en', $this->resolver(['en'])->resolve($this->request(acceptLanguage: 'fr-FR')));
    }

    public function test_english_is_the_last_resort(): void
    {
        self::assertSame('en', $this->resolver(['en', 'bn'])->resolve($this->request()));
    }

    public function test_without_english_the_first_available_locale_is_used(): void
    {
        self::assertSame('bn', $this->resolver(['bn', 'de'])->resolve($this->request()));
    }

    public function test_with_no_files_at_all_the_answer_is_still_english(): void
    {
        self::assertSame('en', $this->resolver([])->resolve($this->request(cookie: 'bn', acceptLanguage: 'bn')));
    }

    public function test_without_a_request_the_answer_is_english(): void
    {
        self::assertSame('en', $this->resolver(['en', 'bn'], country: 'BD')->resolve(null));
    }

    // ---- priority ---------------------------------------------------------

    public function test_the_cookie_beats_the_country(): void
    {
        self::assertSame('en', $this->resolver(['en', 'bn'], country: 'BD')->resolve($this->request(cookie: 'en', acceptLanguage: 'bn-BD')));
    }

    public function test_the_country_beats_the_browser(): void
    {
        self::assertSame('bn', $this->resolver(['en', 'bn'], country: 'BD')->resolve($this->request(acceptLanguage: 'en-US,en;q=0.9')));
    }

    public function test_the_browser_beats_the_english_fallback(): void
    {
        self::assertSame('de', $this->resolver(['en', 'de'])->resolve($this->request(acceptLanguage: 'de')));
    }

    public function test_a_valid_cookie_means_the_country_is_never_asked(): void
    {
        $directory = $this->directory(['en.php' => [], 'bn.php' => []]);
        $countries = new FakeCountryResolver('BD');

        (new LocaleResolver(new TranslationCatalog($directory), $countries))->resolve($this->request(cookie: 'bn'));

        self::assertSame(0, $countries->asked);
    }

    // ---- the country map --------------------------------------------------

    public function test_a_missing_country_map_means_no_country_policy(): void
    {
        $directory = $this->directory(['en.php' => [], 'bn.php' => []]);
        $resolver = new LocaleResolver(new TranslationCatalog($directory), new FakeCountryResolver('BD'), $directory . '/countries.php');

        self::assertSame('en', $resolver->resolve($this->request()));
    }

    public function test_a_country_map_that_is_not_an_array_is_refused(): void
    {
        $directory = $this->directory(['en.php' => [], 'countries.php' => "<?php return 'bn';"]);
        $resolver = new LocaleResolver(new TranslationCatalog($directory), new FakeCountryResolver('BD'), $directory . '/countries.php');

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('returns string instead of an array');

        $resolver->resolve($this->request());
    }

    public function test_a_country_map_with_a_bad_code_is_refused(): void
    {
        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('two-letter country codes');

        $this->resolver(['en'], country: 'BD', countries: ['Bangladesh' => 'bn'])->resolve($this->request());
    }

    public function test_match_tries_each_less_specific_form(): void
    {
        $resolver = $this->resolver(['en', 'pt', 'zh-Hant']);

        self::assertSame('pt', $resolver->match('pt-BR'));
        self::assertSame('zh-Hant', $resolver->match('zh-Hant-TW'));
        self::assertNull($resolver->match('de'));
        self::assertNull($resolver->match('../en'));
    }
}
