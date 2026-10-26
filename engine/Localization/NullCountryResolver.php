<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;

/** No country detection: the default, and what a test uses to switch it off. */
final class NullCountryResolver implements CountryResolver
{
    public function country(Request $request): ?string
    {
        return null;
    }
}
