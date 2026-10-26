<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;

/**
 * Which country a request comes from, as far as this deployment can tell.
 *
 * The answer is an ISO 3166 alpha-2 code or null, and null is an ordinary
 * answer: locale resolution moves on to the browser's preference. How the
 * country is found -- a CDN header, a local GeoIP database, something else --
 * is the implementation's business, and localization never sees an address.
 *
 * An application supplies its own by binding this interface in a module's
 * services(). The framework ships NullCountryResolver and, when
 * localization.country_header is set, HeaderCountryResolver.
 */
interface CountryResolver
{
    public function country(Request $request): ?string;
}
