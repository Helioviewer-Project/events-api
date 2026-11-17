<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

final class AddIssueTimeToEventsTable extends AbstractMigration
{
    /**
     * Migrate Up.
     *
     * When a forecast was issued, as unix seconds: the model's issue time for
     * Solar Flare Predictions, the map's forecast_time for WSA. Set only for
     * forecasts and NULL for every other source, so it also marks what is a
     * forecast. The observation query keeps, per path, only the newest
     * issue_time at or before the observation time.
     *
     * Stored rows are filled here, without their source files: a prediction's
     * remote_id starts with "<dataset>:<issue_time>:", and a WSA map's
     * forecast_time is its coordinate_time.
     */
    public function up(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->integer('issue_time')->nullable();
        });

        Capsule::statement(<<<'SQL'
            UPDATE events
               SET issue_time = extract(epoch FROM substring(remote_id FROM '^[^:]+:([^Z]+Z):')::timestamptz)::int
             WHERE path LIKE 'CCMC>>Solar Flare Predictions>>%'
        SQL);
        Capsule::statement(<<<'SQL'
            UPDATE events SET issue_time = coordinate_time WHERE path LIKE 'WSA>>%'
        SQL);
    }

    /**
     * Migrate Down.
     */
    public function down(): void
    {
        Capsule::schema()->table('events', function ($table) {
            $table->dropColumn('issue_time');
        });
    }
}
