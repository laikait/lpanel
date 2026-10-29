<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Migration;

/** Creates a table, then fails: where structure is transactional, the table goes too. */
return new class implements Migration {
    public function up(Tables $tables): void
    {
        $tables->create('laika_mig_half', static function (Table $table): void {
            $table->id();
        });

        $tables->raw('THIS IS NOT SQL', 'mysql', 'pgsql', 'sqlite', 'sqlsrv');
    }
};
