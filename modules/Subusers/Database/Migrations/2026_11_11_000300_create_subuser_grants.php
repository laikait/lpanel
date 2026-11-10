<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->create('subuser_grants', static function (Table $table): void {
            $table->id();
            // The sub-user's panel_users id.
            $table->bigInteger('user_id')->unique();
            // JSON list of package features (Packages::FEATURES) the owner granted.
            $table->text('grants');
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('subuser_grants');
    }
};
