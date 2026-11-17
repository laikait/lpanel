<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Until when the server keeps a webmail sign-on password for the
        // mailbox (the agent gives the same one while it is good); past it,
        // mail:settle asks the server to forget it.
        $tables->alter('mail_boxes', static function (Table $table): void {
            $table->dateTime('webmail_until')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->alter('mail_boxes', static function (Table $table): void {
            $table->dropColumn('webmail_until');
        });
    }
};
