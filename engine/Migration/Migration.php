<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\Structure\Tables;

/**
 * One change to a module's tables, in a file of its own.
 *
 * `modules/<Kind>/<Name>/Database/Migrations/2026_09_19_120000_create_invoices.php`:
 *
 *     return new class implements Migration {
 *         public function up(Tables $tables): void
 *         {
 *             $tables->create('invoices', static function (Table $table): void { ... });
 *         }
 *     };
 *
 * The file returns the migration, the way module.php returns its closure. A
 * migration that can be undone implements Reversible instead.
 */
interface Migration
{
    public function up(Tables $tables): void;
}
