<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateDonkiModelRunsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     *
     * One row per DONKI WSA-ENLIL model run: the run's image list (gifs), read
     * once from CCMC's DONKI website API. A run can belong to several CMEs, so
     * the list is stored per run, not per event.
     */
    public function up(): void
    {
        Capsule::schema()->create('donki_model_runs', function ($table) {
            $table->integer('run_id')->primary()->comment('DONKI nugget id of the WSA-ENLIL run');
            $table->integer('version')->nullable()->comment('Run version the images were read from');
            $table->text('images')->default('[]')->comment('JSON list of the run\'s gifs: {name: CCMC group, url}');
            $table->string('status', 10)->comment('found | none | failed');
            $table->integer('fetched_at')->comment('Unix time of the last read from CCMC');
            $table->timestamps();
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('donki_model_runs');
    }
}
