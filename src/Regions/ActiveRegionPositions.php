<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Regions;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface as EventRepositoryInterface;
use Helioviewer\EventsApi\Exception\JsocException;
use Helioviewer\EventsApi\Jsoc\NoaaService;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;

/**
 * Where a NOAA active region was, from the HEK active-region events we already
 * store (linked to that NOAA region), with JSOC's NOAA list as the fallback.
 *
 * Rule for a NOAA region at time t (latest record at or before t):
 * 1. HMI SHARP no older than 4 h, if its patch covers only this NOAA region;
 * 2. NOAA SWPC Observer no older than 24 h;
 * 3. the newer of JSOC's record and HEK's latest older record.
 * coordinate_time is the time of the record used; the API rotates from there.
 * Positions are the raw HEK record's hgs_x/hgs_y: most HEK active regions are
 * stored helioprojective, so the event columns are not Stonyhurst degrees.
 *
 * @package Helioviewer\EventsApi\Regions
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class ActiveRegionPositions
{
    public const SHARP = 'HEK>>Active Region>>HMI SHARP';
    public const SWPC = 'HEK>>Active Region>>NOAA SWPC Observer';

    private const SHARP_MAX_AGE = 4 * 3600;
    private const SWPC_MAX_AGE = 24 * 3600;

    private LoggerInterface $logger;

    /**
     * @param RegionRepositoryInterface $regionRepository NOAA regions and their linked HEK events
     * @param EventRepositoryInterface  $eventRepository  HEK SWPC events of a day
     * @param NoaaService|null          $noaaService      JSOC fallback
     * @param JsonStorageInterface|null $jsonStorage      Raw HEK records (hgs_x/hgs_y, sharp_noaa_ars)
     * @param LoggerInterface|null      $logger           Logger
     */
    public function __construct(
        private RegionRepositoryInterface $regionRepository,
        private EventRepositoryInterface $eventRepository,
        private ?NoaaService $noaaService = null,
        private ?JsonStorageInterface $jsonStorage = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * A NOAA region's position at or before a time, by the rule above.
     *
     * @param int $noaaId NOAA region number (5 digits)
     * @param int $at     Unix timestamp, usually the forecast's start_window
     * @return array|null ['latitude', 'longitude', 'coordinate_time', 'source'], or null when nothing knows the region
     */
    public function forNoaa(int $noaaId, int $at): ?array
    {
        // "At or before", never after: a live run and a later backfill pick the same record
        $region = $this->regionRepository->findByOrganizationAndExternalId('NOAA', (string) $noaaId);
        $recent = $region === null ? [] : $this->regionRepository
            ->findEventsByPathsBetween($region, [self::SHARP, self::SWPC], $at - self::SWPC_MAX_AGE, $at);

        // A SHARP patch covering several NOAA regions has their joint centre, which is none of them
        foreach ($recent as $event) {
            if ($event->path === self::SHARP && $at - $event->start <= self::SHARP_MAX_AGE && $this->coversOnly($event, $noaaId)
                && ($position = $this->fromEvent($event, 'HEK SHARP')) !== null) {
                return $position;
            }
        }
        foreach ($recent as $event) {
            if ($event->path === self::SWPC && ($position = $this->fromEvent($event, 'HEK SWPC')) !== null) {
                return $position;
            }
        }

        // Nothing recent in HEK: JSOC's NOAA list often has a newer record (HEK's SWPC has gaps)
        $older = $region === null ? null : $this->regionRepository->findLatestEventByPath($region, self::SWPC, $at);
        $jsoc = $this->fromJsoc($noaaId, $at);

        if ($jsoc !== null && ($older === null || strtotime($jsoc['coordinate_time']) > $older->coordinate_time)) {
            return $jsoc;
        }

        return $older === null ? null : $this->fromEvent($older, 'HEK SWPC');
    }

    /**
     * NOAA's regions on the day before $at, latest position of each:
     * HEK's SWPC records, else JSOC's daily list.
     *
     * @return array<int, array{latitude: float, longitude: float, time: int}>
     */
    public function noaaRegionsBefore(int $at): array
    {
        $regions = [];
        foreach ($this->eventRepository->findByPathBetween(self::SWPC, $at - self::SWPC_MAX_AGE, $at) as $event) {
            $position = $this->stonyhurst($event);
            foreach ($position === null ? [] : $event->regions as $region) {
                if ($region->organization === 'NOAA') {
                    $regions[(int) $region->external_id] = [
                        'latitude' => $position[0],
                        'longitude' => $position[1],
                        'time' => (int) $event->coordinate_time,
                    ];
                }
            }
        }
        if ($regions !== [] || $this->noaaService === null) {
            return $regions;
        }

        try {
            return $this->noaaService->getRegionsOnDate(gmdate('Y-m-d', $at));
        } catch (JsocException $e) {
            $this->logger->warning("JSOC NOAA regions for " . gmdate('Y-m-d', $at) . " failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Whether a SHARP patch covers exactly this NOAA region (its sharp_noaa_ars).
     *
     * @param Event $sharp  HEK HMI SHARP event
     * @param int   $noaaId NOAA region number
     * @return bool
     */
    private function coversOnly(Event $sharp, int $noaaId): bool
    {
        $numbers = array_filter(array_map('trim', explode(',', (string) ($this->raw($sharp)['sharp_noaa_ars'] ?? ''))));

        return array_values($numbers) === [(string) $noaaId];
    }

    /**
     * The record's Stonyhurst position: the raw HEK hgs_y/hgs_x, else the event
     * columns when they are already Stonyhurst.
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private function stonyhurst(Event $event): ?array
    {
        // Most HEK active regions are stored helioprojective (arcsec), so read the raw hgs values
        $raw = $this->raw($event);
        if (isset($raw['hgs_x'], $raw['hgs_y'])) {
            return [(float) $raw['hgs_y'], (float) $raw['hgs_x']];
        }

        return $event->coordinate_system === 'stonyhurst' ? [(float) $event->hv_hpc_y, (float) $event->hv_hpc_x] : null;
    }

    /**
     * The event's raw HEK record.
     *
     * @param Event $event HEK event
     * @return array Raw record, or [] when it is not stored
     */
    private function raw(Event $event): array
    {
        return $this->jsonStorage?->load("/u/apps/data/sources/{$event->id}.json") ?? [];
    }

    /**
     * Coordinate data from a HEK event, timed at the event's coordinate_time.
     *
     * @param Event  $event  HEK SHARP or SWPC event
     * @param string $source Label for the position source ("HEK SHARP", "HEK SWPC")
     * @return array|null Coordinate data, or null without a Stonyhurst position
     */
    private function fromEvent(Event $event, string $source): ?array
    {
        $position = $this->stonyhurst($event);
        if ($position === null) {
            $this->logger->warning("HEK record {$event->id} has no Stonyhurst position");
            return null;
        }

        return [
            'latitude' => $position[0],
            'longitude' => $position[1],
            'coordinate_time' => gmdate('Y-m-d\TH:i:s\Z', (int) $event->coordinate_time),
            'source' => $source . ' ' . gmdate('Y-m-d H:i', (int) $event->coordinate_time),
        ];
    }

    /**
     * Coordinate data from JSOC's latest NOAA record at or before a time.
     *
     * @param int $noaaId NOAA region number
     * @param int $at     Unix timestamp
     * @return array|null Coordinate data, or null when JSOC has nothing or fails
     */
    private function fromJsoc(int $noaaId, int $at): ?array
    {
        if ($this->noaaService === null) {
            return null;
        }
        try {
            $record = $this->noaaService->getLastCoordinateForNoaa($noaaId, gmdate('Y-m-d H:i:s', $at));
        } catch (JsocException $e) {
            $this->logger->warning("JSOC lookup for NOAA {$noaaId} failed: " . $e->getMessage());
            return null;
        }

        return $record === null ? null : [
            'latitude' => (float) $record['latitude'],
            'longitude' => (float) $record['longitude'],
            'coordinate_time' => $record['location_time'],
            'source' => 'JSOC ' . $record['location_time'],
        ];
    }
}
