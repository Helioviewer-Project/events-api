<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

use Helioviewer\EventsApi\Events\Event;

/**
 * WSA coronal-hole maps and magnetic-connectivity footpoints (one tab each),
 * linked to the CCMC WSA dashboard.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class WsaViews implements ViewBuilderInterface
{
    private const DASHBOARD_URL = 'https://ccmc.gsfc.nasa.gov/wsa-dashboard/';
    private const DASHBOARD_COMMUNITY_URL = 'https://ccmc.gsfc.nasa.gov/community-tools/WSA-Dashboard/';

    public function views(array $raw, array $event): array
    {
        $range = $raw['forecast_range'] ?? null;
        $window = is_array($range) ? implode(' to ', $range) : null;
        $inputMap = (string) ($raw['input_map'] ?? '');
        $url = Event::getUrlById((string) $event['id']);

        if (($raw['product'] ?? null) === 'coronal_hole') {
            $contours = 0;
            $points = 0;
            foreach (($raw['contours'] ?? []) as $contour) {
                $n = min(count($contour['lon'] ?? []), count($contour['lat'] ?? []));
                if ($n > 0) {
                    $contours++;
                    $points += $n;
                }
            }
            $real = $raw['real'] ?? 0;
            $content = [
                'Product'           => 'Coronal Hole boundaries',
                'Input map'         => $inputMap,
                'Realization'       => $inputMap === 'AGONG' ? $real : 'n/a',
                'Forecast time'     => $raw['forecast_time'] ?? null,
                'Forecast window'   => $window,
                'Contours'          => $contours,
                'Boundary points'   => $points,
                'Coordinate system' => 'Helio-Carrington',
            ];
            $name = 'WSA Coronal Hole';
        } else {
            $prob = $raw['probability'] ?? null;
            $content = [
                'Product'                  => 'Magnetic connectivity footpoint',
                'Target (sat)'             => (string) ($raw['sat'] ?? ''),
                'Input map'                => $inputMap,
                'Advanced days'            => $raw['adv'] ?? null,
                'Connectivity probability' => $prob !== null ? round((float) $prob * 100, 2) . '%' : null,
                'Forecast time'            => $raw['forecast_time'] ?? null,
                'Forecast window'          => $window,
                'Contour points'           => min(count($raw['lon'] ?? []), count($raw['lat'] ?? [])),
                'Coordinate system'        => 'Helio-Carrington',
            ];
            $name = 'WSA Magnetic Connectivity';
        }

        return [[
            'name'    => $name,
            'content' => $content + [
                'WSA Dashboard'        => self::DASHBOARD_URL,
                'Community Tools'      => self::DASHBOARD_COMMUNITY_URL,
                'EventsAPI URL'        => $url,
                'EventsAPI Source URL' => $url . '/source',
            ],
        ]];
    }

    public function link(array $raw, array $event): array
    {
        return ['url' => self::DASHBOARD_URL, 'text' => 'CCMC WSA Dashboard'];
    }
}
