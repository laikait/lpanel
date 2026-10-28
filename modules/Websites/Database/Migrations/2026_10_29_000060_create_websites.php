<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // A website of a hosting account (ADR-0014): the settings the server
        // serves, the ones asked for while a change is under way, and what the
        // last check found in its Nginx file. Deleting is a state change.
        $tables->create('websites', static function (Table $table): void {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->bigInteger('server_id')->index();
            $table->bigInteger('account_id')->index();
            // The account's customer, copied so a customer's list needs no join.
            $table->string('owner_uid', 26)->index();
            // The primary domain, for lists; every domain is in settings.
            $table->string('domain', 253);
            // JSON: domains, document_root, php, index, redirects, headers.
            $table->text('settings');
            // The settings of an update under way; they replace settings once served.
            $table->text('pending_settings')->nullable();
            $table->string('status', 16);
            $table->string('previous_status', 16)->nullable();
            $table->string('last_action', 16);
            $table->string('operation_uid', 36)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error', 500)->nullable();
            // What the server reported serving: the probe answers with it.
            $table->string('revision', 16)->nullable();
            // ok, edited (by hand, outside LPanel) or missing: from websites.check and refusals.
            $table->string('config_state', 16);
            $table->dateTime('config_checked_at')->nullable();
            $table->string('created_by', 26)->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->timestamps();
            $table->index('status', 'id');
            $table->foreign('server_id')->references('servers');
            $table->foreign('account_id')->references('hosting_accounts');
        });

        // One row per domain a website answers to, or is about to: a domain
        // belongs to one website per server. Released when a website goes.
        $tables->create('website_domains', static function (Table $table): void {
            $table->id();
            $table->bigInteger('server_id');
            $table->bigInteger('website_id')->index();
            $table->string('domain', 253);
            $table->unique('server_id', 'domain');
            $table->foreign('website_id')->references('websites');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('website_domains');
        $tables->drop('websites');
    }
};
