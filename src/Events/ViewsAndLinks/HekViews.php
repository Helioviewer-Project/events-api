<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

/**
 * HEK: one tab for the event type's own fields, then Observation, Recognition
 * Method and References.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class HekViews implements ViewBuilderInterface
{
    public function views(array $raw, array $event): array
    {
        $views = [];
        $eventType = (string) ($raw['event_type'] ?? '');
        $typePrefix = strtolower($eventType) . '_';

        $typeContent = [];
        $obsContent = [];
        $frmContent = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, $typePrefix) || str_starts_with($key, 'event')
                || $key === 'concept' || str_starts_with($key, 'kb_')) {
                $typeContent[$key] = $value;
            }
            if (str_starts_with($key, 'obs_')) {
                $obsContent[$key] = $value;
            }
            if (str_starts_with($key, 'frm_')) {
                $frmContent[$key] = $value;
            }
        }
        if (!empty($typeContent)) {
            $views[] = ['name' => $raw['concept'] ?? $eventType, 'content' => $typeContent];
        }
        if (!empty($obsContent)) {
            $views[] = ['name' => 'Observation', 'content' => $obsContent];
        }
        if (!empty($frmContent)) {
            $views[] = ['name' => 'Recognition Method', 'content' => $frmContent];
        }

        $refs = [];
        foreach ((is_array($raw['refs'] ?? null) ? $raw['refs'] : []) as $ref) {
            if (isset($ref['ref_name'], $ref['ref_url'])) {
                $refs[$ref['ref_name']] = $ref['ref_url'];
            }
        }
        if (!empty($refs)) {
            $views[] = ['name' => 'References', 'content' => $refs];
        }

        return $views;
    }

    public function link(array $raw, array $event): array
    {
        if (!empty($raw['kb_archivid'])) {
            return [
                'url'  => 'https://www.lmsal.com/hek/her?cmd=view-voevent&ivorn=' . $raw['kb_archivid'],
                'text' => 'HEK Archive Link',
            ];
        }
        if (!empty($raw['frm_url'])) {
            return ['url' => $raw['frm_url'], 'text' => 'Feature Recognition Method'];
        }

        return [];
    }
}
