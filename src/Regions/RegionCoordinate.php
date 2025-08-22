<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Regions;

use Illuminate\Database\Eloquent\Model;

/**
 * Region Coordinate Model
 *
 * Represents trajectory coordinates for a region at a specific time.
 * Stores Stonyhurst coordinates (latitude, longitude) and area measurements.
 *
 * @package Helioviewer\EventsApi\Regions
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 *
 * @property int $id Unique identifier
 * @property int $region_id Foreign key to regions table
 * @property int $time Unix timestamp of the coordinate measurement
 * @property float $longitude Stonyhurst longitude in degrees
 * @property float $latitude Stonyhurst latitude in degrees
 * @property float|null $area Area measurement (units TBD)
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 */
class RegionCoordinate extends Model
{
    protected $table = 'region_coordinates';
    
    protected $fillable = [
        'region_id',
        'time',
        'longitude',
        'latitude',
        'area'
    ];
    
    protected $casts = [
        'time' => 'integer',
        'longitude' => 'float',
        'latitude' => 'float',
        'area' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    
    /**
     * Belongs to a region
     */
    public function region()
    {
        return $this->belongsTo(Region::class);
    }
    
    /**
     * Get formatted time
     */
    public function getFormattedTimeAttribute(): string
    {
        return date('Y-m-d H:i:s', $this->time);
    }
}