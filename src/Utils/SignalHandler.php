<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

/**
 * Signal handling utility for handling Ctrl-C correctly
 */
class SignalHandler
{
    /**
     * Setup signal handling for handling Ctrl-C correctly
     * Only sets up signal handling if PCNTL extension is available
     */
    public static function setup(): void
    {
        // Only setup signal handling if PCNTL extension is available
        if (!extension_loaded('pcntl')) {
            return;
        }

        // Enable async signals for immediate response to Ctrl+C
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        
        // Handle SIGINT (Ctrl+C)
        pcntl_signal(SIGINT, function() {
            echo "\n\nReceived interrupt signal. Exiting gracefully...\n";
            exit(0);
        });
        
        // Handle SIGTERM
        pcntl_signal(SIGTERM, function() {
            echo "\n\nReceived termination signal. Exiting gracefully...\n";
            exit(0);
        });
    }
}