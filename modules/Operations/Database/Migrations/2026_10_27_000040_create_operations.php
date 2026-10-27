<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // Work for one server's agent (ADR-0006). The signed envelope is made
        // at claim time, from these columns, with the attempt being delivered.
        $tables->create('operations', static function (Table $table): void {
            $table->id();
            // The operation_id on the wire: a UUIDv4.
            $table->string('uid', 36)->unique();
            $table->bigInteger('server_id');
            $table->string('op', 100);
            $table->integer('op_version');
            $table->string('target_type', 16);
            $table->string('target_uid', 36);
            // JSON, never a secret.
            $table->text('payload');
            $table->string('idempotency_key', 36)->nullable();
            // From the definition when queued, so a lapsed lease is retried as it was promised.
            $table->boolean('mutating');
            $table->boolean('idempotent');
            $table->string('status', 20);
            $table->integer('attempt')->default(0);
            $table->integer('max_attempts');
            $table->boolean('cancel_requested')->default(false);
            $table->integer('lease_seconds');
            $table->integer('timeout_seconds');
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('not_after');
            $table->string('requested_by', 26)->nullable();
            $table->string('requested_by_name', 190)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->text('result')->nullable();
            // The attempt whose result was taken, so a resent copy of it is recognised.
            $table->integer('result_attempt')->nullable();
            $table->string('agent_version', 32)->nullable();
            // As the agent reports them.
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('queued_at');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index('server_id', 'status', 'queued_at');
            $table->index('status', 'lease_until');
            $table->unique('server_id', 'idempotency_key');
            $table->foreign('server_id')->references('servers');
        });

        // Every change of an operation's status, and why.
        $tables->create('operation_events', static function (Table $table): void {
            $table->id();
            $table->bigInteger('operation_id')->index();
            $table->dateTime('occurred_at');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->integer('attempt');
            // user, agent, sweeper or system
            $table->string('actor', 16);
            $table->text('detail')->nullable();
            $table->foreign('operation_id')->references('operations');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('operation_events');
        $tables->drop('operations');
    }
};
