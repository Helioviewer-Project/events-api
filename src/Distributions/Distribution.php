<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Distributions;

use Illuminate\Database\Eloquent\Model;

/**
 * Distribution Model
 *
 * Represents a pre-aggregated event count for a specific time bucket, bucket size,
 * and event path. Used for fast distribution queries.
 *
 * @package Helioviewer\EventsApi\Distributions
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 *
 * @property int $id Unique identifier
 * @property int $start Unix timestamp of bucket start
 * @property string $size Bucket size: 30m, h, D, W, M, Y
 * @property string $path Full event path
 * @property int $count Event count in this bucket
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 */
class Distribution extends Model
{
    protected $table = 'distributions';

    public $timestamps = true;

    protected $fillable = [
        'start',
        'size',
        'path',
        'count'
    ];

    protected $casts = [
        'start' => 'integer',
        'count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
