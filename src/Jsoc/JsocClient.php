<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Jsoc;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Helioviewer\EventsApi\Exception\JsocException;

/**
 * JSOC API client for fetching HARP data.
 *
 * @package    Helioviewer\EventsApi\JSOC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class JsocClient
{
    protected ClientInterface $client;
    private LoggerInterface $logger;

    public function __construct(?ClientInterface $client = null, ?LoggerInterface $logger = null)
    {
        $this->client = $client ?? $this->createDefaultClient();
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    /**
     * Fetch NOAA Active Region data from jsoc.noaa_active_regions dataset.
     *
     * @param int $noaaNumber The NOAA Active Region number
     * @return array|null Array of NOAA AR records or null if not found
     * @throws \Exception If JSOC query fails
     */
    public function fetchNoaaActiveRegions(int $noaaNumber): ?array
    {
        // Query using jsoc.noaa_active_regions dataset with [][noaa_id] syntax
        $dsQuery = "jsoc.noaa_active_regions[][{$noaaNumber}]";
        
        $url = 'http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?' . http_build_query([
            'ds' => $dsQuery,
            'op' => 'rs_list',
            'key' => 'RegionNumber,ObservationTime,LatitudeHG,LongitudeHG,LongitudeCM'
        ]);
        
        $this->logger->debug("Querying JSOC NOAA Active Regions for NOAA AR {$noaaNumber}");
        $this->logger->debug("JSOC URL: " . urldecode($url));
        
        try {
            $response = $this->client->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new JsocException("HTTP {$statusCode} from JSOC");
            }

            $rawResponse = $response->getBody()->getContents();
            $data = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new JsocException("Invalid JSON response from JSOC: " . json_last_error_msg());
            }

            if (!$data || $data['status'] !== 0) {
                $error = isset($data['error']) ? $data['error'] : 'Unknown error';
                throw new JsocException("Failed to query JSOC: " . $error);
            }

            // Parse JSOC results
            if (!isset($data['keywords']) || !isset($data['count']) || $data['count'] === 0) {
                $this->logger->info("No NOAA AR records found for region {$noaaNumber}");
                return null;
            }

            // Build field map
            $fieldMap = [];
            foreach ($data['keywords'] as $keyword) {
                $fieldMap[$keyword['name']] = $keyword['values'] ?? [];
            }

            // Convert all records
            $records = [];
            $recordCount = $data['count'];
            
            $this->logger->info("Found {$recordCount} NOAA AR records for region {$noaaNumber}");
            
            for ($i = 0; $i < $recordCount; $i++) {
                $records[] = [
                    'RegionNumber' => $fieldMap['RegionNumber'][$i] ?? null,
                    'ObservationTime' => $fieldMap['ObservationTime'][$i] ?? null,
                    'LatitudeHG' => (float)($fieldMap['LatitudeHG'][$i] ?? 0),
                    'LongitudeHG' => (float)($fieldMap['LongitudeHG'][$i] ?? 0),
                    'LongitudeCM' => (float)($fieldMap['LongitudeCM'][$i] ?? 0),
                ];
            }
            
            return $records;

        } catch (GuzzleException $e) {
            throw new JsocException("Failed to fetch NOAA AR {$noaaNumber} from JSOC: " . $e->getMessage(), 0, $e);
        }
    }

    protected function createDefaultClient(): ClientInterface
    {
        return new Client([
            'timeout' => 20.0, // 20 seconds maximum
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'Helioviewer Events API/1.0',
                'Accept' => 'application/json',
            ],
        ]);
    }


    /**
     * Fetch all HARP data for a specific date from JSOC.
     *
     * @param string $date Date in format Y-m-d
     * @return array Array of HARP records in JSOC format
     * @throws \Exception If JSOC query fails
     */
    // UNUSED: Commented out - not called anywhere in codebase
    /*
    public function fetch(string $date): array
    {
        // Convert Y-m-d to YYYY.MM.DD format for JSOC
        $jsocDate = str_replace('-', '.', $date);
        $startTime = "{$jsocDate}_00:00:00";
        $endTime = "{$jsocDate}_23:59:59";
        
        // Build query for all HARPs in the time range
        $url = 'http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?' . http_build_query([
            'ds' => "hmi.sharp_720s_nrt[][$startTime-$endTime]",
            'op' => 'rs_list',
            'key' => 'HARPNUM,NOAA_ARS,T_REC,LAT_FWT,LON_FWT'
        ]);
        
        echo "Querying JSOC for all HARP data on {$jsocDate}...\n";
        echo "URL: \"" . urldecode($url) . "\"\n\n";
        
        try {
            $response = $this->client->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \Exception("HTTP {$statusCode} from JSOC");
            }

            $rawResponse = $response->getBody()->getContents();
            $data = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON response from JSOC: " . json_last_error_msg());
            }

            if (!$data || $data['status'] !== 0) {
                $error = isset($data['error']) ? $data['error'] : 'Unknown error';
                throw new \Exception("Failed to query JSOC: " . $error);
            }

            // Parse JSOC results
            if (!isset($data['keywords']) || !isset($data['count'])) {
                return [];
            }

            $recordCount = $data['count'];
            echo "Found {$recordCount} HARP records for {$jsocDate}\n\n";

            if ($recordCount === 0) {
                return [];
            }

            // Build field map
            $fieldMap = [];
            foreach ($data['keywords'] as $keyword) {
                $fieldMap[$keyword['name']] = $keyword['values'] ?? [];
            }

            // Convert to structured records
            $records = [];
            for ($i = 0; $i < $recordCount; $i++) {
                $records[] = [
                    'harp_id' => $fieldMap['HARPNUM'][$i] ?? null,
                    'noaa_id' => $fieldMap['NOAA_ARS'][$i] ?? null,
                    'time' => $fieldMap['T_REC'][$i] ?? null,
                    'lat' => (float)($fieldMap['LAT_FWT'][$i] ?? 0),
                    'long' => (float)($fieldMap['LON_FWT'][$i] ?? 0),
                ];
            }

            return $records;

        } catch (\Exception $e) {
            throw new \Exception("Failed to fetch HARP data from JSOC: " . $e->getMessage());
        }
    }
    */

    /**
     * UNUSED: Fetch data for a specific HARP number with date range from JSOC.
     * Only called by unused HarpService.
     *
     * @param int $harpNumber The HARP number to query
     * @param string $date The date (Y-m-d format)
     * @param int $days Number of days to query
     * @return array|null Array of HARP records or null if not found
     * @throws \Exception If JSOC query fails
     */
    /*
    public function fetchByHarpNumberWithDateRange(int $harpNumber, string $date, int $days = 5): ?array
    {
        // Convert Y-m-d to JSOC date format (Y.m.d)
        $jsocDate = str_replace('-', '.', $date);
        
        // Query for specific HARP number with date range
        $url = 'http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?' . http_build_query([
            'ds' => "hmi.sharp_720s_nrt[{$harpNumber}][{$jsocDate}/{$days}d]",
            'op' => 'rs_list',
            'key' => 'HARPNUM,NOAA_ARS,T_REC,LAT_FWT,LON_FWT'
        ]);
        
        echo "  " . urldecode($url) . "\n\n";
        
        try {
            $response = $this->client->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \Exception("HTTP {$statusCode} from JSOC");
            }

            $rawResponse = $response->getBody()->getContents();
            $data = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON response from JSOC: " . json_last_error_msg());
            }

            if (!$data || $data['status'] !== 0) {
                $error = isset($data['error']) ? $data['error'] : 'Unknown error';
                throw new \Exception("Failed to query JSOC: " . $error);
            }

            // Parse JSOC results
            if (!isset($data['keywords']) || !isset($data['count']) || $data['count'] === 0) {
                return null;
            }

            // Build field map
            $fieldMap = [];
            foreach ($data['keywords'] as $keyword) {
                $fieldMap[$keyword['name']] = $keyword['values'] ?? [];
            }

            // Convert all records
            $records = [];
            $recordCount = $data['count'];
            
            for ($i = 0; $i < $recordCount; $i++) {
                $records[] = [
                    'harp_id' => $fieldMap['HARPNUM'][$i] ?? null,
                    'noaa_id' => $fieldMap['NOAA_ARS'][$i] ?? null,
                    'time' => $fieldMap['T_REC'][$i] ?? null,
                    'lat' => (float)($fieldMap['LAT_FWT'][$i] ?? 0),
                    'long' => (float)($fieldMap['LON_FWT'][$i] ?? 0),
                ];
            }
            
            // Return all records (let HarpService handle the filtering)
            return $records;

        } catch (\Exception $e) {
            throw new \Exception("Failed to fetch HARP {$harpNumber} from JSOC: " . $e->getMessage());
        }
    }
    */

    /**
     * UNUSED: Fetch data for a specific NOAA Active Region number from JSOC.
     * Only called by unused NoaaService method.
     *
     * @param int $noaaNumber The NOAA AR number to query
     * @param string $date The date (Y-m-d format)
     * @param int $days Number of days to query (default 5)
     * @return array|null HARP record or null if not found
     * @throws \Exception If JSOC query fails
     */
    /*
    public function fetchByNoaaNumber(int $noaaNumber, string $date, int $days = 5): ?array
    {
        // Convert Y-m-d to JSOC date format (Y.m.d)
        $jsocDate = str_replace('-', '.', $date);
        
        // Query N days window from the date
        $dsQuery = "hmi.sharp_720s_nrt[][$jsocDate/{$days}d][? NOAA_ARS~\"{$noaaNumber}\" ?]";
        
        $url = 'http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?' . http_build_query([
            'ds' => $dsQuery,
            'op' => 'rs_list',
            'key' => 'HARPNUM,NOAA_ARS,T_REC,LAT_FWT,LON_FWT'
        ]);
        
        echo "  " . urldecode($url) . "\n\n";
        
        echo "Querying JSOC for NOAA AR {$noaaNumber}...\n";
        echo "URL: \"" . urldecode($url) . "\"\n\n";
        
        try {
            $response = $this->client->request('GET', $url);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new \Exception("HTTP {$statusCode} from JSOC");
            }

            $rawResponse = $response->getBody()->getContents();
            $data = json_decode($rawResponse, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON response from JSOC: " . json_last_error_msg());
            }

            if (!$data || $data['status'] !== 0) {
                $error = isset($data['error']) ? $data['error'] : 'Unknown error';
                throw new \Exception("Failed to query JSOC: " . $error);
            }

            // Parse JSOC results
            if (!isset($data['keywords']) || !isset($data['count']) || $data['count'] === 0) {
                return null;
            }

            // Build field map
            $fieldMap = [];
            foreach ($data['keywords'] as $keyword) {
                $fieldMap[$keyword['name']] = $keyword['values'] ?? [];
            }

            // Convert all records (should already be filtered by NOAA_ARS query)
            $records = [];
            $recordCount = $data['count'];
            
            echo "Found {$recordCount} records for NOAA AR {$noaaNumber}\n";
            
            for ($i = 0; $i < $recordCount; $i++) {
                $records[] = [
                    'harp_id' => $fieldMap['HARPNUM'][$i] ?? null,
                    'noaa_id' => $fieldMap['NOAA_ARS'][$i] ?? null,
                    'time' => $fieldMap['T_REC'][$i] ?? null,
                    'lat' => (float)($fieldMap['LAT_FWT'][$i] ?? 0),
                    'long' => (float)($fieldMap['LON_FWT'][$i] ?? 0),
                ];
            }
            
            // Return all records (let HarpService handle the filtering)
            return $records;

        } catch (\Exception $e) {
            echo "Exception in fetchByNoaaNumber for NOAA AR {$noaaNumber}: " . $e->getMessage() . "\n";
            throw new \Exception("Failed to fetch NOAA AR {$noaaNumber} from JSOC: " . $e->getMessage());
        }
    }
    */
}
