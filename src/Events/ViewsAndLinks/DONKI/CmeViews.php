<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI;

use Helioviewer\EventsApi\Events\ViewsAndLinks\ViewBuilderInterface;
use Helioviewer\EventsApi\Utils\StonyhurstLocation;
use HelioviewerEventInterface\Util\Camel2Title;
use HelioviewerEventInterface\Util\Subarray;

/**
 * CCMC DONKI CMEs: a CME tab, then an Analysis tab per analysis followed by a
 * Model tab per model run, with the run's gifs from GifCollector, named by
 * their CCMC group ("Inner Planets: tim-den", "Timelines: Mars"). Same tabs as
 * event-interface's DonkiCme, which scraped the gifs off DONKI's run pages.
 * A CME with no usable location has no tabs, only its DONKI link.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class CmeViews implements ViewBuilderInterface
{
    public function __construct(private ?GifCollector $gifCollector = null)
    {
    }

    public function views(array $raw, array $event): array
    {
        $location = $this->location($raw);
        if ($location === null) {
            return [];
        }

        $views = [['name' => 'CME', 'content' => $this->cmeView($raw, $location)]];
        $tabgroup = 0;
        foreach (($raw['cmeAnalyses'] ?? []) as $i => $analysis) {
            $tabgroup++;
            $views[] = ['tabgroup' => $tabgroup, 'name' => 'Analysis ' . ($i + 1), 'content' => $this->analysisView($analysis)];
            foreach (($analysis['enlilList'] ?? []) as $j => $model) {
                $views[] = ['tabgroup' => $tabgroup, 'name' => 'Model ' . ($j + 1), 'content' => $this->modelView($model)];
            }
        }

        return $views;
    }

    public function link(array $raw, array $event): array
    {
        if ($this->location($raw) === null) {
            return ['url' => 'https://ccmc.gsfc.nasa.gov/DONKI/view/CME/' . ($raw['activityID'] ?? ''), 'text' => 'DONKI'];
        }

        return empty($raw['link']) ? [] : ['url' => $raw['link'], 'text' => 'Go to full analysis'];
    }

    /**
     * Latitude and longitude from the most accurate analysis, or from the
     * source location (e.g. "N10W20", "S8W110") when that analysis has none
     * usable. Both are read strictly: DONKI writes 999 for an unknown position.
     *
     * @return array{0: float, 1: float}|null Null when the CME cannot be placed
     */
    private function location(array $raw): ?array
    {
        foreach (($raw['cmeAnalyses'] ?? []) as $analysis) {
            if (!empty($analysis['isMostAccurate'])) {
                $position = StonyhurstLocation::fromLatLon($analysis['latitude'] ?? null, $analysis['longitude'] ?? null);
                if ($position !== null) {
                    return $position;
                }
                break;
            }
        }

        return StonyhurstLocation::parse($raw['sourceLocation'] ?? null);
    }

    private function cmeView(array $raw, array $location): array
    {
        $linked = array_column($raw['linkedEvents'] ?? [], 'activityID');

        return [
            'Activity ID'    => $raw['activityID'] ?? null,
            'Catalog'        => $raw['catalog'] ?? null,
            'Active Region'  => $raw['activeRegionNum'] ?? null,
            'Start Time'     => $raw['startTime'] ?? null,
            'Latitude'       => $location[0],
            'Longitude'      => $location[1],
            'External Link'  => $raw['link'] ?? null,
            'Instruments'    => implode(', ', array_column($raw['instruments'] ?? [], 'displayName')),
            'Related Events' => $linked ? implode(', ', $linked) : null,
            'Note'           => $raw['note'] ?? null,
        ];
    }

    private function analysisView(?array $analysis): ?array
    {
        if (!$analysis) {
            return null;
        }

        return Camel2Title::Parse($analysis, ['isMostAccurate', 'levelOfData', 'type', 'speed', 'halfAngle', 'note', 'link']);
    }

    /**
     * The run's fields and impacts, then its gifs.
     */
    private function modelView(?array $model): ?array
    {
        if (!$model) {
            return null;
        }
        $content = Camel2Title::Parse($model, ['modelCompletionTime', 'link', 'estimatedShockArrivalTime', 'estimatedDuration']);
        $content = Subarray::merge($content, $model, ['au', 'rmin_re', 'kp_18', 'kp_90', 'kp_135', 'kp_180']);
        $content['Is Earth GB'] = $model['isEarthGB'] ?? null;
        foreach (($model['impactList'] ?? []) as $impact) {
            $content[$impact['location'] . ' Impact'] = $impact['arrivalTime'];
            $content[$impact['location'] . ' Glancing Blow'] = $impact['isGlancingBlow'];
        }

        return $content + $this->gifFields($this->gifCollector?->forRun($model['link'] ?? null) ?? []);
    }

    /**
     * Field name => URL for each gif: its CCMC group and the part of the file
     * name that tells it apart, e.g. "Inner Planets: tim-den-Stereo_A" for
     * …_anim.tim-den-Stereo_A.gif, "Timelines: Mars" for
     * …_ENLIL_CONE_Mars_timeline.gif ("Earth" when no target is named). The
     * file name itself when a gif fits neither shape or its name is taken.
     *
     * @param list<array{name: string, url: string}> $gifs
     */
    private function gifFields(array $gifs): array
    {
        $fields = [];
        foreach ($gifs as $gif) {
            parse_str((string) parse_url($gif['url'], PHP_URL_QUERY), $query);
            $file = $query['filename'] ?? basename((string) parse_url($gif['url'], PHP_URL_PATH));

            $short = null;
            if (preg_match('/_anim\.(.+)\.gif$/', $file, $m)) {
                $short = $m[1];
            } elseif (preg_match('/_ENLIL_CONE_(?:(.+)_)?timeline\.gif$/', $file, $m)) {
                $short = ($m[1] ?? '') === '' ? 'Earth' : $m[1];
            }

            $field = ($gif['name'] !== '' && $short !== null) ? "{$gif['name']}: {$short}" : $file;
            $fields[isset($fields[$field]) ? $file : $field] = $gif['url'];
        }

        return $fields;
    }
}
