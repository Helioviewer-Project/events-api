<?php

/**
 * Maps event path prefixes to their legacy_event_type codes.
 * Used to determine the legacy type for any path by prefix matching.
 *
 * These codes correspond to the "pin" values in event_paths_dictionary.php
 * and are used for filtering distributions by legacy event type.
 */
return [
    // HEK types
    'HEK>>Active Region' => 'AR',
    'HEK>>Coronal Cavity' => 'CC',
    'HEK>>Coronal Dimming' => 'CD',
    'HEK>>Coronal Hole' => 'CH',
    'HEK>>Coronal Jet' => 'CJ',
    'HEK>>CME' => 'CE',
    'HEK>>Coronal Rain' => 'CR',
    'HEK>>Coronal Wave' => 'CW',
    'HEK>>Emerging Flux' => 'EF',
    'HEK>>Eruption' => 'ER',
    'HEK>>Filament' => 'FI',
    'HEK>>Filament Activation' => 'FA',
    'HEK>>Filament Eruption' => 'FE',
    'HEK>>Flare' => 'FL',
    'HEK>>Loop' => 'LP',
    'HEK>>Oscillation' => 'OS',
    'HEK>>Plage' => 'PG',
    'HEK>>Sigmoid' => 'SG',
    'HEK>>Spray Surge' => 'SP',
    'HEK>>Sunspot' => 'SS',
    'HEK>>Other' => 'OT',
    'HEK>>Nothing Reported' => 'NR',
    'HEK>>Topological Object' => 'TO',
    'HEK>>Hypothesis' => 'HY',
    'HEK>>UVBurst' => 'BU',
    'HEK>>Explosive Event' => 'EE',
    'HEK>>Prominence Bubble' => 'PB',
    'HEK>>Peacock Tail' => 'PT',
    'HEK>>SEPs' => 'EP',
    'HEK>>ICMEs' => 'IC',
    'HEK>>SIRs' => 'SR',

    // RHESSI types
    'RHESSI>>Solar Flares' => 'F2',

    // CCMC types
    'CCMC>>DONKI' => 'C3',
    'CCMC>>Solar Flare Predictions' => 'FP',
];
