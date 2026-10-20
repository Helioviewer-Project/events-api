<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * A record that is deliberately not stored because it repeats another one
 * (e.g. AMOS's Model rows, copies of its NOAA rows). Counted, never a failure.
 *
 * @package Helioviewer\EventsApi\Exception
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class SkippedRecordException extends \RuntimeException
{
}
