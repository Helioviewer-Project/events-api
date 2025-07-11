<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use Illuminate\Database\Capsule\Manager as Capsule;

class EventsSeeder extends AbstractSeed
{
    /**
     * Run Method.
     */
    public function run(): void
    {
        $events = [];
        $sources = ['HEK', 'CCMC', 'WSA', 'RHESSI'];
        $eventTypes = [
            'Solar Flare Event',
            'Coronal Mass Ejection',
            'Solar Wind Enhancement',
            'Geomagnetic Storm',
            'Solar Energetic Particle Event',
            'Radio Burst',
            'X-ray Flare',
            'Proton Event'
        ];

        // Generate 1000 events for each source
        foreach ($sources as $index => $source) {
            $sourceId = $index + 1; // 1=HEK, 2=CCMC, 3=WSA, 4=RHESSI
            
            for ($i = 0; $i < 1000; $i++) {
                $start = mt_rand(1577836800, 1735689600); // Random between 2020-01-01 and 2025-01-01
                $duration = mt_rand(3600, 86400); // 1 hour to 24 hours
                $peakOffset = mt_rand(300, $duration - 300); // Peak somewhere in the middle
                
                $eventType = $eventTypes[array_rand($eventTypes)];
                $classification = chr(65 + mt_rand(0, 25)) . mt_rand(1, 9) . '.' . mt_rand(0, 9);
                
                $events[] = [
                    'uuid' => sprintf('%08x-%04x-%04x-%04x-%012x',
                        mt_rand(0, 0xffffffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffffffffffff)
                    ),
                    'source_id' => $sourceId,
                    'start' => $start,
                    'peak' => $start + $peakOffset,
                    'end' => $start + $duration,
                    'label' => $source . ' | ' . $eventType . ' ' . $classification,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        // Insert in batches of 100 to avoid memory issues
        $chunks = array_chunk($events, 100);
        foreach ($chunks as $chunk) {
            Capsule::table('events')->insert($chunk);
        }
    }
}
