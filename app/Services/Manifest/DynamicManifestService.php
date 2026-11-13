<?php

namespace App\Services\Manifest;

use App\Data\Video\ManifestOptions;
use App\Models\Video;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a master manifest for one request: the file the packager wrote, as the link's options
 * want it.
 *
 * Nothing is cached here. The edge keeps what this answers for 30 seconds, under the manifest's
 * path and the link's options key, and asks again only when that copy is gone — so this runs once
 * per manifest and set of options per half minute, not once per playback start, and a cache on
 * this side as well would only double how long an edited manifest takes to show. That is also
 * why a manifest may carry nothing about one viewer ({@see ManifestTransformer}): the edge hands
 * the same built copy to everyone whose link asked for the same thing, and writes each viewer's
 * token in afterwards.
 *
 * It touches no database: the options come out of the token, the renditions out of the manifest.
 */
class DynamicManifestService
{
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
     * @param  string  $file  the manifest's name inside the video's play zone, e.g. `{outputUlid}.mpd`
     */
    public function render(string $videoUlid, string $file, ManifestOptions $options): ?string
    {
        $body = Storage::disk('s3')->get($videoUlid.'/'.Video::PLAY_DIR.'/'.$file);

        if (! is_string($body) || $body === '') {
            return null;
        }

        $format = self::formatOf($file);

        foreach ($this->applicable($options, $format) as $transformer) {
            $body = $transformer->transform($body, $format, $videoUlid, $options);
        }

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
