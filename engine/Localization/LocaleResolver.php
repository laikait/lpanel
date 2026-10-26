<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;

/**
 * Which locale a request should be served in.
 *
 * In this order, and the order is the point:
 *
 *  1. the `language` cookie -- the visitor chose, and a choice outranks a guess;
 *  2. the visitor's country, through the application's country map
 *     (lang/countries.php) -- where the application has a policy for a country,
 *     it outranks a browser that was installed in English;
 *  3. Accept-Language, in the browser's order of preference;
 *  4. en.
 *
 * Every candidate is checked against the application's own lang/ files, and
 * each is tried from most to least specific: a cookie of en-US is satisfied by
 * lang/en.php. A candidate that is not available is skipped, never trusted.
 *
 * This decides a locale and loads no translation.
 */
final class LocaleResolver
{
    public const COOKIE = 'language';

    public const FALLBACK = 'en';

    /** @var array<string, string>|null country => locale, read on first use */
    private ?array $countryMap = null;

    public function __construct(
        private readonly TranslationCatalog $catalog,
        private readonly CountryResolver $countries = new NullCountryResolver(),
        private readonly ?string $countryMapFile = null,
    ) {}

    /** The locale for $request, or the fallback when there is no request (a command, a job). */
    public function resolve(?Request $request): string
    {
        if ($request !== null) {
            foreach ($this->preferences($request) as $preference) {
                $locale = $this->match($preference);

                if ($locale !== null) {
                    return $locale;
                }
            }
        }

        return $this->fallback();
    }

    /**
     * The available locale that best satisfies $tag, or null.
     *
     * pt-BR is satisfied by pt-BR.php, then pt.php.
     */
    public function match(string $tag): ?string
    {
        foreach (Locale::candidates($tag) as $candidate) {
            if ($this->catalog->hasLocale($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function fallback(): string
    {
        if ($this->catalog->hasLocale(self::FALLBACK)) {
            return self::FALLBACK;
        }

        return $this->catalog->locales()[0] ?? self::FALLBACK;
    }

    /**
     * Each source's preference, in priority order, and lazily: a valid cookie
     * means the country is never looked up and the header never parsed.
     *
     * @return \Generator<int, string>
     */
    private function preferences(Request $request): \Generator
    {
        $cookie = $request->cookie(self::COOKIE);

        if ($cookie !== null) {
            yield $cookie;
        }

        $country = Locale::country($this->countries->country($request));

        if ($country !== null && isset($this->countryMap()[$country])) {
            yield $this->countryMap()[$country];
        }

        yield from AcceptLanguage::parse($request->header('Accept-Language'));
    }

    /** @return array<string, string> */
    private function countryMap(): array
    {
        if ($this->countryMap !== null) {
            return $this->countryMap;
        }

        if ($this->countryMapFile === null || !\is_file($this->countryMapFile)) {
            return $this->countryMap = [];
        }

        $map = (static fn(string $__file): mixed => require $__file)($this->countryMapFile);

        if (!\is_array($map)) {
            throw LocalizationException::invalidCountryMap($this->countryMapFile, \sprintf('returns %s instead of an array', \get_debug_type($map)));
        }

        $countries = [];

        foreach ($map as $country => $locale) {
            $code = \is_string($country) ? Locale::country($country) : null;

            if ($code === null || !\is_string($locale)) {
                throw LocalizationException::invalidCountryMap(
                    $this->countryMapFile,
                    \sprintf('maps %s to %s; keys are two-letter country codes and values are locales', \var_export($country, true), \get_debug_type($locale)),
                );
            }

            $countries[$code] = $locale;
        }

        return $this->countryMap = $countries;
    }
}
