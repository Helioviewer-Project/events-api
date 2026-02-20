<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class AddFootprintToEventsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     *
     * Adds footprint column for storing polygon boundary points.
     * Format: array of {x, y} points
     * Example: [{x: 100.5, y: 200.3}, {x: 101.2, y: 201.1}, ...]
     */
    public function up(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->text('footprint')->default('[]');
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->dropColumn('footprint');
        });
    }
}
