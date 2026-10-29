<?php

declare(strict_types=1);

use App\Engine\Database\Connection;
use App\Engine\Migration\Seeder;

/** Ada's invoice: it needs Customers' seeder to have run, which module order sees to. */
return new class implements Seeder {
    public function run(Connection $db): void
    {
        $ada = $db->table('laika_mig_customers')->where('name', 'Ada')->first();

        if ($ada === null) {
            throw new \RuntimeException('Customers has not seeded Ada.');
        }

        if (!$db->table('laika_mig_invoices')->where('customer_id', $ada['id'])->exists()) {
            $db->table('laika_mig_invoices')->insert(['customer_id' => $ada['id'], 'total' => '120.00']);
        }
    }
};
