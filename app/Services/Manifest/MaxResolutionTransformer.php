<?php

namespace App\Services\Manifest;

use App\Data\Video\ManifestOptions;
use App\Models\Output;
use DOMElement;

/**
 * Caps the ladder: video renditions above the requested resolution are dropped, the ones at or
 * below it stay.
 *
 * The rule is {@see Output::resolveCap}'s, which this replaces at playback. A rendition is
 * measured on its SHORT edge, because that is what "1080" means in either orientation — measured
 * on height, a vertical 1080x1920 ladder asked for 1080 would lose its 1080-wide rung. And when
 * every rendition is above the cap the smallest one stays: an empty ladder plays nothing, and the
 * caller asked for less, not for nothing.
 *
 * Read from the manifest's own `width`/`height` (DASH) and `RESOLUTION` (HLS) rather than from
 * the streams table, so the cap is applied to exactly the renditions this manifest lists and a
 * playback start costs no query.
 */
class MaxResolutionTransformer implements ManifestTransformer
{
    public function applies(ManifestOptions $options, string $format): bool
    {
        return $options->resolution !== null;
    }

    public function transform(string $body, string $format, string $videoUlid, ManifestOptions $options): string
    {
        if ($options->resolution === null) {
            return $body;
        }

        return $format === 'hls'
            ? $this->hls($body, $options->resolution)
            : $this->dash($body, $options->resolution);
    }

    /**
     * The short edge a rendition may have and stay: the cap itself, or the smallest rendition's
     * when even that one is above it. Null when the manifest declares no video dimensions at all.
     *
     * @param  list<int>  $shortEdges
     */
    private function limit(array $shortEdges, int $resolution): ?int
    {
        return $shortEdges === [] ? null : max($resolution, min($shortEdges));
    }

    private function dash(string $xml, int $resolution): string
    {
        $mpd = DashDocument::load($xml);

        if ($mpd === null) {
            return $xml;
        }

        $renditions = [];

        foreach ($mpd->xpath->query('//m:AdaptationSet/m:Representation') as $representation) {
            $set = $representation->parentNode;

            if (! $representation instanceof DOMElement || ! $set instanceof DOMElement) {
                continue;
            }

            // A set with a single rendition carries the dimensions itself; the packager only
            // writes them per Representation when they differ.
            $width = (int) ($representation->getAttribute('width') ?: $set->getAttribute('width'));
            $height = (int) ($representation->getAttribute('height') ?: $set->getAttribute('height'));

            if ($width > 0 && $height > 0) {
                $renditions[] = [$representation, $set, min($width, $height)];
            }
        }

        $limit = $this->limit(array_column($renditions, 2), $resolution);
        $changed = false;

        foreach ($renditions as [$representation, $set, $short]) {
            if ($short <= $limit) {
                continue;
            }

            $set->removeChild($representation);
            $changed = true;

            // Never empty: the smallest rendition always stays, but it may sit in another set.
            if ($mpd->xpath->query('m:Representation', $set)->length === 0) {
                $set->parentNode?->removeChild($set);
            } else {
                $this->recomputeMaxes($mpd, $set);
            }
        }

        return $changed ? $mpd->save() : $xml;
    }

    /** `maxWidth`/`maxHeight` describe the renditions left, or a player sizes itself for one that is gone. */
    private function recomputeMaxes(DashDocument $mpd, DOMElement $set): void
    {
        $maxWidth = 0;
        $maxHeight = 0;

        foreach ($mpd->xpath->query('m:Representation', $set) as $representation) {
            if ($representation instanceof DOMElement) {
                $maxWidth = max($maxWidth, (int) $representation->getAttribute('width'));
                $maxHeight = max($maxHeight, (int) $representation->getAttribute('height'));
            }
        }

        if ($maxWidth > 0 && $set->hasAttribute('maxWidth')) {
            $set->setAttribute('maxWidth', (string) $maxWidth);
        }

        if ($maxHeight > 0 && $set->hasAttribute('maxHeight')) {
            $set->setAttribute('maxHeight', (string) $maxHeight);
        }
    }

    private function hls(string $content, int $resolution): string
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $shortEdges = [];

        // The limit comes from the playable variants alone: an I-frame playlist follows its rung.
        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF') && ($short = $this->hlsShortEdge($line)) !== null) {
                $shortEdges[] = $short;
            }
        }

        $limit = $this->limit($shortEdges, $resolution);

        if ($limit === null) {
            return $content;
        }

        $out = [];
        $changed = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $isVariant = str_starts_with($line, '#EXT-X-STREAM-INF');
            $isIframe = str_starts_with($line, '#EXT-X-I-FRAME-STREAM-INF');

            // A variant with no RESOLUTION is audio-only, and no rung of the ladder.
            if (($isVariant || $isIframe) && ($short = $this->hlsShortEdge($line)) !== null && $short > $limit) {
                $changed = true;

                // A variant's URI is the next line that is neither blank nor a tag — the very next
                // one as the packager writes it, but nothing in HLS says so. An I-frame playlist
                // names its own inline.
                if ($isVariant) {
                    while (isset($lines[$i + 1])) {
                        $next = trim($lines[++$i]);

                        if ($next !== '' && $next[0] !== '#') {
                            break;
                        }
                    }
                }

                continue;
            }

            $out[] = $line;
        }

        return $changed ? implode("\n", $out) : $content;
    }

    private function hlsShortEdge(string $line): ?int
    {
        if (! preg_match('/RESOLUTION=(\d+)x(\d+)/', $line, $matches)) {
            return null;
        }

        return min((int) $matches[1], (int) $matches[2]);
    }
}
