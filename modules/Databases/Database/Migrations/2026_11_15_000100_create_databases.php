<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Each account's MariaDB databases, named with its prefix ("bob_shop").
        $tables->create('db_databases', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('name', 64)->unique();
            // Its folder's size when db:usage last measured it.
            $table->bigInteger('size_bytes')->default(0);
            $table->dateTime('created_at')->nullable();
        });

        // Its database users. Only MariaDB's own password hash is kept, the
        // one MariaDB itself stores; the password is never kept anywhere.
        $tables->create('db_users', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('name', 32)->unique();
            $table->string('hash', 41);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // What each user may do in each database: a JSON list of privileges,
        // or ["ALL"].
        $tables->create('db_grants', static function (Table $table): void {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->bigInteger('database_id')->index();
            $table->text('privileges');
            $table->unique('user_id', 'database_id');
        });

        // Hosts other than this server the account's users may connect from.
        $tables->create('db_hosts', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('host', 60);
            $table->string('note', 100)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique('account_id', 'host');
        });

        // The state last sent to the server for each account (db.apply), as
        // web_accounts does for sites; and when its phpMyAdmin login was made.
        $tables->create('db_accounts', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->unique();
            $table->integer('revision')->default(0);
            $table->integer('applied_revision')->default(0);
            $table->string('op_id', 32)->nullable();
            $table->text('op_args')->nullable();
            // pending, applied or failed.
            $table->string('state', 8)->index();
            $table->text('error')->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            // Database space last taken off the home's quota, in KiB.
            $table->bigInteger('quota_kib')->default(0);
            $table->dateTime('sso_at')->nullable()->index();
        });
    }

    public function down(Tables $tables): void
    {
        foreach (['db_accounts', 'db_hosts', 'db_grants', 'db_users', 'db_databases'] as $table) {
            $tables->drop($table);
        }
    }
};
