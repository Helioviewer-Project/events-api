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
 * @property string|null $legacy_event_type Legacy two-letter event type code
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
        'count',
        'legacy_event_type'
    ];

    protected $casts = [
        'start' => 'integer',
        'count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get legacy_event_type for a given path by prefix matching.
     *
     * Looks up the path in events_paths_legacy_event_types.php and finds
     * the matching prefix to determine the legacy type code.
     *
     * @param string $path The event path to look up
     * @return string The legacy event type code (e.g., 'AR', 'FL')
     * @throws \RuntimeException If no matching prefix found
     */
    public static function getLegacyEventType(string $path): string
    {
        static $lookup = null;
        if ($lookup === null) {
            $lookup = require __DIR__ . '/../Api/events_paths_legacy_event_types.php';
        }

        foreach ($lookup as $prefix => $type) {
            // Match if path equals prefix exactly or starts with prefix>>
            if ($path === $prefix || str_starts_with($path, $prefix . '>>')) {
                return $type;
            }
        }

        throw new \RuntimeException(
            "Path '{$path}' does not match any prefix in events_paths_legacy_event_types.php"
        );
    }
}
