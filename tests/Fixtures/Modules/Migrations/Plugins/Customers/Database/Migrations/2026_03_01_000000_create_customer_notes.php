<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

/**
 * Dated after Billing's invoices, and still run before them: Billing requires
 * Customers, so every Customers migration comes first, whatever the dates say.
 */
return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('laika_mig_customer_notes', static function (Table $table): void {
            $table->id();
            $table->bigInteger('customer_id');
            $table->text('body');
            $table->foreign('customer_id')->references('laika_mig_customers')->onDelete('cascade');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('laika_mig_customer_notes');
    }
};
