<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Where alerts go: an address, a Telegram chat, a Slack-style webhook or
        // a webhook of the owner's. The secrets (bot token, webhook address,
        // signing key) are sealed with the panel's key in `secret`; `target`
        // is only what is safe to show.
        $tables->create('ops_alert_channels', static function (Table $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('kind', 10);
            $table->string('target', 255)->default('');
            $table->text('secret')->nullable();
            // Categories of alert it receives, space-separated ("*" for all).
            $table->string('categories', 200)->default('*');
            $table->string('min_severity', 8)->default('warning');
            $table->boolean('enabled')->default(true);
            $table->dateTime('created_at');
            $table->dateTime('last_ok_at')->nullable();
            $table->string('last_error', 300)->nullable();
        });

        // What was raised: one row per alert, whether or not it was sent. A
        // repeat of the same alert inside the quiet period is kept as
        // `suppressed`, so the history shows it without it reaching anyone.
        $tables->create('ops_alerts', static function (Table $table): void {
            $table->id();
            $table->string('category', 16)->index();
            $table->string('alert_key', 120)->index();
            $table->string('severity', 8);
            $table->string('title', 200);
            $table->string('detail', 1000)->default('');
            $table->boolean('suppressed')->default(false);
            $table->integer('sent')->default(0);
            $table->integer('failed')->default(0);
            $table->dateTime('at')->index();
        });

        // Each attempt to deliver, so a channel that does not work says why.
        $tables->create('ops_alert_deliveries', static function (Table $table): void {
            $table->id();
            $table->bigInteger('alert_id')->index();
            $table->bigInteger('channel_id')->index();
            $table->boolean('ok');
            $table->string('error', 300)->nullable();
            $table->dateTime('at');
        });

        // Conditions that hold now (a full disk, a certificate about to
        // expire): told once when they begin, again if they last a day, and
        // when they end.
        $tables->create('ops_alert_active', static function (Table $table): void {
            $table->id();
            $table->string('alert_key', 120)->unique();
            $table->dateTime('since');
            $table->dateTime('told_at');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('ops_alert_active');
        $tables->drop('ops_alert_deliveries');
        $tables->drop('ops_alerts');
        $tables->drop('ops_alert_channels');
    }
};
