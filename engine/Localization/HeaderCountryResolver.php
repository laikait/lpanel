<?php

declare(strict_types=1);

namespace App\Engine\Localization;

use App\Engine\Http\Request;

/**
 * The country a CDN or reverse proxy put in a header, such as CF-IPCountry.
 *
 * Any client can send that header, so it is read only when the request came
 * through a proxy the application trusts -- http.trusted_proxies, the same
 * list that decides whether X-Forwarded-For is believed. With no trusted
 * proxy configured the header is never read, whatever it says.
 */
final class HeaderCountryResolver implements CountryResolver
{
    public function __construct(private readonly string $header) {}

    public function header(): string
    {
        return $this->header;
    }

    public function country(Request $request): ?string
    {
        if (!$request->fromTrustedProxy()) {
            return null;
        }

        return Locale::country($request->header($this->header));
    }
}
