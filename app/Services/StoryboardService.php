<?php

namespace App\Services;

use App\Models\Stream;
use App\Models\Video;
use App\Support\Scratch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the WebVTT storyboard (sprite sheets + cue file) for seek-bar previews. One ffmpeg pass
 * emits every sheet; the VTT is then pure arithmetic since each cue's sheet and cell are a fixed
 * function of its index.
 *
 * It is built from an encoded rendition at packaging, not from the source at preparation. The
 * source read a whole file at the busiest moment of a video's life (30 GB for one film, over HTTP,
 * on a node already saturated with probes and audio), to keep a thumbnail every ten seconds. The
 * rendition is already on the packaging worker's local disk, a fraction of the size, with regular
 * keyframes, so each thumbnail lands on its own second instead of the nearest scene cut.
 */
class StoryboardService
{
    /** Seconds between thumbnails (also the VTT cue length). */
    private const THUMBNAIL_INTERVAL = 10;

    /**
     * A sheet may not exceed this on either side: it is the largest texture many GPUs (TV
     * browsers included) accept, and a larger sheet renders blank there.
     */
    private const MAX_SPRITE_SIZE = 4096;

    /** Keep THUMB_WIDTH * SPRITE_COLUMNS <= MAX_SPRITE_SIZE. */
    private const SPRITE_COLUMNS = 6;

    /** Rows are fitted to the thumb height under MAX_SPRITE_SIZE ({@see spriteRows}), up to this. */
    private const MAX_SPRITE_ROWS = 10;

    /**
     * 640 px wide: the trick-play size for a full HD screen. At 320 the previews were visibly soft
     * on a TV, where the player draws them several times larger than on a phone.
     */
    public const THUMB_WIDTH = 640;

    private const DEFAULT_ASPECT_RATIO = 1.777; // 16:9

    private const FFMPEG_QUALITY = 4; // ffmpeg -q:v (2 best .. 31 worst)

    /**
     * Seconds, inside packaging's own 1800 s budget. Keyframes of a small local rendition decode in
     * seconds, so a run this long is a hang, and it must not cost the packaging its attempt.
     */
    private const FFMPEG_TIMEOUT = 300;

    public function __construct(private ThumbnailService $thumbnails) {}

    /**
     * The rendition to build from: the smallest one at least THUMB_WIDTH wide, so nothing is
     * upscaled and nothing larger is decoded than the thumbs can show. A 4K keyframe costs many
     * times a 360p one, for the same 640 px thumb. When no rendition is that wide, the widest.
     *
     * @param  Collection<int, Stream>  $videoStreams
     */
    public static function pickRendition(Collection $videoStreams): ?Stream
    {
        $byWidth = $videoStreams->sortBy(fn (Stream $s) => (int) $s->width)->values();

        return $byWidth->first(fn (Stream $s) => (int) $s->width >= self::THUMB_WIDTH) ?? $byWidth->last();
    }

    /**
     * As many rows as fit under MAX_SPRITE_SIZE. A fixed ten rows made a portrait video's sheet
     * taller than the limit (ten 1138 px thumbs at this width), and such a sheet renders blank.
     */
    public static function spriteRows(int $thumbHeight): int
    {
        return max(1, min(self::MAX_SPRITE_ROWS, intdiv(self::MAX_SPRITE_SIZE, max(1, $thumbHeight))));
    }

    /** Renders the sheets and the VTT from a local rendition and uploads them to primary S3. */
    public function generate(Video $video, string $inputPath): void
    {
        $thumbHeight = $this->thumbHeight($video);
        $rows = self::spriteRows($thumbHeight);
        $perSprite = self::SPRITE_COLUMNS * $rows;
        $totalThumbs = max(1, (int) ceil($video->duration / self::THUMBNAIL_INTERVAL));

        $tmpDir = Storage::disk('tmp')->path($video->ulid);
        Scratch::ensureDirectory($tmpDir);

        $spriteCount = $this->thumbnails->generateStoryboardSprites(
            inputPath: $inputPath,
            outputPattern: "{$tmpDir}/".Video::STORYBOARD_SPRITE_PATTERN,
            interval: self::THUMBNAIL_INTERVAL,
            columns: self::SPRITE_COLUMNS,
            rows: $rows,
            thumbWidth: self::THUMB_WIDTH,
            thumbHeight: $thumbHeight,
            quality: self::FFMPEG_QUALITY,
            timeout: self::FFMPEG_TIMEOUT,
        );

        file_put_contents("{$tmpDir}/".Video::STORYBOARD_VTT_FILENAME, $this->buildVtt($totalThumbs, $perSprite, $spriteCount, $thumbHeight));

        $this->upload($video, $spriteCount);
    }

    private function thumbHeight(Video $video): int
    {
        $height = (int) (self::THUMB_WIDTH / $this->parseAspectRatio($video->aspect_ratio));

        return $height + ($height % 2); // ffmpeg needs an even height
    }

    /**
     * Guards the ratio itself, not just the denominator: ffprobe reports `0:1` for a source with
     * no display aspect ratio, and `aspect_ratio` is stored verbatim. A `0.0` here would divide by
     * zero in {@see thumbHeight}, and the video would ship without seek-bar previews.
     */
    private function parseAspectRatio(?string $aspectRatio): float
    {
        if ($aspectRatio && str_contains($aspectRatio, ':')) {
            [$w, $h] = explode(':', $aspectRatio);

            if ((float) $h > 0 && (float) $w > 0) {
                return (float) $w / (float) $h;
            }
        }

        return self::DEFAULT_ASPECT_RATIO;
    }

    /** Cues beyond the sheets ffmpeg actually produced are skipped. */
    private function buildVtt(int $totalThumbs, int $perSprite, int $spriteCount, int $thumbHeight): string
    {
        $vtt = "WEBVTT\n\n";

        for ($i = 0; $i < $totalThumbs; $i++) {
            $sprite = intdiv($i, $perSprite);

            if ($sprite >= $spriteCount) {
                break;
            }

            $cell = $i % $perSprite;
            $x = ($cell % self::SPRITE_COLUMNS) * self::THUMB_WIDTH;
            $y = intdiv($cell, self::SPRITE_COLUMNS) * $thumbHeight;

            $start = $this->formatVttTime($i * self::THUMBNAIL_INTERVAL);
            $end = $this->formatVttTime(($i + 1) * self::THUMBNAIL_INTERVAL);

            $vtt .= "{$start} --> {$end}\n";
            $vtt .= Video::storyboardSpriteFilename($sprite)."#xywh={$x},{$y},".self::THUMB_WIDTH.",{$thumbHeight}\n\n";
        }

        return $vtt;
    }

    private function formatVttTime(int $seconds): string
    {
        // Not gmdate(): hours must keep counting past 24h or cues wrap for day-long videos.
        return sprintf('%02d:%02d:%02d.000', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
    }

    /** Straight to primary S3, under the video's assets: nothing here goes through the mirror. */
    private function upload(Video $video, int $spriteCount): void
    {
        $names = [Video::STORYBOARD_VTT_FILENAME];
        for ($i = 0; $i < $spriteCount; $i++) {
            $names[] = Video::storyboardSpriteFilename($i);
        }

        $tmp = Storage::disk('tmp');
        $primary = Storage::disk('s3');

        foreach ($names as $name) {
            $localPath = $tmp->path("{$video->ulid}/{$name}");

            $handle = fopen($localPath, 'r');
            try {
                $primary->writeStream($video->assetKey($name), $handle);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                @unlink($localPath);
            }
        }
    }
}
