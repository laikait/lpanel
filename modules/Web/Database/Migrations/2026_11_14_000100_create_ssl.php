<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Each site's certificate and its AutoSSL state: one row per main or
        // addon/sub domain that has ever had either. The key and the chain
        // stay on the server (the agent's store); the panel knows the
        // certificate by its id.
        $tables->create('ssl_sites', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('domain_id')->unique();
            // AutoSSL looks after this site; force sends plain HTTP to HTTPS.
            $table->boolean('autossl')->default(true);
            $table->boolean('force_https')->default(true);
            // The certificate served now, if any.
            $table->string('cert_id', 16)->nullable();
            // auto (AutoSSL) or custom (brought by the owner).
            $table->string('source', 8)->nullable();
            $table->text('names')->nullable();
            $table->string('issuer', 200)->nullable();
            $table->string('serial', 64)->nullable();
            $table->string('key_type', 16)->nullable();
            $table->boolean('self_signed')->default(false);
            // letsencrypt or zerossl, for AutoSSL's.
            $table->string('authority', 16)->nullable();
            $table->dateTime('not_before')->nullable();
            $table->dateTime('not_after')->nullable()->index();
            // AutoSSL's last attempt: names left out and why (JSON), the
            // error, how many failures in a row, and when it may try again.
            $table->text('excluded')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('failures')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Getting a certificate takes up to minutes, so the agent does it in
        // the background. Each order is recorded with its operation id before
        // it is sent; ssl:autossl and web:settle ask again until it answers.
        $tables->create('ssl_orders', static function (Table $table): void {
            $table->id();
            // site (an account's site) or host (the server's hostname).
            $table->string('kind', 8);
            $table->bigInteger('account_id')->nullable()->index();
            $table->bigInteger('domain_id')->nullable()->index();
            $table->string('op_id', 32)->unique();
            $table->text('op_args');
            // running, done or failed.
            $table->string('state', 8)->index();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->string('actor_name', 120)->default('');
            $table->dateTime('created_at');
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('ssl_orders');
        $tables->drop('ssl_sites');
    }
};
