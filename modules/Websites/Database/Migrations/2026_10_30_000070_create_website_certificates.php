<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // A website's certificate (ADR-0015): what the server serves it with
        // over HTTPS, and when LPanel next asks for one on its own. The key
        // never leaves the server; nothing secret is here.
        $tables->create('website_certificates', static function (Table $table): void {
            $table->id();
            $table->bigInteger('website_id')->unique();
            // Whether LPanel gets and renews it on its own; off once someone removes it.
            $table->boolean('auto')->default(true);
            // JSON list: the domains it covers.
            $table->text('domains')->nullable();
            $table->string('issuer', 100)->nullable();
            $table->string('serial', 64)->nullable();
            $table->dateTime('not_before')->nullable();
            $table->dateTime('not_after')->nullable();
            $table->dateTime('issued_at')->nullable();
            // Failures in a row, since the last one issued (or since the first try).
            $table->integer('attempts')->default(0);
            // When the scheduler next asks; null: not on its own.
            $table->dateTime('next_attempt_at')->nullable()->index();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->foreign('website_id')->references('websites');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('website_certificates');
    }
};
