<?php

declare(strict_types=1);

namespace App\Engine\Routing;

/**
 * Why a match attempt ended the way it did.
 *
 * The distinction between NotFound and MethodNotAllowed is not cosmetic: a 405
 * must advertise what is allowed, and conflating the two hides real routing
 * mistakes from API clients.
 */
enum MatchStatus
{
    case Matched;
    case NotFound;
    case MethodNotAllowed;
}
