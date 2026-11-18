<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Where backups go. Only what the Admin panel shows is kept here; the
        // credentials and each repository's password are the agent's.
        $tables->create('backup_destinations', static function (Table $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            // local, sftp, s3 or b2.
            $table->string('type', 8);
            // The settings without secrets, as JSON.
            $table->text('settings');
            // For SFTP: the agent's public key, to authorize on the server.
            $table->text('public_key')->nullable();
            $table->integer('snapshots')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // The schedule and retention, as last saved.
        $tables->create('backup_settings', static function (Table $table): void {
            $table->string('name', 64)->unique();
            $table->string('value', 255);
        });

        // Each run: which accounts, where to, how it went (per account in
        // result). op_id is the agent's background operation.
        $tables->create('backup_runs', static function (Table $table): void {
            $table->id();
            $table->bigInteger('destination_id')->index();
            $table->string('op_id', 32)->nullable();
            // scheduled or manual.
            $table->string('kind', 12);
            // running, done (every account), partial or failed.
            $table->string('state', 8)->index();
            $table->integer('accounts')->default(0);
            $table->integer('ok')->default(0);
            $table->integer('failed')->default(0);
            $table->text('usernames');
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->string('started_by', 64)->default('');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        foreach (['backup_runs', 'backup_settings', 'backup_destinations'] as $table) {
            $tables->drop($table);
        }
    }
};
