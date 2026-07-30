<?php

declare(strict_types=1);

ini_set('memory_limit', '512M');

use Phinx\Seed\AbstractSeed;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;
use Helioviewer\EventsApi\Regions\Region;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Helioviewer\EventsApi\Utils\Container;

class RhessiSeeder extends AbstractSeed
{
    private RepositoryInterface $event_repository;
    private RegionRepositoryInterface $region_repository;
    private JsonStorageInterface $json_storage;
    private static bool $interrupted = false;

    public function __construct()
    {
        parent::__construct();

        // Get container and services
        $container = Container::getInstance();
        $this->event_repository = $container->get('eventRepository');
        $this->region_repository = $container->get('regionRepository');
        $this->json_storage = $container->get('jsonStorage');
    }

    /**
     * Run Method.
     *
     * Seed RHESSI (Reuven Ramaty High Energy Solar Spectroscopic Imager) events
     */
    public function run(): void
    {
        // Enable async signals and register Ctrl+C handler
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () {
                echo "\n\nInterrupt received (Ctrl+C). Finishing current event and stopping...\n";
                self::$interrupted = true;
            });
        }

        $filePath = __DIR__ . '/rhessi_flares_helioviewer.txt';
        $regionsFilePath = __DIR__ . '/rhessi_flare_regions.csv';

        // Load region mapping
        $regionMap = $this->loadRegionMapping($regionsFilePath);
        echo "Loaded " . count($regionMap) . " region mappings.\n";

        $rawEvents = $this->parseRhessiFile($filePath);

        echo "Parsed " . count($rawEvents) . " RHESSI events.\n";

        $processedCount = 0;
        $updatedCount = 0;
        $createdCount = 0;
        $regionsCreatedCount = 0;

        // Iterate through all events
        foreach ($rawEvents as $rawEvent) {
            // Check for interrupt
            if (self::$interrupted) {
                break;
            }

            // Check region mapping
            $flareId = $rawEvent['id'];
            if (!isset($regionMap[$flareId])) {
                throw new RuntimeException("ERROR: Flare ID {$flareId} not found in region mapping. Please update the region mapping file.");
            }
            $regionId = $regionMap[$flareId];

            // Add region_id to raw event
            $rawEvent['region_id'] = $regionId;

            $transformedEvent = $this->transformRawEventToModel($rawEvent);

            // Check if event already exists
            $remoteId = $transformedEvent->remote_id;
            $existingEvent = $this->event_repository->findByRemoteId($remoteId);

            if ($existingEvent) {
                $existingEvent->fill($transformedEvent->getAttributes());
                $rhessiEvent = $this->event_repository->save($existingEvent);
                $updatedCount++;
                echo "Event {$rawEvent['id']} UPDATED (UUID: {$rhessiEvent->id}) - {$rhessiEvent->getUrl()}\n";
            } else {
                $rhessiEvent = $this->event_repository->save($transformedEvent);
                $createdCount++;
                echo "Event {$rawEvent['id']} CREATED (UUID: {$rhessiEvent->id}) - {$rhessiEvent->getUrl()}\n";
            }

            // Now save JSON files using the UUID after successful DB save
            $uuid = $rhessiEvent->id;

            // Save raw source data using sharded storage
            $this->json_storage->storeById($uuid, 'sources', $rawEvent);

            // Save link data using sharded storage
            $this->json_storage->storeById($uuid, 'links', [
                'url' => $rawEvent['link'],
                'text' => 'Full analysis'
            ]);

            // Save views data with raw event including full link URL
            $this->json_storage->storeById($uuid, 'views', [[
                'name' => 'Main',
                'content' => $rawEvent,
            ]]);

            // Handle region association
            if ($regionId !== 0) {
                // Find or create the region with organization NOAA
                $region = $this->region_repository->findByOrganizationAndExternalId('NOAA', (string)$regionId);

                if (!$region) {
                    $region = new Region();
                    $region->organization = 'NOAA';
                    $region->external_id = (string)$regionId;
                    $region = $this->region_repository->save($region);
                    $regionsCreatedCount++;
                    echo "Created new region: {$region->name} (ID: {$region->id})\n";
                }

                // Sync replaces all existing associations with just this one
                $rhessiEvent->regions()->sync([$region->id]);
            } else {
                // Region is 0 - remove all region associations
                $rhessiEvent->regions()->sync([]);
            }

            $processedCount++;
        }

        echo "\n=== Summary ===\n";
        if (self::$interrupted) {
            echo "*** INTERRUPTED BY USER ***\n";
        }
        echo "Total parsed: " . count($rawEvents) . "\n";
        echo "Processed: {$processedCount}\n";
        echo "Created: {$createdCount}\n";
        echo "Updated: {$updatedCount}\n";
        echo "Regions created: {$regionsCreatedCount}\n";
        if (self::$interrupted) {
            echo "Remaining: " . (count($rawEvents) - $processedCount) . " events not processed\n";
        }
    }

    /**
     * Transform raw RHESSI event data to Event model
     *
     * @param array $rawEvent Raw event data from file
     * @return Event Eloquent Event model instance (not saved)
     */
    private function transformRawEventToModel(array $rawEvent): Event
    {
        // Convert timestamps
        $startTime = strtotime($rawEvent['start']);
        $peakTime = strtotime($rawEvent['peak']);
        $endTime = strtotime($rawEvent['end']);

        // Build event data array - use HPC coordinates directly from source
        $eventData = [
            'remote_id' => 'RHESSI:' . $rawEvent['id'],
            'source_id' => JsonSource::RHESSI,
            'path' => 'RHESSI>>Solar Flares>>Flare',
            'start' => $startTime,
            'peak' => $peakTime,
            'end' => $endTime,
            'coordinate_time' => $peakTime, // Use peak time as coordinate time for RHESSI
            'hv_hpc_x' => (float) $rawEvent['xloc'], // HPC X in arcseconds
            'hv_hpc_y' => (float) $rawEvent['yloc'], // HPC Y in arcseconds
            'coordinate_system' => 'helioprojective',
            // Already HPC — the native snapshot is a straight copy (no footprints)
            'x_hpc' => (float) $rawEvent['xloc'],
            'y_hpc' => (float) $rawEvent['yloc'],
            'footprint_hpc' => [],
            'label' => 'RHESSI ' . $rawEvent['id'],
            'short_label' => $rawEvent['id'] . ': ' . date('Y-m-d H:i:s', $startTime),
            'legacy_version' => '',
            'legacy_type' => 'FL', // Flare type
            'legacy_pin' => 'FL',
        ];

        // Create Event model instance (not saved to database)
        $event = new Event();
        $event->fill($eventData);

        return $event;
    }

    /**
     * Load region mapping from CSV file
     *
     * @param string $filePath Path to the region mapping CSV file
     * @return array Array mapping flare_id => active_region
     * @throws RuntimeException If file doesn't exist or cannot be read
     */
    private function loadRegionMapping(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Region mapping file not found at: {$filePath}");
        }

        $file = fopen($filePath, 'r');

        if ($file === false) {
            throw new RuntimeException("Failed to open region mapping file at: {$filePath}");
        }

        $regionMap = [];

        while (($line = fgets($file)) !== false) {
            // Skip empty lines
            if (empty(trim($line))) {
                continue;
            }

            // Parse CSV line: flare_id,active_region
            $parts = str_getcsv(trim($line));

            if (count($parts) >= 2) {
                $flareId = $parts[0];
                $activeRegion = (int) $parts[1];

                // Transform region ID if not 0 and less than 9000 (add 10000)
                if ($activeRegion !== 0 && $activeRegion < 9000) {
                    $activeRegion = 10000 + $activeRegion;
                }

                $regionMap[$flareId] = $activeRegion;
            }
        }

        fclose($file);

        return $regionMap;
    }

    /**
     * Parse RHESSI flares file
     *
     * @param string $filePath Path to the RHESSI flares file
     * @return array Array of parsed events
     * @throws RuntimeException If file doesn't exist or cannot be read
     */
    private function parseRhessiFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("RHESSI data file not found at: {$filePath}");
        }

        $file = fopen($filePath, 'r');

        if ($file === false) {
            throw new RuntimeException("Failed to open RHESSI data file at: {$filePath}");
        }

        $events = [];

        while (($line = fgets($file)) !== false) {
            // Skip comment lines and empty lines
            if (empty(trim($line)) || $line[0] === ';') {
                continue;
            }

            // Parse CSV line
            $raw_event = str_getcsv(trim($line));

            // id,stime,ptime,etime,prate,tcounts,xloc,yloc,en_hi,dsun,ntime,nen,link
            $events[] = [
                'id' => $raw_event[0],
                'start' => $raw_event[1],
                'peak' => $raw_event[2],
                'end' => $raw_event[3],
                'peakrate' => $raw_event[4],
                'totalcounts' => $raw_event[5],
                'xloc' => $raw_event[6],
                'yloc' => $raw_event[7],
                'hi_band' => $raw_event[8],
                'dsun' => $raw_event[9],
                'ntime' => $raw_event[10],
                'nen' => $raw_event[11],
                'link' => 'https://umbra.nascom.nasa.gov/rhessi/rhessi_extras/flare_images_v2/' . $raw_event[12],
            ];
        }

        fclose($file);

        return $events;
    }
}
