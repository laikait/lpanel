<?php

declare(strict_types=1);

use App\Engine\Database\Structure\Table;
use App\Engine\Database\Structure\Tables;
use App\Engine\Migration\Reversible;

return new class implements Reversible {
    public function up(Tables $tables): void
    {
        // What the service watchdog knows about each service. watch: it is
        // restarted when it goes down (on unless turned off); hold: an
        // administrator stopped it on purpose, so it is left alone until they
        // start it again; down_since: set while the watchdog finds it down.
        $tables->create('ops_services', static function (Table $table): void {
            $table->id();
            $table->string('unit', 64)->unique();
            $table->boolean('watch')->default(true);
            $table->boolean('hold')->default(false);
            $table->dateTime('down_since')->nullable();
        });

        // What happened to the services: found down, restarted, could not be
        // restarted, given up on, recovered.
        $tables->create('ops_service_events', static function (Table $table): void {
            $table->id();
            $table->string('unit', 64)->index();
            $table->string('kind', 16);
            $table->string('detail', 300)->default('');
            $table->dateTime('at')->index();
        });
    }

    public function down(Tables $tables): void
    {
        $tables->drop('ops_service_events');
        $tables->drop('ops_services');
    }
};
