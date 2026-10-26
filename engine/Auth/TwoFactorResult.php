<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/** What a password got: nothing, a login, or a request for the code. */
enum TwoFactorResult
{
    case Failed;
    case LoggedIn;
    case ChallengeRequired;
}
