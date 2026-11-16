<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Server-wide mail settings, by name: smarthost (a relay host for
        // outgoing mail).
        $tables->create('mail_settings', static function (Table $table): void {
            $table->string('name', 40)->unique();
            $table->text('value');
        });

        // Every domain of an account is a mail domain (main, addon, alias
        // and subdomains alike); a row follows its domain.
        $tables->create('mail_domains', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('domain_id')->unique();
            $table->string('name', 253)->unique();
            // false when the domain's mail is delivered elsewhere (its MX is
            // another server's); its mailboxes may still log in and send.
            $table->boolean('local')->default(true);
            // null refuses mail to unknown addresses; ":blackhole:" discards
            // it; an address receives it.
            $table->string('catchall', 254)->nullable();
            // The DKIM public key as its TXT record reads, from the agent.
            $table->text('dkim')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        $tables->create('mail_boxes', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('mail_domain_id')->index();
            $table->string('local', 64);
            // bcrypt; the password itself is never kept.
            $table->string('hash', 100);
            // 0: no cap of its own (the account's disk space still applies).
            $table->integer('quota_mb')->default(0);
            $table->bigInteger('used_bytes')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique('mail_domain_id', 'local');
        });

        // One row per destination: info@ to a@ and b@ is two rows.
        $tables->create('mail_forwarders', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('mail_domain_id')->index();
            $table->string('local', 64);
            $table->string('destination', 254);
            $table->dateTime('created_at')->nullable();
            $table->unique('mail_domain_id', 'local', 'destination');
        });

        $tables->create('mail_responders', static function (Table $table): void {
            $table->id();
            $table->bigInteger('mailbox_id')->unique();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('from_name', 100)->nullable();
            // Each sender is answered once in this many days.
            $table->integer('days')->default(1);
            // YYYY-MM-DD, either may be empty.
            $table->string('starts_on', 10)->nullable();
            $table->string('ends_on', 10)->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // The state last sent to the server for each account (mail.apply)
        // and what became of it, like db_accounts.
        $tables->create('mail_accounts', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->unique();
            $table->integer('revision')->default(0);
            $table->integer('applied_revision')->default(0);
            $table->string('op_id', 32)->nullable();
            $table->text('op_args')->nullable();
            $table->string('state', 8)->index();
            $table->text('error')->nullable();
            $table->dateTime('changed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
        });

        // Mail to delete once the server no longer has the mailbox (local) or
        // the domain (local empty) set up: mail:settle finishes these.
        $tables->create('mail_purges', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->string('domain', 253);
            $table->string('local', 64);
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        foreach (['mail_purges', 'mail_accounts', 'mail_responders', 'mail_forwarders', 'mail_boxes', 'mail_domains', 'mail_settings'] as $table) {
            $tables->drop($table);
        }
    }
};
