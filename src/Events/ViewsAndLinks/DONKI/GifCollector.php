<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI;

use Helioviewer\EventsApi\Exception\UpstreamFormatException;
use Helioviewer\EventsApi\Exception\UpstreamUnavailableException;
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
 * no images, or a failed read, is tried again after RETRY_AFTER. Collecting a
 * CME (warm()) also asks CCMC for each stored run's current version and reads
 * the gifs again only when it changed; API requests never ask. It never
 * throws: any failure, CCMC's or the database's, returns the gifs already
 * known (or none), so gifs cannot break a request or a collect.
 *
 * Every failure is logged and goes to Sentry, once per kind per process: CCMC
 * unreachable, down or limiting us (network errors; HTTP 429, 502, 503, 504 as
 * UpstreamUnavailableException), CCMC answering in another shape or with another
 * HTTP error (UpstreamFormatException, usually a change to this undocumented
 * API), and database errors. Only new or failed runs ever reach CCMC, so alerts
 * stay few.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class GifCollector
{
    private const API = 'https://ccmc.gsfc.nasa.gov/DONKI/api/donki/nuggets/';
    private const RETRY_AFTER = 3600;
    private const UNAVAILABLE = [429, 502, 503, 504];

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
    public function forRun(?string $runLink, bool $checkVersion = false): array
    {
        if ($runLink === null || !preg_match('#/WSA-ENLIL/(\d+)/(-?\d+)#', $runLink, $m)) {
            return [];
        }
        $id = (int) $m[1];

        try {
            return $this->lookup($id, (int) $m[2], $checkVersion);
        } catch (\Throwable $e) {
            // The database, not CCMC: a request or a collect must not fail over gifs
            $this->logger->warning("GifCollector | run {$id}: {$e->getMessage()}");
            $this->alert($e);

            return [];
        }
    }

    private function lookup(int $id, int $linkVersion, bool $checkVersion): array
    {
        $stored = ModelRun::find($id);
        $found = $stored?->status === ModelRun::FOUND;
        // A link to the latest version (-1) of a found run is only re-asked when collecting
        $recheck = $found && $checkVersion && $linkVersion <= 0;
        if ($stored && !$recheck && ($found || $stored->fetched_at > time() - self::RETRY_AFTER)) {
            return $stored->images;
        }

        $version = null;
        try {
            $version = $linkVersion > 0 ? $linkVersion : $this->latestVersion($id);
            if ($found && ($version === null || $version === $stored->version)) {
                // Same version, or CCMC no longer lists the run: keep what we have
                return $stored->images;
            }
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
        } catch (NetworkExceptionInterface | UpstreamUnavailableException | UpstreamFormatException $e) {
            $this->logger->warning("GifCollector | run {$id}: {$e->getMessage()}");
            $this->alert($e);

            // Another process may have stored the run meanwhile; never replace its gifs with a failure
            $current = ModelRun::find($id);
            if ($current?->status === ModelRun::FOUND) {
                return $current->images;
            }
            $this->store($id, $version ?? $current?->version, $current?->images ?? [], ModelRun::FAILED);

            return $current?->images ?? [];
        }
    }

    /**
     * Read and keep the gifs of every model run of a raw DONKI CME record,
     * picking up a newer version of a run already stored.
     */
    public function warm(array $cme): void
    {
        foreach (($cme['cmeAnalyses'] ?? []) as $analysis) {
            foreach (($analysis['enlilList'] ?? []) as $run) {
                $this->forRun($run['link'] ?? null, true);
            }
        }
    }

    /**
     * One atomic insert-or-update, so two processes storing the same new run
     * at once cannot collide. upsert() skips model casts, hence the json_encode.
     */
    private function store(int $id, ?int $version, array $gifs, string $status): void
    {
        ModelRun::upsert(
            [['run_id' => $id, 'version' => $version, 'images' => json_encode($gifs), 'status' => $status, 'fetched_at' => time()]],
            ['run_id'],
            ['version', 'images', 'status', 'fetched_at']
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
     * @throws UpstreamUnavailableException When CCMC is down or limiting us (429, 502, 503, 504)
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
        if (in_array($status, self::UNAVAILABLE, true)) {
            throw new UpstreamUnavailableException("{$url}: HTTP {$status}");
        }
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
