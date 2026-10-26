<?php

declare(strict_types=1);

namespace App\Engine\Auth;

/** What to show while two-factor login is being switched on. */
final class TwoFactorEnrolment
{
    public function __construct(
        /** For typing in, when the camera will not cooperate. */
        public readonly string $secret,
        /** The otpauth:// URI the QR code holds. */
        public readonly string $uri,
        /** The QR code, as an SVG document to put in the page. */
        public readonly string $qrSvg,
    ) {}
}
