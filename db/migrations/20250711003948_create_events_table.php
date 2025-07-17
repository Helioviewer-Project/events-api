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
            // Primary key - auto-generated UUID
            $table->uuid('id')->primary();
            
            // Core required fields
            $table->string('remote_id')->unique(); // Remote system ID, must be unique
            $table->string('response_hash')->unique(); // Hash of the response, must be unique
            $table->integer('source_id'); // Source identifier
            $table->string('path', 500); // Source path, up to 500 chars
            
            // Timestamp fields (stored as integers)
            $table->integer('start'); // Start timestamp - cannot be null
            $table->integer('peak'); // Peak timestamp - cannot be null  
            $table->integer('end'); // End timestamp - cannot be null
            
            // Coordinate fields (signed floats)
            $table->float('hv_hpc_x'); // Helioviewer HPC X coordinate
            $table->float('hv_hpc_y'); // Helioviewer HPC Y coordinate
            
            // Label and legacy fields
            $table->string('label', 256); // Event label, max 256 chars
            $table->string('translator'); // Translator identifier (static text)
            $table->string('legacy_version', 50)->nullable(); // Legacy version string
            $table->string('legacy_type', 4)->nullable(); // Legacy type, max 4 chars
            $table->string('legacy_pin', 4); // Legacy pin, max 4 chars, required
            
            // Timestamps
            $table->timestamps();
            
            // Indexes for optimal query performance
            $table->index('remote_id'); // Index on remote_id for lookups
            $table->index('response_hash'); // Index on response_hash for lookups
            
            // Basic source_id index for simple filtering
            $table->index('source_id'); // Index on source_id for filtering
            
            // MOST IMPORTANT: Composite index for your main query pattern
            // WHERE source_id = X AND start <= t AND end >= t
            $table->index(['source_id', 'start', 'end'], 'idx_events_source_range');
            
            // Additional composite indexes for other patterns
            $table->index(['source_id', 'start'], 'idx_events_source_start');
            $table->index(['source_id', 'end'], 'idx_events_source_end');
            $table->index(['source_id', 'created_at'], 'idx_events_source_created');
            $table->index(['source_id', 'updated_at'], 'idx_events_source_updated');
            
            // Standalone timestamp indexes (for queries without source_id)
            $table->index('created_at', 'idx_events_created_at');
            $table->index('updated_at', 'idx_events_updated_at');
            
            // Fallback index for queries without source_id (less common)
            $table->index(['start', 'end'], 'idx_events_start_end');
        });
        
        // Add advanced GiST index for complex overlap queries using raw SQL
        // This creates a generated column with range type and GiST index
        Capsule::statement('
            ALTER TABLE events 
            ADD COLUMN time_range int4range 
            GENERATED ALWAYS AS (int4range(start, "end", \'[]\')) STORED
        ');
        
        // GiST index for complex overlap queries (without source_id for flexibility)
        Capsule::statement('
            CREATE INDEX idx_events_time_range_gist 
            ON events USING gist (time_range)
        ');
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('events');
    }
}
