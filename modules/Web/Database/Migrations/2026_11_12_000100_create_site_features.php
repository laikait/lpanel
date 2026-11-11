<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        $tables->alter('domains', static function (Table $table): void {
            // "www" sends visitors to www.<name>, "bare" away from it; null leaves both.
            $table->string('canonical', 4)->nullable();
            // {"404": "/errors/404.html", …}
            $table->text('error_pages')->nullable();
            // {"extensions": ["jpg", …], "allow": ["friend.example", …]}, or null when off.
            $table->text('hotlink')->nullable();
        });

        $tables->create('web_redirects', static function (Table $table): void {
            $table->id();
            $table->bigInteger('domain_id')->index();
            $table->string('path', 500);
            $table->text('target');
            $table->integer('code');
            $table->dateTime('created_at');
        });

        // Folders that ask for a user name and password. The passwords are
        // stored as bcrypt hashes only, which is what Apache checks.
        $tables->create('web_protected', static function (Table $table): void {
            $table->id();
            $table->bigInteger('domain_id')->index();
            // Relative to the site's folder; "" is the whole site.
            $table->string('path', 255);
            $table->string('realm', 60);
            // [{"name": "ann", "hash": "$2y$10$…"}, …]
            $table->text('users');
            $table->dateTime('created_at');
        });

        // The owner's php.ini choices, per PHP version their sites use.
        $tables->create('php_settings', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('version', 2);
            $table->string('directive', 40);
            $table->string('value', 64);
            $table->unique('account_id', 'version', 'directive');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('php_settings');
        $tables->drop('web_protected');
        $tables->drop('web_redirects');
        $tables->alter('domains', static function (Table $table): void {
            $table->dropColumn('canonical');
            $table->dropColumn('error_pages');
            $table->dropColumn('hotlink');
        });
    }
};
