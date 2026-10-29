<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

/**
 * One tree, one table. `parent_id` is deliberately not a foreign key: this
 * module is meant to be deletable, and a table nothing else points at (and
 * that points at nothing else) is one a fresh install can drop without
 * touching any other module's schema. A `parent_id` naming a row that is
 * gone -- its parent deleted, or this table dropped and recreated -- is not
 * an error the database needs to catch; the tree builder treats it as a
 * root.
 *
 * `location` groups the tree into whichever menu is being asked for --
 * `header`, `footer`, `admin_sidebar`, `user_panel` -- with no other table
 * and no other mechanism per menu.
 */
return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Column names match NavItem's constructor parameters exactly,
        // including case: there is no snake_case/camelCase conversion between
        // a row and a model (docs/reference/models.md, "A property is never
        // filled").
        $tables->create('nav_items', static function (Table $table): void {
            $table->id();
            $table->bigInteger('parentId')->nullable()->index();
            $table->string('location', 40)->index();
            $table->string('label', 120);
            $table->string('url', 255)->nullable();
            $table->string('route', 120)->nullable();
            $table->string('capability', 120)->nullable();
            $table->integer('order')->default(0);
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('nav_items');
    }
};
