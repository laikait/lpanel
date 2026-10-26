<?php

declare(strict_types=1);

// The language a visitor from each country is shown first, when they have not
// chosen one with the language cookie. This is the application's policy, not a
// claim that a country speaks one language, and a locale named here is used
// only if lang/<locale>.php exists. Countries come from a CountryResolver; see
// docs/reference/localization.md.

return [
    'BD' => 'bn',
    'US' => 'en',
    'GB' => 'en',
];
