<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI;

use Helioviewer\EventsApi\Exception\UpstreamFormatException;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;
use Helioviewer\EventsApi\Utils\CachedHttpClient;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The image list (WSA-ENLIL gifs) of a DONKI model run, from the JSON API the
 * DONKI website itself uses — the run's public page no longer lists them.
 * Each run is read from CCMC once and kept in `donki_model_runs`; a run with
 * no images, or a failed read, is tried again after RETRY_AFTER. Any failure
 * returns no images.
 *
 * Anything other than a network hiccup — an HTTP error, or an answer not in
 * the expected shape — goes to Sentry (once per kind per process), since it
 * usually means CCMC changed this undocumented API.  Only new or failed runs
 * ever reach CCMC, so alerts stay few without any rate-limit store.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class GifCollector
{
    private const API = 'https://ccmc.gsfc.nasa.gov/DONKI/api/donki/nuggets/';
    private const RETRY_AFTER = 3600;

    private LoggerInterface $logger;
    private SentryClientInterface $sentry;
    private array $alerted = [];

    public function __construct(
        private CachedHttpClient $http,
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sentry = $sentry ?? new SentryVoidClient([]);
    }

    /**
     * @param string|null $runLink A run's DONKI link, e.g. https://…/DONKI/view/WSA-ENLIL/48651/-1
     * @return list<array{name: string, url: string}> The run's gifs as CCMC lists them: group name ("Inner Planets", "Timelines") and URL
     */
    public function forRun(?string $runLink): array
    {
        if ($runLink === null || !preg_match('#/WSA-ENLIL/(\d+)/(-?\d+)#', $runLink, $m)) {
            return [];
        }
        $id = (int) $m[1];
        $stored = ModelRun::find($id);
        if ($stored && ($stored->status === ModelRun::FOUND || $stored->fetched_at > time() - self::RETRY_AFTER)) {
            return $stored->images;
        }

        $version = null;
        try {
            $version = (int) $m[2] > 0 ? (int) $m[2] : $this->latestVersion($id);
            $run = $version === null ? null : $this->getJson(self::API . "enlil/{$id}/{$version}/");
            if ($run === null) {
                // CCMC has no such run (removed or never published): a normal answer, not a change
                $this->store($id, $version, [], ModelRun::NONE);

                return [];
            }
            if (!array_key_exists('links', $run) || !is_array($run['links'])) {
                throw new UpstreamFormatException("DONKI run {$id}/{$version}: no 'links' list; keys: " . implode(', ', array_keys($run)));
            }
            $gifs = $this->toGifs($run['links'], "{$id}/{$version}");
            $this->store($id, $version, $gifs, $gifs ? ModelRun::FOUND : ModelRun::NONE);

            return $gifs;
        } catch (\Throwable $e) {
            $this->logger->warning("GifCollector | run {$id}: {$e->getMessage()}");
            if (!$e instanceof NetworkExceptionInterface) {
                $this->alert($e);
            }
            // keep a list read earlier rather than replacing it with a failure
            $this->store($id, $version ?? $stored?->version, $stored?->images ?? [], ModelRun::FAILED);

            return $stored?->images ?? [];
        }
    }

    /**
     * Read and keep the gifs of every model run of a raw DONKI CME record.
     */
    public function warm(array $cme): void
    {
        foreach (($cme['cmeAnalyses'] ?? []) as $analysis) {
            foreach (($analysis['enlilList'] ?? []) as $run) {
                $this->forRun($run['link'] ?? null);
            }
        }
    }

    private function store(int $id, ?int $version, array $gifs, string $status): void
    {
        ModelRun::updateOrCreate(
            ['run_id' => $id],
            ['version' => $version, 'images' => $gifs, 'status' => $status, 'fetched_at' => time()]
        );
    }

    /**
     * @return int|null Null when CCMC has no such run
     */
    private function latestVersion(int $id): ?int
    {
        $nugget = $this->getJson(self::API . "{$id}/");
        if ($nugget === null) {
            return null;
        }
        $version = $nugget['versionId'] ?? null;
        if (!is_int($version) || $version < 1) {
            throw new UpstreamFormatException("DONKI nugget {$id}: no usable 'versionId'");
        }

        return $version;
    }

    /**
     * The cached client hands back HTTP errors as responses, so the status is
     * checked here.
     *
     * @return array|null Null on 404
     * @throws UpstreamFormatException On any other HTTP error, or a body that is not a JSON object
     */
    private function getJson(string $url): ?array
    {
        $response = $this->http->request('GET', $url);
        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        $body = (string) $response->getBody();
        if ($status >= 400) {
            throw new UpstreamFormatException("{$url}: HTTP {$status}, " . substr($body, 0, 120));
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new UpstreamFormatException("{$url}: expected a JSON object, got " . substr($body, 0, 120));
        }

        return $data;
    }

    /**
     * One Sentry event per kind of failure: a changed API fails for every run,
     * e.g. all of a re-collect, and the first report is the useful one.
     */
    private function alert(\Throwable $e): void
    {
        $kind = get_class($e) . preg_replace('/\d+/', '#', $e->getMessage());
        if (isset($this->alerted[$kind])) {
            return;
        }
        $this->alerted[$kind] = true;
        $this->sentry->capture($e);
    }

    /**
     * Kept as CCMC gives them: `name` is the group an image belongs to
     * ("Inner Planets", "Timelines"), shared by several images.
     */
    private function toGifs(array $links, string $run): array
    {
        $gifs = [];
        foreach ($links as $link) {
            $url = is_array($link) ? ($link['url'] ?? null) : null;
            if (!is_string($url) || !str_starts_with($url, 'http')) {
                throw new UpstreamFormatException("DONKI run {$run}: a link without a usable 'url': " . substr(json_encode($link), 0, 120));
            }
            $gifs[] = ['name' => is_string($link['name'] ?? null) ? trim($link['name']) : '', 'url' => $url];
        }

        return $gifs;
    }
}
