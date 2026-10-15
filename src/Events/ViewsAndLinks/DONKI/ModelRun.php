<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI;

use Illuminate\Database\Eloquent\Model;

/**
 * A DONKI WSA-ENLIL model run's image list, as last read from CCMC.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 *
 * @property int                   $run_id     DONKI nugget id of the run
 * @property int|null              $version    Run version the images were read from
 * @property list<array{name: string, url: string}> $images The run's gifs: CCMC's group name and URL
 * @property string                $status     found | none | failed
 * @property int                   $fetched_at Unix time of the last read from CCMC
 */
class ModelRun extends Model
{
    public const FOUND = 'found';
    public const NONE = 'none';
    public const FAILED = 'failed';

    protected $table = 'donki_model_runs';
    protected $primaryKey = 'run_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = ['run_id', 'version', 'images', 'status', 'fetched_at'];

    protected $casts = [
        'run_id'     => 'integer',
        'version'    => 'integer',
        'images'     => 'array',
        'fetched_at' => 'integer',
    ];
}
