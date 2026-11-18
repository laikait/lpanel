<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Each account's SSH public keys. A private key the panel made is
        // shown once and never kept.
        $tables->create('ssh_keys', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('name', 64);
            $table->string('type', 64);
            $table->text('public_key');
            // SHA256:<base64>, as ssh-keygen -l shows it.
            $table->string('fingerprint', 64);
            $table->integer('bits')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->unique('account_id', 'fingerprint');
        });

        // The keys last sent to the server for each account (ssh.apply), as
        // ftp_accounts does for FTP logins.
        $tables->create('ssh_accounts', static function (Table $table): void {
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
    }

    public function down(Tables $tables): void
    {
        foreach (['ssh_accounts', 'ssh_keys'] as $table) {
            $tables->drop($table);
        }
    }
};
