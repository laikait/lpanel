<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Bumped when a password changes or an administrator signs someone out:
        // a session remembers the number it started with, and one that no
        // longer matches is signed out on its next request.
        $tables->alter('users', static function (Table $table): void {
            $table->integer('session_epoch')->default(1);
        });

        // A token is shown once and stored only as its SHA-256 fingerprint.
        // "prefix" is the first characters, so a person can tell tokens apart.
        $tables->create('api_tokens', static function (Table $table): void {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->bigInteger('user_id')->index();
            $table->string('name', 100);
            $table->string('prefix', 16);
            $table->string('fingerprint', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('users');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('api_tokens');
        $tables->alter('users', static function (Table $table): void {
            $table->dropColumn('session_epoch');
        });
    }
};
