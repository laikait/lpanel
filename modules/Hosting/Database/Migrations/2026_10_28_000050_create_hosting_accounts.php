<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // A hosting account on one server (ADR-0013): what the panel wants it
        // to be, and the operation under way to get it there. Deleting one is
        // a state change ("deleting" -> "deleted"), never a row removed.
        $tables->create('hosting_accounts', static function (Table $table): void {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->bigInteger('server_id')->index();
            // The customer it belongs to: users.uid.
            $table->string('owner_uid', 26)->index();
            $table->string('label', 100);
            // lp + 8 of [a-z2-7], chosen by the panel; unique per server.
            $table->string('system_user', 10);
            $table->integer('system_uid')->nullable();
            $table->integer('system_gid')->nullable();
            $table->string('php_version', 8)->nullable();
            $table->string('status', 16);
            // Where it was before the action under way, to go back to if that fails.
            $table->string('previous_status', 16)->nullable();
            // create, suspend, unsuspend or delete: the newest asked for.
            $table->string('last_action', 16);
            $table->string('operation_uid', 36)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error', 500)->nullable();
            // For later limits (websites, disk); stored, not yet enforced.
            $table->text('limits')->nullable();
            $table->string('created_by', 26)->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
            $table->unique('server_id', 'system_user');
            $table->index('status', 'id');
            $table->foreign('server_id')->references('servers');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('hosting_accounts');
    }
};
