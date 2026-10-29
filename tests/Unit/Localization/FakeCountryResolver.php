<?php

declare(strict_types=1);

namespace App\Tests\Unit\Localization;

use App\Engine\Http\Request;
use App\Engine\Localization\CountryResolver;

/** A country decided by the test, and a count of how often it was asked. */
final class FakeCountryResolver implements CountryResolver
{
    public int $asked = 0;

    public function __construct(private readonly ?string $country) {}

    public function country(Request $request): ?string
    {
        ++$this->asked;

        return $this->country;
    }
}
