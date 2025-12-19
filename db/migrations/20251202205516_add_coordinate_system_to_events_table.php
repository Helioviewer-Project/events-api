<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class AddCoordinateSystemToEventsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->string('coordinate_system', 20)->nullable();
        });
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->dropColumn('coordinate_system');
        });
    }
}
