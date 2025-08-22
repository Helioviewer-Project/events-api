<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateRegionsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->create('regions', function ($table) {
            // Primary key - auto-increment integer starting from 1
            $table->id();
            
            // Core fields
            $table->string('organization')->comment('Organization name (NOAA, HARP, etc.)');
            $table->string('external_id')->comment('External identifier from the organization');
            
            // Timestamps
            $table->timestamps();
            
            // Unique constraint on organization + external_id
            $table->unique(['organization', 'external_id'], 'idx_regions_org_external');
            
            // Index for faster lookups
            $table->index('organization', 'idx_regions_organization');
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('regions');
    }
}