<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Event Model
 * 
 * Represents a solar event record in the database with normalized data
 * from various sources (HEK, CCMC, WSA, RHESSI).
 */
class Event extends Model
{
    use HasUuids;

    /**
     * The table associated with the model
     */
    protected $table = 'events';

    /**
     * The primary key for the model
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'remote_id',
        'response_hash',
        'source_id',
        'path',
        'start',
        'peak',
        'end',
        'hv_hpc_x',
        'hv_hpc_y',
        'label',
        'translator',
        'legacy_version',
        'legacy_type',
        'legacy_pin',
    ];

    /**
     * The attributes that should be cast
     */
    protected $casts = [
        'start' => 'integer',
        'peak' => 'integer',
        'end' => 'integer',
        'hv_hpc_x' => 'float',
        'hv_hpc_y' => 'float',
        'source_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization
     */
    protected $hidden = [
        'response_hash',
    ];

    /**
     * Get events for a specific source
     */
    public function scopeBySource($query, int $sourceId)
    {
        return $query->where('source_id', $sourceId);
    }

    /**
     * Get events that overlap with a given time period
     */
    public function scopeOverlapping($query, int $start, int $end)
    {
        return $query->where('start', '<=', $end)
                    ->where('end', '>=', $start);
    }

    /**
     * Get events within a time range
     */
    public function scopeTimeRange($query, int $start, int $end)
    {
        return $query->where('start', '>=', $start)
                    ->where('end', '<=', $end);
    }

    /**
     * Get events by translator
     */
    public function scopeByTranslator($query, string $translator)
    {
        return $query->where('translator', $translator);
    }

    /**
     * Get the start time as a formatted date
     */
    public function getStartDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->start);
    }

    /**
     * Get the peak time as a formatted date
     */
    public function getPeakDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->peak);
    }

    /**
     * Get the end time as a formatted date
     */
    public function getEndDateAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->end);
    }

    /**
     * Get the duration in seconds
     */
    public function getDurationAttribute(): int
    {
        return $this->end - $this->start;
    }

    /**
     * Check if event overlaps with another time period
     */
    public function overlaps(int $start, int $end): bool
    {
        return $this->start <= $end && $this->end >= $start;
    }

    /**
     * Create a new event from processed data
     */
    public static function createFromProcessed(array $processedData): self
    {
        return static::create($processedData);
    }
}