<?php

declare(strict_types=1);

namespace App\Modules\Identity;

/**
 * The four tiers, plus root. A login's kind decides which interface it signs in
 * to and which role it holds; it never changes after the login is created.
 */
enum Kind: string
{
    case Root = 'root';
    case Admin = 'admin';
    case Reseller = 'reseller';
    case User = 'user';
    case Subuser = 'subuser';

    /** @return 'admin'|'user' the interface this kind signs in to */
    public function area(): string
    {
        return match ($this) {
            self::Root, self::Admin, self::Reseller => 'admin',
            self::User, self::Subuser => 'user',
        };
    }

    /** The role declared in Identity's module.php for this kind. */
    public function role(): string
    {
        return $this->value;
    }
}
