<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Modules\Nav\Schema;

use App\Engine\Schema\Field;
use App\Engine\Schema\Schema;

/**
 * What the create and edit forms accept. `location` and `enabled` are
 * required here even though a blank one is a mistake worth catching at the
 * form rather than only at the database -- an empty location would silently
 * put an item nowhere any tree() call ever looks for it.
 */
final class NavItemSchema
{
    public static function input(): Schema
    {
        return Schema::of(
            'nav.input',
            Field::int('parentId')->optional()->nullable()->describedAs('The parent item, or none for a top-level entry.'),
            Field::string('location')->length(1, 40)->describedAs('Which menu this belongs to: header, footer, admin_sidebar, or your own name.'),
            Field::string('label')->length(1, 120)->describedAs('The text shown to a visitor.'),
            Field::string('url')->optional()->nullable()->length(0, 255)->describedAs('A literal href, when this does not point at a named route.'),
            Field::string('route')->optional()->nullable()->length(0, 120)->describedAs('A route name, resolved to a URL when the menu is rendered.'),
            Field::string('capability')->optional()->nullable()->length(0, 120)->describedAs('A capability an identity must hold to see this item, or none.'),
            Field::int('order')->describedAs('Where this sits among its siblings; lower comes first.'),
            Field::bool('enabled')->describedAs('Whether this item appears in a rendered menu at all.'),
        );
    }
}
