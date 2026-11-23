<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Failed sign-ins, as the panel's own sign-in and the agent's log
        // scan report them: counted by address in a window, then forgotten.
        $tables->create('bruteforce_failures', static function (Table $table): void {
            $table->id();
            $table->string('ip', 45);
            $table->string('service', 8);
            $table->integer('count')->default(1);
            $table->dateTime('seen_at')->index();
            $table->index('ip', 'seen_at');
        });

        // Every block the blocker made, kept for the page and to make a repeat
        // offender's next block longer.
        $tables->create('bruteforce_bans', static function (Table $table): void {
            $table->id();
            $table->string('cidr', 49);
            $table->string('services', 40);
            $table->integer('failures');
            $table->string('duration', 5);
            $table->dateTime('banned_at')->index();
            $table->index('cidr');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('bruteforce_bans');
        $tables->drop('bruteforce_failures');
    }
};
