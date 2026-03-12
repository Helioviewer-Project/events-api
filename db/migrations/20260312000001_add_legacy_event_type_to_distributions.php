<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class AddLegacyEventTypeToDistributions extends AbstractMigration
{
    /**
     * Migrate Up.
     */
    public function up(): void
    {
        Capsule::schema()->table('distributions', function ($table) {
            $table->string('legacy_event_type', 10)->nullable()->after('path')
                  ->comment('Legacy two-letter event type code (AR, FL, CE, etc.)');
        });

        // Add index for filtering by legacy_event_type
        $this->execute("
            CREATE INDEX idx_dist_legacy_type ON distributions (size, legacy_event_type, start)
        ");
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        $this->execute("DROP INDEX IF EXISTS idx_dist_legacy_type");

        Capsule::schema()->table('distributions', function ($table) {
            $table->dropColumn('legacy_event_type');
        });
    }
}
