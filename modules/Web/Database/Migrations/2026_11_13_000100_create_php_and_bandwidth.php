<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Server-wide web settings, such as the PHP version new sites get.
        $tables->create('web_settings', static function (Table $table): void {
            $table->string('name', 64)->unique();
            $table->string('value', 255);
        });

        // Installing and removing PHP versions and extensions takes minutes,
        // so the agent runs it in the background. Each job is recorded with
        // its operation id before it is sent, and web:settle asks again
        // until the agent has an answer.
        $tables->create('php_jobs', static function (Table $table): void {
            $table->id();
            // php.install, php.remove or php.extensions.
            $table->string('op', 16);
            $table->string('version', 2);
            $table->string('op_id', 32)->unique();
            $table->text('op_args');
            // running, done or failed.
            $table->string('state', 8)->index();
            $table->text('error')->nullable();
            $table->bigInteger('actor_id')->nullable();
            $table->string('actor_name', 120)->default('');
            $table->dateTime('created_at');
            $table->dateTime('finished_at')->nullable();
        });

        // Bytes each account's sites sent and received, per day, as the
        // agent counts them from the access logs. The agent answers running
        // totals, so a day's row is replaced, never added to.
        $tables->create('web_traffic', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id');
            $table->date('day');
            $table->bigInteger('bytes')->default(0);
            $table->unique('account_id', 'day');
        });

        $tables->alter('web_accounts', static function (Table $table): void {
            // The account has used its bandwidth for the month and its
            // package says to stop its sites until the next.
            $table->boolean('limited')->default(false);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->alter('web_accounts', static function (Table $table): void {
            $table->dropColumn('limited');
        });
        $tables->drop('web_traffic');
        $tables->drop('php_jobs');
        $tables->drop('web_settings');
    }
};
