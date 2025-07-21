<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Models\Event;
use HelioviewerEventInterface\Translator\FlarePrediction;
use HelioviewerEventInterface\Util\LocationParser;

/**
 * FlareScoreboard prediction source
 * 
 * Fetches solar flare prediction data from CCMC's ISWA FlareScoreboard HAPI API
 * and converts it to Event model instances using the FlarePrediction translator.
 */
class FlareScoreboard extends HttpSource
{
    private string $modelId;
    private string $modelName;
    
    /**
     * FlareScoreboard constructor
     * 
     * @param string $path The source path identifier
     * @param string $modelId The prediction model ID (e.g., 'SIDC_Operator_REGIONS', 'BoM_flare1_REGIONS')
     * @param string $modelName The human-readable model name (e.g., 'SIDC Operator', 'Bureau of Meteorology')
     * @param \GuzzleHttp\Client|null $client Optional HTTP client instance
     */
    public function __construct(string $path, string $modelId, string $modelName, ?\GuzzleHttp\Client $client = null)
    {
        parent::__construct($path, $client);
        $this->modelId = $modelId;
        $this->modelName = $modelName;
    }
    
    /**
     * Build the HTTP request for fetching flare predictions from HAPI API
     * 
     * @param int $start Start timestamp for prediction query
     * @param int $end End timestamp for prediction query
     * @return \Psr\Http\Message\RequestInterface The HTTP request object
     */
    protected function request(int $start, int $end): \Psr\Http\Message\RequestInterface
    {
        $startDate = date('Y-m-d\TH:i:s', $start);
        $endDate = date('Y-m-d\TH:i:s', $end);
        
        $baseUrl = "https://iswa.gsfc.nasa.gov/IswaSystemWebApp/flarescoreboard/hapi/data";
        $queryParams = [
            'id' => $this->modelId,
            'time.min' => $startDate. '.0',
            'time.max' => $endDate . '.0',
            'format' => 'json',
        ];
        
        $uri = $baseUrl . '?' . http_build_query($queryParams);
        
        return new \GuzzleHttp\Psr7\Request('GET', $uri);
    }

    /**
     * Process the HTTP response and return processed flare prediction events
     * 
     * @param \Psr\Http\Message\ResponseInterface $response HTTP response from the HAPI API
     * @return Event[] Array of Event model instances
     */
    protected function processResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        // Check content type
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            error_log("Unexpected content type from FlareScoreboard API: " . $contentType);
            return [];
        }
        
        $responseBody = $response->getBody()->getContents();
        
        // Check if response is valid JSON
        $rawData = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON response from FlareScoreboard API: " . json_last_error_msg());
            return [];
        }

        echo "Found FlareScoreboard data with " . count($rawData['data'] ?? []) . " predictions:\n";
        
        // Generate response hash from raw data
        $responseHash = md5(json_encode($rawData));
        
        // Process raw HAPI data using FlarePrediction translator
        $events = [];
        
        try {
            // Use the FlarePrediction translator to convert HAPI data
            $translatedData = FlarePrediction::Translate($rawData, $this->modelName);
            
            if (empty($translatedData) || !isset($translatedData[0]['data'])) {
                echo "No predictions after translation\n";
                return [];
            }
            
            $predictions = $translatedData[0]['data'];
            $rawCount = count($rawData['data'] ?? []);
            $translatedCount = count($predictions);
            $ignoredCount = $rawCount - $translatedCount;
            
            echo "Translated to " . $translatedCount . " predictions\n";
            if ($ignoredCount > 0) {
                echo "IGNORED: " . $ignoredCount . " predictions due to missing coordinate information\n";
            }
            
            foreach ($predictions as $prediction) {
                try {
                    // Extract coordinates from source data
                    $latitude = 0.0;
                    $longitude = 0.0;
                    $locationTime = null;
                    
                    if (isset($prediction['source'])) {
                        $source = $prediction['source'];
                        
                        // Use the same coordinate extraction logic as FlarePrediction
                        $lat = $source['NOAALatitude'] ?? $source['CataniaLatitude'] ?? null;
                        $lon = $source['NOAALongitude'] ?? $source['CataniaLongitude'] ?? null;
                        $time = $source['NOAALocationTime'] ?? $source['CataniaLocationTime'] ?? $source['issue_time'] ?? null;
                        
                        if ($lat !== null && $lon !== null && LocationParser::IsValidLatitudeLongitude($lat, $lon)) {
                            $latitude = (float) $lat;
                            $longitude = (float) $lon;
                            $locationTime = $time;
                        }
                    }
                    
                    // Map prediction to database fields
                    $eventData = [
                        'remote_id' => $prediction['id'],
                        'response_hash' => $responseHash,
                        'source_id' => Source::CCMC,
                        'path' => $this->getPath() . ">>" . $this->modelName,
                        'start' => strtotime($prediction['start']),
                        'peak' => isset($prediction['end']) ? strtotime($prediction['end']) : strtotime($prediction['start']),
                        'end' => strtotime($prediction['end']),
                        'hv_hpc_x' => $latitude,   // Store Stonyhurst latitude
                        'hv_hpc_y' => $longitude,  // Store Stonyhurst longitude
                        'label' => $prediction['label'] ?? 'Flare Prediction',
                        'translator' => 'FlarePrediction',
                        'legacy_version' => $prediction['version'] ?? null,
                        'legacy_type' => $prediction['type'] ?? 'FP',
                        'legacy_pin' => 'FP',
                    ];

                    // Create Event model instance (without saving to database)
                    $event = new Event($eventData);
                    $events[] = $event;
                    
                    echo "Processed flare prediction: " . $event->remote_id . " (Duration: " . $event->duration . "s)\n";
                    
                } catch (\Exception $e) {
                    error_log("Error processing flare prediction: " . $e->getMessage());
                }
            }
            
        } catch (\Exception $e) {
            error_log("Error translating FlareScoreboard data: " . $e->getMessage());
        }
        
        return $events;
    }
}
