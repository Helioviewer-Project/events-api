<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;

/**
 * Event Model
 *
 * Represents a solar event record in the database with normalized data
 * from various sources including HEK (Heliophysics Event Knowledgebase),
 * CCMC (Community Coordinated Modeling Center), WSA (Wang-Sheeley-Arge),
 * and RHESSI (Reuven Ramaty High Energy Solar Spectroscopic Imager).
 *
 * This model handles the storage and retrieval of solar events with
 * standardized coordinates, timing, and metadata. Events are uniquely
 * identified by UUIDs and can be filtered by source, time range, and
 * various other criteria.
 *
 * @package Helioviewer\EventsApi\Models
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 *
 * @property string $id Unique UUID identifier for the event
 * @property string $remote_id Remote identifier from the source system
 * @property int $source_id Identifier of the data source
 * @property string|null $path Event path or classification
 * @property int $start Event start time as Unix timestamp
 * @property int|null $peak Event peak time as Unix timestamp
 * @property int $end Event end time as Unix timestamp
 * @property int $coordinate_time Time when coordinates were observed as Unix timestamp
 * @property float|null $hv_hpc_x Helioviewer HPC X coordinate
 * @property float|null $hv_hpc_y Helioviewer HPC Y coordinate
 * @property string|null $coordinate_system Coordinate system (stonyhurst, helioprojective, carrington)
 * @property array $footprint Footprint as a LIST of polygons: [[{x,y},…],…] (single-polygon events hold one entry)
 * @property float|null $x_hpc Native-HPC X (arcsec, from Earth at coordinate_time); NULL = not resolved yet
 * @property float|null $y_hpc Native-HPC Y (arcsec, from Earth at coordinate_time); NULL = not resolved yet
 * @property array|null $footprint_hpc Footprint in native HPC arcsec (same polygon-list shape); set LAST on resolution — NULL = not resolved yet
 * @property string|null $label Human-readable event label
 * @property string $short_label Shorter event label
 * @property string|null $legacy_version Legacy version identifier
 * @property string|null $legacy_type Legacy event type
 * @property string|null $legacy_pin Legacy pin identifier
 * @property \Carbon\Carbon $created_at Event creation timestamp
 * @property \Carbon\Carbon $updated_at Event last update timestamp
 *
 * @method static Builder bySource(int $sourceId) Filter events by source ID
 * @method static Builder overlapping(int $start, int $end) Find overlapping events
 * @method static Builder timeRange(int $start, int $end) Filter by time range
 */
