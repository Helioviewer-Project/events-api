<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\WSA;

use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Base for WSA dashboard sources (Coronal Holes, Footpoints).
 *
 * WSA needs many GETs per run (sat × input_map × realization/adv), so subclasses
 * override fetchRawData() to loop the parameter space — discovered live from the
 * matching *_capabilities endpoint — and flatten each response's forecast[] into
 * one raw record per contour/point.
 *
 * Every GET goes through the inherited {@see JsonSource::makeJsonRequest()} (status
 * check + UTF-8/JSON sanitise + decode). The WSA browser headers ({@see HEADERS})
 * ride on the injected client (configured in Collector::createStandard), and capabilities
 * are cached ~1 day in the shared cache (the client cache is only 1 h). See
 * docs/WSA_PLAN.md.
 *
 * @package Helioviewer\EventsApi\Events\Sources\WSA
 */
abstract class Source extends JsonSource
{
    protected const API_BASE = 'https://ccmc.gsfc.nasa.gov/wsa-dashboard/api';

    /**
     * CCMC returns 403 to a non-browser User-Agent; the identifier header lets
     * them recognise/allowlist our traffic despite the generic browser UA.
     * Applied to the WSA client's inner Guzzle in Collector::createStandard().
     */
    public const HEADERS = [
        'User-Agent'           => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        'Referer'              => 'https://ccmc.gsfc.nasa.gov/wsa-dashboard/',
        'X-Helioviewer-Client' => 'events-api WSA collector',
        'Accept'               => 'application/json',
    ];

    /** Pause between requests — the WSA API throttles bursts. */
    protected int $sleepMicros = 1_500_000; // 1.5 s

    public function __construct(
        ClientInterface $client,
        protected ?CacheInterface $cache = null
    ) {
        parent::__construct($client);
    }

    // WSA builds URLs per-combo inside fetchRawData(); the JsonSource single-URL
    // template is bypassed, so these abstract hooks are inert.
    protected function buildUrl(TimeRange $range): string
    {
        throw new \LogicException('WSA sources build URLs per combo in fetchRawData()');
    }

    protected function extractDataFromResponse(array $jsonData): array
    {
        throw new \LogicException('unused by WSA sources');
    }

    /**
     * Fetch a capabilities endpoint (`{input_maps, locations, ...}`), cached ~1 day
     * in the shared cache. Fetched via the inherited makeJsonRequest().
     */
    protected function capabilities(string $endpoint): array
    {
        $key = 'wsa_caps:' . md5($endpoint);

        $hit = $this->cache?->get($key);
        if (is_array($hit)) {
            return $hit;
        }

        $caps = $this->makeJsonRequest($endpoint);
        $this->cache?->set($key, $caps, 86400);
        return $caps;
    }

    /**
     * WSA calendar-range params. `end_date` is exclusive upstream, so we map the
     * last day + 1; a same-date range silently returns [] (see docs/WSA_PLAN.md Q1).
     *
     * @return array{start_date:string, end_date:string}
     */
    protected function dateParams(TimeRange $range): array
    {
        return [
            'start_date' => date('Y-m-d', $range->start),
            'end_date'   => date('Y-m-d', $range->end + 86400),
        ];
    }
}
