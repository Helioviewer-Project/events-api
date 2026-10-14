<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * An upstream service is down or limiting us for now (HTTP 429, 502, 503,
 * 504): worth a retry later, and told apart from a change in its answers.
 *
 * @package Helioviewer\EventsApi\Exception
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class UpstreamUnavailableException extends \RuntimeException
{
}
