<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Space an account uses outside its home: its databases, which live
        // in MariaDB's folder, owned by MariaDB, so the kernel's quota never
        // sees them. It counts against the package's disk space all the same:
        // the home's quota is the package's space less this (Provisioner).
        $tables->alter('accounts', static function (Table $table): void {
            $table->bigInteger('db_used_bytes')->default(0);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->alter('accounts', static function (Table $table): void {
            $table->dropColumn('db_used_bytes');
        });
    }
};
