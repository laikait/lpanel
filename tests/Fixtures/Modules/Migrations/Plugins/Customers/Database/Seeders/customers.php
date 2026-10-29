<?php

declare(strict_types=1);

use App\Engine\Database\Connection;
use App\Engine\Migration\Seeder;

/** Looks before it inserts, so seeding twice leaves one Ada. */
return new class implements Seeder {
    public function run(Connection $db): void
    {
        if (!$db->table('laika_mig_customers')->where('name', 'Ada')->exists()) {
            $db->table('laika_mig_customers')->insert(['name' => 'Ada']);
        }
    }
};
