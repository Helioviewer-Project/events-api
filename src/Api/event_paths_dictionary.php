<?php

/**
 * Dictionary for event paths with custom names, pins, contacts, and URLs
 * Used by Legacy class to format hierarchical event data
 */

$hek = [
    'HEK>>Active Region' => [
        "name" => "Active Region",
        "pin" => "AR",
    ],
    'HEK>>Coronal Cavity' => [
        "name" => "Coronal Cavity",
        "pin" => "CC",
    ],
    'HEK>>Coronal Dimming' => [
        "name" => "Coronal Dimming",
        "pin" => "CD",
    ],
    'HEK>>Coronal Hole' => [
        "name" => "Coronal Hole",
        "pin" => "CH",
    ],
    'HEK>>Coronal Jet' => [
        "name" => "Coronal Jet",
        "pin" => "CJ",
    ],
    'HEK>>CME' => [
        "name" => "CME",
        "pin" => "CE",
    ],
    'HEK>>Coronal Rain' => [
        "name" => "Coronal Rain",
        "pin" => "CR",
    ],
    'HEK>>Coronal Wave' => [
        "name" => "Coronal Wave",
        "pin" => "CW",
    ],
    'HEK>>Emerging Flux' => [
        "name" => "Emerging Flux",
        "pin" => "EF",
    ],
    'HEK>>Eruption' => [
        "name" => "Eruption",
        "pin" => "ER",
    ],
    'HEK>>Filament' => [
        "name" => "Filament",
        "pin" => "FI",
    ],
    'HEK>>Filament Activation' => [
        "name" => "Filament Activation",
        "pin" => "FA",
    ],
    'HEK>>Filament Eruption' => [
        "name" => "Filament Eruption",
        "pin" => "FE",
    ],
    'HEK>>Flare' => [
        "name" => "Flare",
        "pin" => "FL",
    ],
    'HEK>>Loop' => [
        "name" => "Loop",
        "pin" => "LP",
    ],
    'HEK>>Oscillation' => [
        "name" => "Oscillation",
        "pin" => "OS",
    ],
    'HEK>>Plage' => [
        "name" => "Plage",
        "pin" => "PG",
    ],
    'HEK>>Sigmoid' => [
        "name" => "Sigmoid",
        "pin" => "SG",
    ],
    'HEK>>Spray Surge' => [
        "name" => "Spray Surge",
        "pin" => "SP",
    ],
    'HEK>>Sunspot' => [
        "name" => "Sunspot",
        "pin" => "SS",
    ],
    // Weird ones
    'HEK>>Other' => [
        "name" => "Other",
        "pin" => "OT",
    ],
    'HEK>>Nothing Reported' => [
        "name" => "Nothing Reported",
        "pin" => "NR",
    ],
    'HEK>>Topological Object' => [
        "name" => "Topological Object",
        "pin" => "TO",
    ],
    'HEK>>Hypothesis' => [
        "name" => "Hypothesis",
        "pin" => "HY",
    ],
    'HEK>>UV Burst' => [
        "name" => "UV Burst",
        "pin" => "BU",
    ],
    'HEK>>Explosive Event' => [
        "name" => "Explosive Event",
        "pin" => "EE",
    ],
    'HEK>>Prominence Bubble' => [
        "name" => "Prominence Bubble",
        "pin" => "PB",
    ],
    'HEK>>Peacock Tail' => [
        "name" => "Peacock Tail",
        "pin" => "PT",
    ],
    'HEK>>SEPs' => [
        "name" => "Solar Energetic Particles",
        "pin" => "EP",
    ],
    'HEK>>ICMEs' => [
        "name" => "Interplanetary Coronal Mass Ejections",
        "pin" => "IC",
    ],
    'HEK>>SIRs' => [
        "name" => "Stream Interaction Regions",
        "pin" => "SR",
    ],
];

$rhessi = [
    "RHESSI>>Solar Flares" => [
        "name" => "Solar Flares",
        "pin" => "F2",
    ],
    "RHESSI>>Solar Flares>>Flare" => [
        "name" => "Flare",
        "contact" => " ",
        "url" => "https://umbra.nascom.nasa.gov/rhessi/rhessi_extras/flare_images_v2/hsi_flare_image_archive.html"
    ],
];

$ccmc = [
    // DONKI
    "CCMC>>DONKI" => [
        "name" => "DONKI",
        "pin" => "C3"
    ],
    "CCMC>>DONKI>>CME" => [
        "name" => "CME",
        "contact" => "Space Weather Database of NOtifications, Knowledge, Information (DONKI)",
        "url" => "https://kauai.ccmc.gsfc.nasa.gov/DONKI/"
    ],
    "CCMC>>DONKI>>Solar Flares" => [
        "name" => "Solar Flares",
        "contact" => " ",
        "url" => "https://kauai.ccmc.gsfc.nasa.gov/DONKI/"
    ],

    // Solar Flare Predictions
    "CCMC>>Solar Flare Predictions" => [
        "name" => "Solar Flare Predictions",
        "pin" => "FP"
    ],
    "CCMC>>Solar Flare Predictions>>DAFFS" => [
        "name" => "DAFFS",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>ASAP" => [
        "name" => "ASAP",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>AMOS" => [
        "name" => "AMOS",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>ASSA" => [
        "name" => "ASSA",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>Bureau of Meteorology" => [
        "name" => "Bureau of Meteorology",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>SIDC Operator" => [
        "name" => "SIDC Operator",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MOSWOC" => [
        "name" => "MOSWOC",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>Met Office" => [
        "name" => "Met Office",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>SAWS" => [
        "name" => "SAWS",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MAG4 LoS FEr" => [
        "name" => "MAG4 LoS FEr",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MAG4 LoS r" => [
        "name" => "MAG4 LoS r",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MAG4 Sharp FE" => [
        "name" => "MAG4 Sharp FE",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MAG4 Sharp" => [
        "name" => "MAG4 Sharp",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>MAG4 Sharp HMI" => [
        "name" => "MAG4 Sharp HMI",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
    "CCMC>>Solar Flare Predictions>>AEffort" => [
        "name" => "AEffort",
        "contact" => " ",
        "url" => "https://ccmc.gsfc.nasa.gov/scoreboards/flare/"
    ],
];

return array_merge($hek, $rhessi, $ccmc);
