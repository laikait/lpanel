<?php

declare(strict_types=1);

namespace App\Engine\Error;

use App\Engine\Http\Request;

/**
 * Who is about to read this error.
 *
 * The specification asks for four renderings -- an HTML page, a JSON document,
 * console output, and a safe generic version in production -- and the fourth is
 * not a fourth context at all. Production versus development decides *how much*
 * is said; this decides *to whom*, and the two are independent.
 *
 * That distinction is the whole reason this type exists. "Is this message safe
 * to show" has no answer until you know who is reading it:
 *
 *   Browser   a person who typed a URL. They did not cause the bug and cannot
 *             fix it, and anything beyond "this page is not available" is at
 *             best noise and at worst a map of the application.
 *
 *   Api       a program. It needs a status and a stable shape, and it has no
 *             eyes for an apology, so the body is machine-readable.
 *
 *   Console   an operator on the machine, who already has the source, the
 *             configuration and the directory listing. Withholding a
 *             framework-authored message from them protects nobody and costs
 *             them an afternoon.
 *
 * None of these ever sees a message the framework did not write; that rule
 * lives on FrameworkException and applies on top of this one.
 */
enum ErrorContext: string
{
    case Browser = 'browser';

    case Api = 'api';

    case Console = 'console';

    /** Which of the two web audiences a request belongs to. */
    public static function of(Request $request): self
    {
        return $request->expectsJson() ? self::Api : self::Browser;
    }

    /** Whether the reader is a program rather than a person. */
    public function isMachine(): bool
    {
        return $this === self::Api;
    }

    /**
     * Whether a framework-authored message survives outside debug mode.
     *
     * Only the console. A duplicate command name or a module that failed to
     * load names modules and files -- useful on a terminal, an inventory of the
     * application anywhere else.
     */
    public function disclosesFrameworkMessages(): bool
    {
        return $this === self::Console;
    }
}
