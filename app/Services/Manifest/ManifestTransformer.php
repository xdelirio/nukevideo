<?php

namespace App\Services\Manifest;

use App\Data\Video\ManifestOptions;

/**
 * One way a manifest can differ from what the packager wrote, applied when it is requested.
 *
 * Each personalization is one of these plus the {@see ManifestOptions} property it reads; the
 * service runs every one that applies, in order ({@see DynamicManifestService::TRANSFORMERS}).
 *
 * A transformer works from the manifest's own text and never from the database: this runs on
 * every playback start, and what a manifest says about its renditions is exactly what a player
 * will act on. It must also be pure with respect to the viewer — the result is cached and shared
 * by everyone whose options are equal ({@see ManifestOptions::cacheKey}), so nothing
 * viewer-specific may be written into it. The token is added by the edge afterwards.
 */
interface ManifestTransformer
{
    /** @param  string  $format  `hls` or `dash` */
    public function applies(ManifestOptions $options, string $format): bool;

    /**
     * The manifest as these options want it. Returns `$body` untouched when there is nothing to
     * change or it cannot be parsed: a manifest that plays unpersonalized beats one that does not
     * play.
     *
     * @param  string  $format  `hls` or `dash`
     */
    public function transform(string $body, string $format, string $videoUlid, ManifestOptions $options): string;
}
