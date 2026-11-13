<?php

namespace App\Services\Manifest;

use App\Data\Video\ManifestOptions;
use App\Enums\SubtitleFormat;
use App\Models\Stream;
use App\Services\SubtitlePackager;
use DOMElement;

/**
 * Hands a DASH player its subtitles as plain `.vtt` files instead of the fragmented-MP4 WebVTT
 * (`wvtt`) the packager writes, which a number of players cannot read. This is the default: it
 * runs for every DASH manifest whose link did not ask for `segments`.
 *
 * Each text `AdaptationSet` keeps everything a track menu shows — language, label, role, ids —
 * and only changes how the track is fetched: the segment template goes, and a `BaseURL` names the
 * sidecar file published next to the track's segments ({@see SubtitlePackager}). The URL is
 * relative and inside the playback zone on purpose: the edge appends the viewer's token to every
 * `BaseURL` it serves, and that token authorizes `play/` and nothing else — so the standalone copy
 * in the download zone, which the playback links hand out one by one, cannot be the target.
 *
 * The file is taken to be there and never looked for. Every packaged track gets one, and a check
 * would cost a request to the bucket per track on the path of a playback start. The price is that
 * a track without it is a subtitle that 404s, not one that falls back to its packaged form — so
 * the videos packaged before sidecars existed must have theirs copied in before this ships.
 *
 * HLS is left alone: it already carries raw WebVTT, and a master playlist cannot name a bare file.
 */
class SubtitleFormatTransformer implements ManifestTransformer
{
    public function applies(ManifestOptions $options, string $format): bool
    {
        return $format === 'dash' && $options->subtitlesFormat === SubtitleFormat::VTT;
    }

    public function transform(string $body, string $format, ManifestOptions $options): string
    {
        if (! $this->applies($options, $format)) {
            return $body;
        }

        $mpd = DashDocument::load($body);

        if ($mpd === null) {
            return $body;
        }

        $changed = false;

        foreach ($mpd->xpath->query('//m:AdaptationSet[@contentType="text" or m:Representation[@codecs="wvtt"]]') as $set) {
            if ($set instanceof DOMElement && $this->pointAtSidecar($mpd, $set)) {
                $changed = true;
            }
        }

        return $changed ? $mpd->save() : $body;
    }

    private function pointAtSidecar(DashDocument $mpd, DOMElement $set): bool
    {
        $template = $mpd->xpath->query('.//m:SegmentTemplate[@media]', $set)->item(0);
        $representation = $mpd->xpath->query('m:Representation', $set)->item(0);

        if (! $template instanceof DOMElement || ! $representation instanceof DOMElement) {
            return false;
        }

        // Every track is packaged under a directory named by its stream's ULID.
        $streamUlid = strstr($template->getAttribute('media'), '/', true);

        if ($streamUlid === false || ! preg_match('/^[0-9A-Za-z]{26}$/', $streamUlid)) {
            return false;
        }

        $sidecar = $streamUlid.'/'.Stream::SIDECAR_VTT;

        $template->parentNode?->removeChild($template);

        // The type and codec sit on the Representation as the packager writes them, but a set
        // may carry them too, and a `wvtt` left on either contradicts the file being named.
        foreach ([$set, $representation] as $node) {
            $node->removeAttribute('codecs');

            if ($node === $representation || $node->hasAttribute('mimeType')) {
                $node->setAttribute('mimeType', 'text/vtt');
            }
        }

        // Describes how the segments that are no longer there line up.
        $set->removeAttribute('segmentAlignment');

        $representation->insertBefore(
            $mpd->doc->createElementNS(DashDocument::NS, 'BaseURL', $sidecar),
            $representation->firstChild,
        );

        return true;
    }
}
