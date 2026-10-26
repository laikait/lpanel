<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Database\Connection;

/**
 * Rows a module's tables start with, in a file of its own.
 *
 * `modules/<Kind>/<Name>/Database/Seeders/countries.php`:
 *
 *     return new class implements Seeder {
 *         public function run(Connection $db): void
 *         {
 *             if (!$db->table('countries')->where('code', 'BD')->exists()) {
 *                 $db->table('countries')->insert(['code' => 'BD', 'name' => 'Bangladesh']);
 *             }
 *         }
 *     };
 *
 * It writes through the query builder, so one seeder fills every database.
 * Nothing records that it ran: `db:seed` runs every seeder every time, so a
 * seeder looks before it inserts, and running it twice changes nothing.
 */
interface Seeder
{
    public function run(Connection $db): void;
}
