<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Restores and downloads: what was asked of which snapshot, and how
        // it went. op_id is the agent's background operation. An account
        // made again from a backup waits in "creating" until the server has
        // made it, then its files and databases are put back ("running").
        $tables->create('backup_restores', static function (Table $table): void {
            $table->id();
            // Null while an account being made again has no row yet.
            $table->bigInteger('account_id')->nullable()->index();
            $table->string('username', 16)->index();
            $table->bigInteger('destination_id');
            $table->string('snapshot', 64);
            // files, mailbox, database, account (all of it) or download.
            $table->string('kind', 12);
            // What: paths, databases, folder (JSON).
            $table->text('what');
            // creating, running, done or failed.
            $table->string('state', 8)->index();
            $table->string('op_id', 32)->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->string('requested_by', 64)->default('');
            // Asked for in the User panel, by the account's owner or a sub-user.
            $table->boolean('by_owner')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('backup_restores');
    }
};
