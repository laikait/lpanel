<?php

declare(strict_types=1);

namespace App\Engine\Network;

/**
 * The two address families, by the number that names them.
 */
enum IpVersion: int
{
    case V4 = 4;
    case V6 = 6;

    /** How many bits an address of this family has: 32 or 128. */
    public function bits(): int
    {
        return $this === self::V4 ? 32 : 128;
    }

    /** How many bytes the packed (inet_pton) form has: 4 or 16. */
    public function bytes(): int
    {
        return $this === self::V4 ? 4 : 16;
    }
}
