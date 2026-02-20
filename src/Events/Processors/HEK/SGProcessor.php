<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;

/**
 * HEK Sigmoid (SG) Event Processor
 *
 * Specialized processor for HEK Sigmoid events.
 * Handles the Sigmoid Sniffer FRM source.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class SGProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct('SG', $logger);
    }

    /**
     * Build label array for Sigmoid events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'Sigmoid Sniffer') {
            $labelArray['Shape'] = $rawRecord['sg_shape'] ?? '';
        }

        return $labelArray;
    }
}
