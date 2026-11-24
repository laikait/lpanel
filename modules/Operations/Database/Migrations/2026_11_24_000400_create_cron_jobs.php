<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // An account's scheduled jobs. The server runs them as the account
        // (cron.apply sends the whole list), at most one in five minutes each.
        $tables->create('cron_jobs', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('schedule', 100);
            $table->string('command', 1000);
            $table->dateTime('created_at');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('cron_jobs');
    }
};
