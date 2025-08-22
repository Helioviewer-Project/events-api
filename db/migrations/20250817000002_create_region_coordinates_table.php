<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateRegionCoordinatesTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->create('region_coordinates', function ($table) {
            // Primary key
            $table->id();
            
            // Foreign key to regions
            $table->unsignedBigInteger('region_id');
            $table->foreign('region_id')->references('id')->on('regions')->onDelete('cascade');
            
            // Coordinate data
            $table->integer('time')->comment('Unix timestamp of coordinate measurement');
            $table->double('longitude')->comment('Stonyhurst longitude in degrees');
            $table->double('latitude')->comment('Stonyhurst latitude in degrees');
            $table->double('area')->nullable()->comment('Area measurement');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes for trajectory queries
            $table->index(['region_id', 'time'], 'idx_region_coords_region_time');
            $table->index('time', 'idx_region_coords_time');
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('region_coordinates');
    }
}