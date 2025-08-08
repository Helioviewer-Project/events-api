<?php

/**
 * Dictionary for event paths with custom names, pins, contacts, and URLs
 * Used by Legacy class to format hierarchical event data
 */

return [
    // 2-level paths (have name and pin)
    "CCMC>>Solar Flare Predictions" => [
        "name" => "Solar Flare Predictions",
        "pin" => "FP"
    ],
    "CCMC>>DONKI" => [
        "name" => "DONKI",
        "pin" => "C3"
    ],
    
    // 3-level paths (have name, contact, url)
    "CCMC>>Solar Flare Predictions>>DAFFS" => [
        "name" => "DAFFS",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>ASSA" => [
        "name" => "ASSA",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>BoM" => [
        "name" => "Bureau of Meteorology",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>NOAA" => [
        "name" => "NOAA",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>SIDC" => [
        "name" => "SIDC",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>MOSWOC" => [
        "name" => "MOSWOC",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>Met Office" => [
        "name" => "Met Office",
        "contact" => "",
        "url" => ""
    ],
    "CCMC>>Solar Flare Predictions>>SAWS" => [
        "name" => "SAWS",
        "contact" => "",
        "url" => ""
    ],
    
    // Add more paths as needed
];