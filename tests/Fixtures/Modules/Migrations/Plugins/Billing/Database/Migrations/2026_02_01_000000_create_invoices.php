<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('laika_mig_invoices', static function (Table $table): void {
            $table->id();
            $table->bigInteger('customer_id')->index();
            $table->decimal('total', 12, 2)->default('0.00');
            $table->foreign('customer_id')->references('laika_mig_customers');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('laika_mig_invoices');
    }
};
