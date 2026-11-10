<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('reseller_limits', static function (Table $table): void {
            $table->id();
            // The reseller's panel_users id.
            $table->bigInteger('user_id')->unique();
            // 0 means unlimited.
            $table->integer('max_accounts')->default(0);
            $table->bigInteger('disk_mb')->default(0);
            $table->bigInteger('bandwidth_mb')->default(0);
            // With overselling, the packages' disk and bandwidth may add up to
            // more than the allowance; the count of accounts still applies.
            $table->boolean('oversell')->default(false);
            // JSON list of what the reseller may do (Resellers::ACL).
            $table->text('acl');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('reseller_limits');
    }
};
