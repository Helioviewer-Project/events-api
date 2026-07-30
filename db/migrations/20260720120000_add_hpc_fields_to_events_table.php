<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class AddHpcFieldsToEventsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     *
     * Adds the native-HPC snapshot fields: the event as seen from Earth at its
     * own coordinate_time, in helioprojective arcsec, regardless of the source
     * coordinate_system (hv_hpc_x/y hold degrees for stonyhurst/carrington).
     *
     * All three are nullable — NULL means "not resolved yet". footprint_hpc is
     * the resolution marker: it is set last ('[]' for footprint-less events),
     * so bin/backfill-hpc.php can resume on WHERE footprint_hpc IS NULL.
     */
    public function up(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->float('x_hpc')->nullable();
            $table->float('y_hpc')->nullable();
            $table->text('footprint_hpc')->nullable();
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->dropColumn('x_hpc');
            $table->dropColumn('y_hpc');
            $table->dropColumn('footprint_hpc');
        });
    }
}
