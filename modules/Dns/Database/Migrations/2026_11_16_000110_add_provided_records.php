<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Records another module keeps in a zone for one of the account's
        // domains, such as mail's DKIM key and DMARC policy: their source
        // ("mail.dkim"), so a changed set replaces the old one.
        $tables->alter('dns_records', static function (Table $table): void {
            $table->string('source', 40)->nullable();
        });

        // Which sets have been placed, and what they held then: a set is
        // placed again only when it changes, so a record the owner removed
        // or edited stays that way.
        $tables->create('dns_provided', static function (Table $table): void {
            $table->id();
            $table->bigInteger('zone_id')->index();
            $table->bigInteger('domain_id')->index();
            $table->string('source', 40);
            $table->string('digest', 40);
            $table->unique('zone_id', 'domain_id', 'source');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('dns_provided');
        $tables->alter('dns_records', static function (Table $table): void {
            $table->dropColumn('source');
        });
    }
};
