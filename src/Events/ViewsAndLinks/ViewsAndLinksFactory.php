<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

use Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI\CmeViews as DonkiCmeViews;
use Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI\FlareViews as DonkiFlareViews;
use Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI\GifCollector as DonkiGifCollector;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Picks an event's view builder by its path and returns its views and link,
 * computed from the raw record on every request — nothing is stored.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class ViewsAndLinksFactory
{
    /** @var array<string, ViewBuilderInterface> path prefix => builder, most specific first */
    private array $builders;

    public function __construct(
        private LoggerInterface $logger,
        private SentryClientInterface $sentry,
        ?DonkiGifCollector $donkiGifCollector = null
    ) {
        $wsa = new WsaViews();
        $this->builders = [
            'CCMC>>DONKI>>CME'              => new DonkiCmeViews($donkiGifCollector),
            'CCMC>>DONKI>>Solar Flares'     => new DonkiFlareViews(),
            'CCMC>>Solar Flare Predictions' => new FlarePredictionViews(),
            'WSA>>Coronal Hole'             => $wsa,
            'WSA>>Magnetic Connectivity'    => $wsa,
            'HEK'                           => new HekViews(),
            'RHESSI'                        => new RhessiViews(),
        ];
    }

    /**
     * @param array      $event The event row; at least `id` and `path`
     * @param array|null $raw   Its raw source record
     * @return array{views: array, link: array{url: string, text: string}}
     */
    public function build(array $event, ?array $raw): array
    {
        $id = (string) ($event['id'] ?? '');
        $default = ['url' => Event::getUrlById($id), 'text' => 'Helioviewer Events API JSON'];
        $builder = $this->builderFor((string) ($event['path'] ?? ''));
        if ($builder === null || empty($raw)) {
            return ['views' => [], 'link' => $default];
        }

        try {
            return [
                'views' => $builder->views($raw, $event),
                'link'  => $builder->link($raw, $event) ?: $default,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning("ViewsAndLinksFactory | views failed for {$id} ({$event['path']}): {$e->getMessage()}");
            $this->sentry->capture($e);

            return ['views' => [], 'link' => $default];
        }
    }

    private function builderFor(string $path): ?ViewBuilderInterface
    {
        foreach ($this->builders as $prefix => $builder) {
            if ($path === $prefix || str_starts_with($path, $prefix . '>>')) {
                return $builder;
            }
        }

        return null;
    }
}
