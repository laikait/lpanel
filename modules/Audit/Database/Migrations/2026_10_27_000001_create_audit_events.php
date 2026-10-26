<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('audit_events', static function (Table $table): void {
            $table->id();
            $table->dateTime('occurred_at')->index();
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable()->index();
            $table->string('actor_name', 190)->nullable();
            $table->string('action', 100)->index();
            $table->string('outcome', 16);
            $table->string('target_type', 50)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('metadata')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('audit_events');
    }
};