class Event extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'events';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * These attributes can be set via mass assignment methods
     * like create() and fill().
     *
     * @var array<string>
     */
    protected $fillable = [
        'remote_id',
        'source_id',
        'path',
        'start',
        'peak',
        'end',
        'coordinate_time',
        'hv_hpc_x',
        'hv_hpc_y',
        'coordinate_system',
        'footprint',
        'x_hpc',
        'y_hpc',
        'footprint_hpc',
        'label',
        'short_label',
        'legacy_version',
        'legacy_type',
        'legacy_pin',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start' => 'integer',
        'peak' => 'integer',
        'end' => 'integer',
        'coordinate_time' => 'integer',
        'hv_hpc_x' => 'float',
        'hv_hpc_y' => 'float',
        'footprint' => 'array',
        'x_hpc' => 'float',
        'y_hpc' => 'float',
        'footprint_hpc' => 'array',
        'source_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        // Native-HPC snapshot: internal input to rotation, never part of a
        // response. Serializing it would duplicate the rotated hv_hpc_*/footprint
        // and decode a second copy of every vertex into PHP arrays. Attribute
        // access ($event->footprint_hpc) is unaffected by $hidden.
        'x_hpc',
        'y_hpc',
        'footprint_hpc',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['regions'];

    /**
     * Whether this freshly-processed event carries different coordinate data
     * than its stored DB copy: coordinate_time, coordinate_system, the center
     * coordinates, or the footprint. A missing copy (new event) counts as
     * different. Pure comparison — no knowledge of the HPC snapshot fields.
     *
     * @param Event|null $existingEvent The stored DB copy (null when the event is new)
     * @return bool
     */
    public function coordinatesDifferFrom(?Event $existingEvent): bool
    {
        return $existingEvent === null
            || $existingEvent->coordinate_time !== $this->coordinate_time
            || $existingEvent->coordinate_system !== $this->coordinate_system
            || (float) $existingEvent->hv_hpc_x !== (float) $this->hv_hpc_x
            || (float) $existingEvent->hv_hpc_y !== (float) $this->hv_hpc_y
            || $existingEvent->footprint !== $this->footprint;
    }

    /**
     * Scope a query to only include events from a specific source.
     *
     * @param  Builder $query The query builder instance
     * @param  int $sourceId The source ID to filter by
     * @return Builder The modified query builder
     */
    public function scopeBySource(Builder $query, int $sourceId): Builder
    {
        return $query->where('source_id', $sourceId);
    }

    /**
     * Scope a query to only include events that overlap with a given time period.
     *
     * An event overlaps if its start time is before or at the period end,
     * and its end time is after or at the period start.
     *
     * @param  Builder $query The query builder instance
     * @param  int $start The start of the time period (Unix timestamp)
     * @param  int $end The end of the time period (Unix timestamp)
     * @return Builder The modified query builder
     */
    public function scopeOverlapping(Builder $query, int $start, int $end): Builder
    {
        return $query->where('start', '<=', $end)
                    ->where('end', '>=', $start);
    }

    /**
     * Scope a query to only include events that fall completely within a time range.
     *
     * Both the event start and end times must be within the specified range.
     *
     * @param  Builder $query The query builder instance
     * @param  int $start The start of the time range (Unix timestamp)
     * @param  int $end The end of the time range (Unix timestamp)
     * @return Builder The modified query builder
     */
    public function scopeTimeRange(Builder $query, int $start, int $end): Builder
    {
        return $query->where('start', '>=', $start)
                    ->where('end', '<=', $end);
    }


    /**
     * Get the start time as a formatted date string.
     *
     * @return string The formatted start date (Y-m-d H:i:s)
     */
    public function getStartDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->start);
    }

    /**
     * Get the peak time as a formatted date string.
     *
     * @return string tHE FORmatted peak date (Y-m-d H:i:s)
     */
    public function getPeakDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->peak);
    }

    /**
     * Get the end time as a formatted date string.
     *
     * @return string The formatted end date (Y-m-d H:i:s)
     */
    public function getEndDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->end);
    }

    /**
     * Get the event duration in seconds.
     *
     * Calculates the difference between end and start timestamps.
     *
     * @return int The duration in seconds
     */
    public function getDurationAttribute(): int
    {
        return $this->end - $this->start;
    }

    /**
     * Check if this event overlaps with another time period.
     *
     * An overlap occurs when the event's time range intersects
     * with the given time period.
     *
     * @param  int $start The start of the time period to check (Unix timestamp)
     * @param  int $end The end of the time period to check (Unix timestamp)
     * @return bool True if the event overlaps with the given period
     */
    public function overlaps(int $start, int $end): bool
    {
        return $this->start <= $end && $this->end >= $start;
    }

    /**
     * Create a new event from processed data.
     *
     * This is a convenience method for creating events from
     * data that has already been processed and normalized.
     *
     * @param  array<string, mixed> $processedData The processed event data
     * @return self The created event instance
     * @throws \Illuminate\Database\QueryException If the data is invalid
     */
    public static function createFromProcessed(array $processedData): self
    {
        return static::create($processedData);
    }

    /**
     * Get the API URL for this event.
     *
     * @return string The full API URL for this event
     */
    public function getUrl(): string
    {
        return self::getUrlById($this->id);
    }

    /**
     * Get the API URL for an event by UUID.
     *
     * @param string $uuid The event UUID
     * @return string The full API URL for the event
     */
    public static function getUrlById(string $uuid): string
    {
        return rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/') . "/api/v1/events/{$uuid}";
    }

    /**
     * Many-to-many relationship with regions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function regions()
    {
        return $this->belongsToMany(
            \Helioviewer\EventsApi\Regions\Region::class,
            'event_regions',
            'event_id',
            'region_id'
        )->withTimestamps();
    }
}
