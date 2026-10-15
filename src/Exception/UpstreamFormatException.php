<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * An upstream service answered, but not in the shape we read: its API has
 * likely changed and the code that reads it needs a look.
 *
 * @package Helioviewer\EventsApi\Exception
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class UpstreamFormatException extends \RuntimeException
{
}
