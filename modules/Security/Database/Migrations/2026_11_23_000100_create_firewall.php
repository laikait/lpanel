<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // The firewall's two address lists. Both are sent to the server whole
        // (firewall.lists) whenever one changes. A deny entry may end by
        // itself (expires_at), and says where it came from (source: a person,
        // or later the brute-force blocker).
        $tables->create('firewall_entries', static function (Table $table): void {
            $table->id();
            $table->string('list', 5);
            $table->string('cidr', 49);
            $table->string('note', 120)->default('');
            $table->string('source', 12)->default('manual');
            $table->bigInteger('created_by')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('created_at')->nullable();
            $table->unique('list', 'cidr');
        });

        // The countries blocked (two-letter codes).
        $tables->create('firewall_countries', static function (Table $table): void {
            $table->id();
            $table->string('code', 2)->unique();
            $table->dateTime('created_at')->nullable();
        });

        // When each country's list was last fetched and what the server holds
        // (a single row per name), and the state last sent.
        $tables->create('firewall_settings', static function (Table $table): void {
            $table->string('name', 64)->unique();
            $table->string('value', 255);
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('firewall_settings');
        $tables->drop('firewall_countries');
        $tables->drop('firewall_entries');
    }
};
