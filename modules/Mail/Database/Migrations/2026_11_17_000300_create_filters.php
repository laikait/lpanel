<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // A mailbox's own filters, run in position order on arrival.
        $tables->create('mail_filters', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->index();
            $table->bigInteger('mailbox_id')->index();
            $table->integer('position')->default(0);
            // from, to, subject
            $table->string('field', 10);
            // contains, is, begins, ends
            $table->string('test', 10);
            $table->string('value', 200);
            // folder, forward, discard
            $table->string('action', 10);
            // The folder, or the address; empty for discard.
            $table->string('target', 254)->nullable();
            $table->boolean('stop')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        // How an account's mailboxes file spam. An account without a row has
        // the defaults (Mailboxes::SPAM_DEFAULTS).
        $tables->create('mail_spam', static function (Table $table): void {
            $table->id();
            $table->bigInteger('account_id')->unique();
            // Scores in tenths, as Exim gives them; 0 turns each off.
            $table->integer('junk_score')->default(60);
            $table->integer('delete_score')->default(0);
            // JSON lists of addresses and @domains.
            $table->text('allow');
            $table->text('block');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('mail_spam');
        $tables->drop('mail_filters');
    }
};
