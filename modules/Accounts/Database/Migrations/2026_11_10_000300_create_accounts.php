<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('accounts', static function (Table $table): void {
            $table->id();
            // The account owner's login (panel_users, kind "user"); same name.
            $table->bigInteger('login_id')->unique();
            // The Linux user, and the owner's login name.
            $table->string('username', 16)->unique();
            $table->string('domain', 253)->unique();
            $table->bigInteger('package_id')->index();
            // The reseller's panel_users id, or null for the server's own accounts.
            $table->bigInteger('owner_id')->nullable()->index();
            // creating, active, suspended, terminating or failed.
            $table->string('status', 16)->index();
            $table->string('suspend_reason', 255)->nullable();
            $table->integer('uid')->nullable();
            $table->string('home', 255)->nullable();
            // Last read from the kernel's quota through account.info.
            $table->bigInteger('disk_used_bytes')->nullable();
            $table->bigInteger('inodes_used')->nullable();
            $table->dateTime('usage_at')->nullable();
            // The agent operation in flight, if any. op_id is sent with every
            // retry, so the agent's journal runs it once however often it is asked.
            $table->string('op', 32)->nullable();
            $table->string('op_id', 32)->nullable();
            $table->text('op_args')->nullable();
            $table->text('op_meta')->nullable();
            $table->dateTime('op_started_at')->nullable();
            // Why the last operation failed.
            $table->text('op_error')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('accounts');
    }
};
