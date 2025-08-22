<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Regions;

use Illuminate\Database\Eloquent\Model;

/**
 * Region Model
 *
 * Represents a solar region (Active Region, Coronal Hole, etc.) from various
 * organizations like NOAA, HARP, and others. Regions can contain multiple
 * events and have trajectory data over time.
 *
 * @package Helioviewer\EventsApi\Regions
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 *
 * @property int $id Unique identifier for the region
 * @property string $organization Organization name (NOAA, HARP, etc.)
 * @property string $external_id External identifier from the organization
 * @property \Carbon\Carbon $created_at Region creation timestamp
 * @property \Carbon\Carbon $updated_at Region last update timestamp
 */
class Region extends Model
{
    protected $table = 'regions';
    
    protected $fillable = [
        'organization',
        'external_id'
    ];
    
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Virtual name field - combines organization and external_id
     */
    public function getNameAttribute(): string
    {
        return "{$this->organization} {$this->external_id}";
    }
    
    /**
     * Region trajectory coordinates over time
     */
    public function coordinates()
    {
        return $this->hasMany(RegionCoordinate::class);
    }
    
    /**
     * Many-to-many relationship with events.
     */
    public function events()
    {
        return $this->belongsToMany(
            \Helioviewer\EventsApi\Events\Event::class,
            'event_regions',
            'region_id',
            'event_id'
        )->withTimestamps();
    }
}