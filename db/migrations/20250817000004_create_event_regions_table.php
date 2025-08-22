<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateEventRegionsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->create('event_regions', function ($table) {
            // Primary key
            $table->id();
            
            // Foreign keys
            $table->uuid('event_id');
            $table->unsignedBigInteger('region_id');
            
            // Foreign key constraints
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('cascade');
            
            // Timestamps
            $table->timestamps();
            
            // Unique constraint to prevent duplicates
            $table->unique(['event_id', 'region_id'], 'idx_event_regions_unique');
            
            // Indexes for efficient queries
            $table->index('event_id', 'idx_event_regions_event');
            $table->index('region_id', 'idx_event_regions_region');
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('event_regions');
    }
}