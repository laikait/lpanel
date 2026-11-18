<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Each account's FTP logins ("bob@example.com"), unique on the
        // server. Only the bcrypt hash Pure-FTPd checks is kept, never the
        // password. The folder is where the login is shut in, from the home
        // ("" for the whole home).
        $tables->create('ftp_users', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('login', 100)->unique();
            $table->string('hash', 60);
            $table->text('folder');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // The state last sent to the server for each account (ftp.apply),
        // as db_accounts does for databases.
        $tables->create('ftp_accounts', static function (Table $table): void {
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
        });

        // The server's FTP settings (TLS, passive ports, client limits), as
        // last saved and applied.
        $tables->create('ftp_settings', static function (Table $table): void {
            $table->string('name', 64)->unique();
            $table->string('value', 255);
        });
    }

    public function down(Tables $tables): void
    {
        foreach (['ftp_settings', 'ftp_accounts', 'ftp_users'] as $table) {
            $tables->drop($table);
        }
    }
};
