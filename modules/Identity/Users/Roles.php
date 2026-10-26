<?php

declare(strict_types=1);

namespace App\Modules\Identity\Users;

/**
 * The built-in roles. Capabilities are declared in module.php; these are the names.
 *
 * root is never stored: the provider gives it to the system root account and
 * to nobody else, so neither a database edit nor the users API can hand it out.
 */
final class Roles
{
    public const ROOT = 'root';

    public const ADMIN = 'admin';

    public const CUSTOMER = 'customer';

    /** What the users API may assign. */
    public const ASSIGNABLE = [self::ADMIN, self::CUSTOMER];
}
