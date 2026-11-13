<?php

namespace App\Services\Manifest;

use App\Data\Video\ManifestOptions;
use App\Models\Video;
use App\Services\ManifestEditor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a master manifest for one request: the file the packager wrote, as the link's options
 * want it.
 *
 * This is on the path of every playback start — the edge asks here instead of reading the bucket,
 * and no longer caches what it gets — so the answer normally comes from the cache alone: no
 * database ever, and the bucket only when nobody asked for the same manifest with the same
 * options in the last {@see TTL} seconds.
 *
 * One entry per (manifest, options), shared by every viewer who asked for that. Sharing is safe
 * because nothing viewer-specific is ever in a built manifest: the token is written in by the
 * edge afterwards, and the options are part of the key. A transformer that put anything about
 * one viewer into the body would break that ({@see ManifestTransformer}).
 */
class DynamicManifestService
{
    /**
     * Seconds a manifest read from the bucket is trusted. Manifests are edited in place after
     * publishing — a track added, relabelled or removed ({@see ManifestEditor}) — and this is the
     * same 30 seconds the edge kept them for while it read the bucket itself, so those edits
     * surface no later than they did.
     */
    public const TTL = 30;

    /**
     * Every personalization, in the order they run. Adding one is a class here and the
     * {@see ManifestOptions} property it reads. The order only matters between two that touch the
     * same nodes, and these do not: one removes video renditions, the other rewrites text tracks.
     *
     * @var list<class-string<ManifestTransformer>>
     */
    public const TRANSFORMERS = [
        MaxResolutionTransformer::class,
        SubtitleFormatTransformer::class,
    ];

    public function __construct(private Container $container) {}

    /** `hls` or `dash`, by the manifest's extension. */
    public static function formatOf(string $file): string
    {
        return str_ends_with($file, '.m3u8') ? 'hls' : 'dash';
    }

    /**
     * The manifest to serve, or null when the bucket has no such file.
     *
     * A missing manifest is not remembered: it is either a link to a file that never existed,
     * which costs the caller and nobody else, or a manifest about to be written, which must not
     * stay hidden.
     *
     * Nothing stops several requests for the same cold manifest from each building it. That is
     * one bucket read of a few kilobytes apiece, once per {@see TTL}, and they all store the same
     * bytes — cheaper than a lock on the path of every playback start.
     *
     * @param  string  $file  the manifest's name inside the video's play zone, e.g. `{outputUlid}.mpd`
     */
    public function render(string $videoUlid, string $file, ManifestOptions $options): ?string
    {
        $path = $videoUlid.'/'.Video::PLAY_DIR.'/'.$file;
        $key = "manifest:{$path}:{$options->cacheKey()}";

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $body = Storage::disk('s3')->get($path);

        if (! is_string($body) || $body === '') {
            return null;
        }

        $format = self::formatOf($file);

        foreach ($this->applicable($options, $format) as $transformer) {
            $body = $transformer->transform($body, $format, $options);
        }

        Cache::put($key, $body, self::TTL);

        return $body;
    }

    /** @return list<ManifestTransformer> */
    private function applicable(ManifestOptions $options, string $format): array
    {
        $applicable = [];

        foreach (self::TRANSFORMERS as $class) {
            $transformer = $this->container->make($class);

            if ($transformer->applies($options, $format)) {
                $applicable[] = $transformer;
            }
        }

        return $applicable;
    }
}
