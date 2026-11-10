<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('panel_users', static function (Table $table): void {
            $table->id();
            $table->string('username', 64)->unique();
            // root, admin, reseller, user or subuser; see Identity\Kind.
            $table->string('kind', 16)->index();
            $table->string('name', 120)->default('');
            $table->string('email', 190)->nullable();
            // Null for root, whose password is the server's and is checked by authd.
            $table->string('password_hash', 255)->nullable();
            // The reseller who owns a user, or the user who owns a sub-user.
            $table->bigInteger('parent_id')->nullable()->index();
            $table->boolean('active')->default(true);
            // Encrypted with APP_KEY; whoever reads it can make codes.
            $table->text('totp_secret')->nullable();
            $table->bigInteger('totp_step')->nullable();
            // JSON list of password hashes of unused recovery codes.
            $table->text('recovery_codes')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('panel_users');
    }
};
