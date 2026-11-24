<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Operations' settings, one row each (updates: what is installed by
        // itself, when; see UpdateSettings).
        $tables->create('ops_settings', static function (Table $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('value', 1000);
        });

        // Each check for updates, update, upgrade of LPanel and reboot: who
        // or what started it, the agent's operation, how it went and what it
        // did. A panel upgrade is "installing" between the agent starting its
        // upgrade script and that script saying how it went.
        $tables->create('ops_update_runs', static function (Table $table): void {
            $table->id();
            $table->string('kind', 8)->index();
            $table->string('scope', 16)->default('');
            $table->string('state', 12)->index();
            $table->string('op_id', 32)->nullable();
            $table->string('target', 64)->default('');
            $table->string('started_by', 64);
            $table->dateTime('started_at')->index();
            $table->dateTime('finished_at')->nullable();
            $table->text('result')->nullable();
            $table->string('error', 500)->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('ops_update_runs');
        $tables->drop('ops_settings');
    }
};
