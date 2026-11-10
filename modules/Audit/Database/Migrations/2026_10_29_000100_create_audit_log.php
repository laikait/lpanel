<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('audit_log', static function (Table $table): void {
            $table->id();
            $table->dateTime('created_at')->index();
            // Who: null for an unknown visitor (a failed sign-in).
            $table->string('actor_id', 32)->nullable()->index();
            $table->string('actor_name', 120)->default('');
            $table->string('actor_kind', 16)->default('');
            // Set while someone acts through another person's login.
            $table->string('on_behalf_of', 32)->nullable();
            $table->string('ip', 45)->nullable();
            // What: a dotted verb such as "auth.login" or "staff.create".
            $table->string('action', 64)->index();
            $table->string('subject', 190)->default('');
            $table->string('outcome', 16)->default('ok');
            $table->text('details')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('audit_log');
    }
};
