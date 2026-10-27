<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // A managed server, and what its agent last said about it. Online or
        // offline is worked out from last_seen_at when shown; "state" is only
        // pending (no agent yet), active, or revoked.
        $tables->create('servers', static function (Table $table): void {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->string('name', 100);
            $table->string('state', 16)->default('pending');
            $table->string('agent_id', 36)->nullable()->unique();
            $table->string('hostname', 255)->nullable();
            $table->string('os_id', 64)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('kernel', 128)->nullable();
            $table->string('machine_id_sha256', 64)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->text('protocols')->nullable();
            $table->text('capabilities')->nullable();
            $table->text('health')->nullable();
            $table->boolean('rotate_requested')->default(false);
            $table->dateTime('enrolled_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->string('created_by', 26)->nullable();
            $table->timestamps();
        });

        // An agent's keys. Two may be live for a day while one rotates out.
        $tables->create('agent_credentials', static function (Table $table): void {
            $table->id();
            $table->bigInteger('server_id')->index();
            $table->string('kid', 16)->unique();
            $table->string('public_key', 64);
            $table->string('tls_cert_sha256', 64);
            $table->string('state', 16)->default('active');
            $table->dateTime('retire_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('server_id')->references('servers');
        });

        // One-time pairing tokens, stored only as their SHA-256.
        $tables->create('agent_enrollments', static function (Table $table): void {
            $table->id();
            $table->bigInteger('server_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->string('created_by', 26)->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->string('consumed_from_ip', 45)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('server_id')->references('servers');
        });

        // Request ids already used, until their replay window has passed.
        $tables->create('agent_seen_requests', static function (Table $table): void {
            $table->id();
            $table->string('agent_id', 36);
            $table->string('request_id', 36);
            $table->bigInteger('expires_at')->index();
            $table->unique('agent_id', 'request_id');
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('agent_seen_requests');
        $tables->drop('agent_enrollments');
        $tables->drop('agent_credentials');
        $tables->drop('servers');
    }
};
