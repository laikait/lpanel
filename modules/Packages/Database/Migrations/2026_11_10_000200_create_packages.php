<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('packages', static function (Table $table): void {
            $table->id();
            // The reseller's panel_users id, or null for the server's own packages.
            $table->bigInteger('owner_id')->nullable()->index();
            $table->string('name', 64);
            // Limits; 0 means unlimited.
            $table->bigInteger('disk_mb')->default(0);
            $table->bigInteger('inodes')->default(0);
            $table->bigInteger('bandwidth_mb')->default(0);
            $table->integer('domains')->default(0);
            $table->integer('subdomains')->default(0);
            $table->integer('mailboxes')->default(0);
            $table->integer('databases')->default(0);
            $table->integer('ftp_accounts')->default(0);
            // JSON list of Packages::FEATURES.
            $table->text('features');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unique('owner_id', 'name');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('packages');
    }
};
