<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class CreateDistributionsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->create('distributions', function ($table) {
            // Primary key - auto-increment bigint
            $table->id();

            // Core fields
            $table->bigInteger('start')->comment('Unix timestamp of bucket start');
            $table->string('size', 10)->comment('Bucket size: 30m, h, D, W, M, Y');
            $table->string('path', 255)->comment('Full event path');
            $table->integer('count')->default(0)->comment('Event count in this bucket');

            // Timestamps
            $table->timestamps();

            // Unique constraint on (size, path, start)
            $table->unique(['size', 'path', 'start'], 'idx_distributions_unique');
        });

        // Add text_pattern_ops index for LIKE prefix queries
        // This must be done with raw SQL since Laravel doesn't support text_pattern_ops
        $this->execute("
            CREATE INDEX idx_dist_path_prefix ON distributions
                (size, path text_pattern_ops, start)
        ");
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->dropIfExists('distributions');
    }
}
