<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Set while the hosting account a login belongs to is suspended.
        // Separate from `active`, which an administrator sets by hand, so
        // unsuspending never re-enables a login someone disabled on purpose.
        $tables->alter('panel_users', static function (Table $table): void {
            $table->boolean('suspended')->default(false);
        });

        $tables->create('api_tokens', static function (Table $table): void {
            $table->id();
            $table->bigInteger('user_id')->index();
            $table->string('name', 80);
            // SHA-256 of the token; the token itself is shown once and never stored.
            $table->string('token_hash', 64)->unique();
            // The first characters, so a person can tell their tokens apart.
            $table->string('prefix', 12);
            $table->dateTime('created_at');
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('api_tokens');
        $tables->alter('panel_users', static function (Table $table): void {
            $table->dropColumn('suspended');
        });
    }
};
