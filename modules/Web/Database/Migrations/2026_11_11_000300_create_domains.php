<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('domains', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            // Lowercase, without a trailing dot. "www." is always an alias of
            // its domain, never a domain of its own.
            $table->string('name', 253)->unique();
            // main (the account's own), addon, sub or alias.
            $table->string('kind', 8);
            // alias: the domain it points to; sub: the domain it is under.
            $table->bigInteger('parent_id')->nullable()->index();
            // The folder it serves, relative to the home (none for an alias).
            $table->string('docroot', 255)->nullable();
            // PHP version, two digits such as "83" (none for an alias).
            $table->string('php', 2)->nullable();
            // Also answers every subdomain of it (*.name).
            $table->boolean('wildcard')->default(false);
            $table->dateTime('created_at');
        });

        // Each account's web state on the server. Every change raises the
        // revision and records the whole state with a new operation id;
        // web:settle sends it again until the agent has answered.
        $tables->create('web_accounts', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->unique();
            $table->integer('revision')->default(0);
            $table->integer('applied_revision')->default(0);
            // pending, applied or failed.
            $table->string('state', 8)->index();
            $table->string('op_id', 32)->nullable();
            $table->text('op_args')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('web_accounts');
        $tables->drop('domains');
    }
};
