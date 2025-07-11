<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateEventsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->create('events', function ($table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->smallInteger('source_id');
            $table->timestamp('start');
            $table->timestamp('peak');
            $table->timestamp('end');
            $table->string('label', 256);
            $table->timestamps();
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('events');
    }
}
