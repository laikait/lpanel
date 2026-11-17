<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // How many recipients an account's mail may reach in an hour, from
        // its mailboxes and its sites together (Exim counts them); 0 means
        // unlimited, like every limit.
        $tables->alter('packages', static function (Table $table): void {
            $table->integer('mail_per_hour')->default(0);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->alter('packages', static function (Table $table): void {
            $table->dropColumn('mail_per_hour');
        });
    }
};
