<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->alter('packages', static function (Table $table): void {
            // block or warn (Packages::BANDWIDTH_ACTIONS).
            $table->string('bandwidth_action', 8)->default('block');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->alter('packages', static function (Table $table): void {
            $table->dropColumn('bandwidth_action');
        });
    }
};
