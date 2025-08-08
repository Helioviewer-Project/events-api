<?php

use Phinx\Migration\AbstractMigration;

class AddPathIndexToEventsTable extends AbstractMigration
{
    /**
     * Add index on path column for better GROUP BY performance
     */
    public function change()
    {
        $table = $this->table('events');
        
        // Add index on path column for efficient grouping
        $table->addIndex(['path'], [
            'name' => 'idx_events_path'
        ]);
        
        // Add composite index for path with common filter columns
        $table->addIndex(['path', 'source_id', 'start'], [
            'name' => 'idx_events_path_source_start'
        ]);
        
        $table->update();
    }
}