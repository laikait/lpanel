<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->alter('domains', static function (Table $table): void {
            // {"mode": "off|detect|block", "exclude": [942100, …]}, or null: the server's default mode.
            $table->text('waf')->nullable();
        });

        // What the web application firewall's rules said about requests, read
        // from the sites' logs. Kept for two weeks.
        $tables->create('waf_events', static function (Table $table): void {
            $table->id();
            $table->dateTime('seen_at')->index();
            $table->string('account', 32)->index();
            $table->string('host', 255)->index();
            $table->string('ip', 45);
            $table->string('uri', 300);
            $table->boolean('blocked')->default(false);
            $table->integer('score')->default(0);
            // [{"id": 942100, "msg": "SQL Injection Attack Detected via libinjection"}, …]
            $table->text('rules');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('waf_events');
        $tables->alter('domains', static function (Table $table): void {
            $table->dropColumn('waf');
        });
    }
};