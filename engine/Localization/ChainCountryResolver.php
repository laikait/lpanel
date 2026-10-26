<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;

/**
 * Several country sources, asked in order until one knows.
 *
 * Bootstrap puts a trusted CDN header ahead of a local MaxMind database: a
 * CDN that already did the lookup is free, and the database answers for
 * requests that did not come through it.
 */
final class ChainCountryResolver implements CountryResolver
{
    /** @var list<CountryResolver> */
    private readonly array $resolvers;

    public function __construct(CountryResolver ...$resolvers)
    {
        $this->resolvers = \array_values($resolvers);
    }

    /** @return list<CountryResolver> */
    public function resolvers(): array
    {
        return $this->resolvers;
    }

    public function country(Request $request): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $country = Locale::country($resolver->country($request));

            if ($country !== null) {
                return $country;
            }
        }

        return null;
    }
}
