<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('users', static function (Table $table): void {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->string('login', 190)->unique();
            $table->string('name', 190);
            $table->string('email', 190)->nullable();
            // "system" is root, checked by lpanel-authd and never by a hash; "panel" has a panel password.
            $table->string('kind', 16)->default('panel');
            $table->string('password_hash', 255)->nullable();
            $table->string('status', 16)->default('active');
            // Encrypted with Encrypter, context "identity.totp".
            $table->text('totp_secret')->nullable();
            $table->bigInteger('totp_last_step')->nullable();
            $table->text('recovery_codes')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
        });

        $tables->create('user_roles', static function (Table $table): void {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('role', 64);
            $table->unique('user_id', 'role');
            $table->foreign('user_id')->references('users');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('user_roles');
        $tables->drop('users');
    }
};
