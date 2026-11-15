<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Server-wide DNS settings, by name: ip4 and ip6, the addresses new
        // zones' records point at.
        $tables->create('dns_settings', static function (Table $table): void {
            $table->string('name', 40)->unique();
            $table->text('value');
        });

        // Nameservers and the template for new zones: the server's own
        // (owner_id 0) and each reseller's (their login's id).
        $tables->create('dns_profiles', static function (Table $table): void {
            $table->id();
            $table->bigInteger('owner_id')->unique();
            // A JSON list of names, such as ["ns1.example.com", "ns2.example.com"].
            $table->text('nameservers')->nullable();
            // One record a line; null means "the one above" (the server's,
            // or the built-in one).
            $table->text('template')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Every zone on the server: one for each main, addon and alias
        // domain of an account, and the server's own (no account). Like
        // web_accounts, each row also holds the state last sent to the
        // server (dns.apply) and what became of it.
        $tables->create('dns_zones', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->nullable()->index();
            $table->string('name', 253)->unique();
            $table->integer('revision')->default(0);
            $table->integer('applied_revision')->default(0);
            $table->string('op_id', 32)->nullable();
            $table->text('op_args')->nullable();
            // pending, applied, failed, or removing (being deleted on the server).
            $table->string('state', 8)->index();
            $table->text('error')->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        // A zone's records, apart from its SOA and its own NS records, which
        // come from the nameservers of whoever the zone belongs to.
        $tables->create('dns_records', static function (Table $table): void {
            $table->id();
            $table->bigInteger('zone_id')->index();
            // Relative to the zone: "@", "www", "*", "_dmarc", "a.b".
            $table->string('name', 253);
            $table->string('type', 8);
            $table->integer('ttl');
            // As typed: host names in full without the final dot, TXT as
            // plain text, priorities first ("10 mail.example.com").
            $table->text('content');
            // Set on records made for one of the account's domains (a
            // subdomain's address, a wildcard): they go when it goes.
            $table->bigInteger('domain_id')->nullable()->index();
            $table->dateTime('created_at')->nullable();
        });

        // What has been set up for each of an account's domains, so a record
        // the owner changed or removed is not made again.
        $tables->create('dns_domains', static function (Table $table): void {
            $table->id();
            $table->bigInteger('domain_id')->unique();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('zone_id')->index();
            $table->boolean('wildcard')->default(false);
        });
    }

    public function down(Tables $tables): void
    {
        foreach (['dns_domains', 'dns_records', 'dns_zones', 'dns_profiles', 'dns_settings'] as $table) {
            $tables->drop($table);
        }
    }
};
