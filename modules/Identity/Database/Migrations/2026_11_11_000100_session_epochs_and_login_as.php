<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Raised to sign a login out everywhere: a session remembers the
        // value it signed in with and stops working when they differ.
        $tables->alter('panel_users', static function (Table $table): void {
            $table->integer('session_epoch')->default(0);
        });

        // "Log in as" into the other interface: a one-use ticket, good for a
        // minute, carried from the Admin panel to the User panel's port.
        $tables->create('login_handoffs', static function (Table $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->bigInteger('target_id');
            $table->bigInteger('by_id');
            $table->string('by_name', 64);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('login_handoffs');
        $tables->alter('panel_users', static function (Table $table): void {
            $table->dropColumn('session_epoch');
        });
    }
};
