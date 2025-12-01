<?php

/**
 * Dictionary for event paths with custom names, pins, contacts, and URLs
 * Used by Legacy class to format hierarchical event data
 */

return [

    // RHESSI
    "RHESSI>>Solar Flares" => [
        "name" => "Solar Flares",
        "pin" => "F2",
    ],
    "RHESSI>>Solar Flares>>Flare" => [
        "name" => "Flare",
        "contact" => " ",
        "url" => "https://umbra.nascom.nasa.gov/rhessi/rhessi_extras/flare_images_v2/hsi_flare_image_archive.html"
    ],

    // CCMC DONKI
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

    // CCMC Solar Flare Predictions 
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
