<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;

/**
 * HEK Filament (FI) Event Processor
 *
 * Specialized processor for HEK Filament events.
 * Handles the AAFDCC FRM source.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class FIProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null, ?SentryClientInterface $sentry = null)
    {
        parent::__construct('FI', $logger, $sentry);
    }

    /**
     * Build label array for Filament events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'AAFDCC') {
            $lengthValue = str_replace('+', '', sprintf('%.1e', (float)($rawRecord['fi_length'] ?? 0)));
            $lengthUnit = $rawRecord['fi_lengthunit'] ?? '';
            $labelArray['Filament Length'] = $lengthValue . ' ' . $lengthUnit;
        }

        return $labelArray;
    }
}
