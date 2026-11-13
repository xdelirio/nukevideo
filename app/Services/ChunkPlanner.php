<?php

namespace App\Services;

use App\DTOs\ChunkPlan;
use App\Jobs\ProcessChunkJob;
use App\Models\Video;

/**
 * Decides how a source is cut into the keyframe-aligned windows {@see ProcessChunkJob}
 * encodes: how long a window should be for a given video, and where the cuts actually land once
 * the source's own keyframes have their say.
 *
 * It lives here rather than inside the job that calls it because the job's other work — download,
 * mirror, probe, resolve renditions, fan out — has nothing to do with this decision and everything
 * to do with orchestrating it. Every method is pure except {@see secondsFor}, which is the only one
 * that has to read the resolved parameters back off the database.
 */
class ChunkPlanner
{
    // Window sizing. A single ProcessChunkJob pass must finish well inside the per-chunk timeout,
    // and its wall-time scales ~linearly with pixels/frame × fps — for BOTH ends: it decodes a
    // window of the source and encodes one rendition out of it. So we hold that workload near the
    // reference (a 1080p source encoded to a 1080p rendition, 30fps, REF_WINDOW seconds) and shrink
    // the window as either end's resolution/fps climb — a 4K/8K master gets proportionally shorter
    // windows instead of blowing the timeout even when its top rendition is only 1080p.
    // Floored/capped so fan-out and parallelism stay sane.
    //
    // Pixels and fps are only two thirds of the workload: the third is which encoder eats them.
    // Sized on those two alone, SVT-AV1 preset 6 gets the same 150-second window as x264 `medium`
    // and every chunk of a feature film times out — the reference window is ~10x its budget. So the
    // encoder's relative cost (config/ffmpeg.php `encode_cost` × `preset_cost`) divides the window
    // too, which is what keeps a slow codec merely slow instead of impossible.
    private const REF_PIXELS = 1920 * 1080;

    private const REF_FPS = 30.0;

    private const REF_WINDOW = 120.0;

    private const MIN_WINDOW = 8;

    private const MAX_WINDOW = 300;

    // Decoding a pixel is far cheaper than encoding one; weight the source side accordingly.
    private const DECODE_WEIGHT = 0.25;

    // Above this, `avg_frame_rate` is a VFR container lying (1000/1 and friends), not a real rate.
    // Public because every consumer of a probed rate has to apply the same disbelief — see
    // {@see CreateVideoStreamsService::deriveGopSize()}.
    public const MAX_FPS = 120.0;

    // Held past the last video packet so `-to` keeps that frame whole, and short enough to stay
    // inside the truncation guard's tolerance.
    private const TAIL_SLACK = 0.5;

    /**
     * Plan a video's chunk windows off its keyframe probe, keeping the numbers that explain the
     * result. The probe is parsed once and feeds both the cut and the measurement of what the
     * source would allow, because the second is only free while the first is being done.
     */
    public function plan(Video $video, string $probeOutput, float $containerDuration, float $startTime = 0.0): ChunkPlan
    {
        [$keyTimes, $lastPacket] = $this->readPackets($probeOutput, $startTime);

        $ideal = $this->idealFor($video);
        $target = (int) max(self::MIN_WINDOW, min(self::MAX_WINDOW, round($ideal)));

        return new ChunkPlan(
            windows: $this->groupKeyframes($keyTimes, $this->pictureEnd($lastPacket, $containerDuration), $target),
            target: $target,
            ideal: $ideal,
            keyframeInterval: $this->medianKeyframeInterval($keyTimes),
            encodeRate: $this->slowestEncodeRate($video),
            deadline: ProcessChunkJob::ffmpegTimeout(),
        );
    }

    /**
     * Wall seconds per source second for the rendition that costs the most, as measured on THIS
     * node by the probes that already ran ({@see QualityBitrateProbe}, {@see RenditionPreflight}).
     * The slowest rendition, because every rendition of a window shares its length and the dearest
     * one is the chunk that reaches the deadline first.
     *
     * Null when nothing measured — a short video skips the bitrate probe, and a rendition whose
     * hardware this node lacks skips both. The plan then has only its estimate, which is the
     * situation every video was in before this existed.
     */
    private function slowestEncodeRate(Video $video): ?float
    {
        $rates = $video->streams()->where('type', 'video')->get()
            ->map(fn ($stream) => (float) ($stream->meta['encode_rate'] ?? 0.0))
            ->filter(fn (float $rate) => $rate > 0.0);

        return $rates->isEmpty() ? null : (float) $rates->max();
    }

    /**
     * Window length (seconds) for THIS video. Every rendition must share the chunk boundaries, so
     * size a window for each one — off its own pixels, the `source_*` probe meta its chunks decode
     * ({@see CreateVideoStreamsService}) and what its encoder costs — and keep the tightest: the
     * heaviest rendition's jobs are the ones that have to stay inside the per-chunk timeout.
     */
    public function secondsFor(Video $video): int
    {
        $ideal = $this->idealFor($video);

        return (int) max(self::MIN_WINDOW, min(self::MAX_WINDOW, round($ideal)));
    }

    /**
     * The same calculation before the clamps. Kept apart because the gap between the two is the
     * only evidence that a video wanted a window this planner is not allowed to give it — an 8K AV1
     * ladder asks for 0.75s and is handed MIN_WINDOW, which no amount of sizing can fix and which
     * is worth saying out loud instead of discovering 480 seconds at a time.
     */
    private function idealFor(Video $video): float
    {
        return $video->streams()->where('type', 'video')->get()
            ->map(fn ($stream) => $this->idealWindowSeconds(
                (int) $stream->width * (int) $stream->height,
                (int) ($stream->meta['source_width'] ?? 0) * (int) ($stream->meta['source_height'] ?? 0),
                (float) ($stream->meta['source_fps'] ?? 0.0),
                ChunkTranscodeService::encodeCost(
                    data_get($stream->input_params, 'video_codec'),
                    $stream->input_params ?? [],
                ),
            ))
            ->min() ?? self::REF_WINDOW;
    }

    /**
     * Window size (seconds of source per chunk) for ONE rendition: scale the reference window
     * inversely with the per-frame workload of its chunk jobs — its own pixels plus the source
     * pixels each of them decodes — times fps, times what its encoder costs per pixel, then clamp.
     *
     * A source probed before `source_fps` existed reports no rate; fall back to the reference one
     * rather than stretching the window on a video whose framerate we can't see. $encodeCost
     * defaults to the reference for the same reason: a caller that can't resolve a codec gets the
     * sizing that was in place before costs existed, never a shorter or longer guess.
     */
    public function windowSeconds(int $renditionPixels, int $sourcePixels, float $fps, float $encodeCost = 1.0): int
    {
        $window = $this->idealWindowSeconds($renditionPixels, $sourcePixels, $fps, $encodeCost);

        return (int) max(self::MIN_WINDOW, min(self::MAX_WINDOW, round($window)));
    }

    /** The window the workload asks for, with nothing floored or capped. {@see windowSeconds} */
    public function idealWindowSeconds(int $renditionPixels, int $sourcePixels, float $fps, float $encodeCost = 1.0): float
    {
        if ($renditionPixels <= 0 && $sourcePixels <= 0) {
            return self::REF_WINDOW;
        }

        $sourcePixels = $sourcePixels > 0 ? $sourcePixels : $renditionPixels;
        $fps = $fps > 0 && $fps <= self::MAX_FPS ? $fps : self::REF_FPS;

        $pixels = $renditionPixels + $sourcePixels * self::DECODE_WEIGHT;
        $refPixels = self::REF_PIXELS * (1 + self::DECODE_WEIGHT);

        // Cheap encoders never stretch the window: a shorter one is always safe — same total work,
        // more parallelism, a cheaper retry — while a longer one spends the timeout headroom that
        // is the whole point, and the reference length is the only one this fleet has evidence for.
        return self::REF_WINDOW
            * ($refPixels / $pixels)
            * (self::REF_FPS / $fps)
            / max(1.0, $encodeCost);
    }

    /**
     * Window planning off the packet probe: keyframe-aligned blocks that stop where the VIDEO track
     * does.
     *
     * `$containerDuration` is the video's stored duration, which ffprobe reads off the container —
     * i.e. the LONGEST track. An MKV whose audio runs past the picture (common) would otherwise get
     * a last window asking for video that isn't there, and ProcessChunkJob's truncation guard would
     * reject that complete encode on every retry. The last packet is the picture's real end.
     *
     * @return list<array{0:float,1:float}> ordered [start, end] windows in seconds
     */
    public function windowsFromPackets(string $probeOutput, float $containerDuration, int $chunkSeconds, float $startTime = 0.0): array
    {
        [$keyTimes, $lastPacket] = $this->readPackets($probeOutput, $startTime);

        return $this->groupKeyframes($keyTimes, $this->pictureEnd($lastPacket, $containerDuration), $chunkSeconds);
    }

    /**
     * Sorted keyframe timestamps and the highest packet time seen, both counted from the START of
     * the file — which is how the duration they are compared against is counted, and how the
     * `-ss`/`-to` the windows become are read.
     *
     * ffprobe's packet times are not: they are the container's own timestamps, which start
     * wherever the container says ({@see MediaSource::startTime}). A broadcast or HLS-captured
     * MPEG-TS routinely starts hours in, so every keyframe sat past the end of the "duration" and
     * the whole video came out as one window — encoded as a single chunk, until it hit the
     * per-chunk timeout.
     *
     * @return array{0:list<float>,1:float}
     */
    private function readPackets(string $probeOutput, float $startTime = 0.0): array
    {
        $keyTimes = [];
        $lastPacket = 0.0;

        foreach (explode("\n", trim($probeOutput)) as $line) {
            // Each line is "<pts_time>,<flags>", e.g. "12.345000,K__".
            [$time, $flags] = array_pad(explode(',', $line), 2, '');

            if ($time === '' || $time === 'N/A') {
                continue;
            }

            // A packet may sit a hair before the container's start (B-frame reordering at the
            // head); it is the first frame either way.
            $time = max(0.0, (float) $time - $startTime);

            // B-frames put packets out of order, so the tail is the max, not the last line.
            $lastPacket = max($lastPacket, $time);

            if (str_contains($flags, 'K')) {
                $keyTimes[] = $time;
            }
        }

        sort($keyTimes);

        return [$keyTimes, $lastPacket];
    }

    private function pictureEnd(float $lastPacket, float $containerDuration): float
    {
        return $lastPacket > 0.0
            ? min($containerDuration, $lastPacket + self::TAIL_SLACK)
            : $containerDuration;
    }

    /**
     * How often the SOURCE offers a cut. This is not the GOP we ask our own encoders for
     * ({@see CreateVideoStreamsService::deriveGopSize()}) — it is a property of the file the user
     * uploaded, and it is a hard floor under every window: a master keyframed once a minute has no
     * 15-second window to give, however cheap the planner decides a chunk ought to be.
     *
     * The median, not the mean: scene-cut keyframes sit between the regular ones and would drag an
     * average toward a spacing the file does not actually offer.
     *
     * @param  list<float>  $keyTimes
     */
    private function medianKeyframeInterval(array $keyTimes): float
    {
        if (count($keyTimes) < 2) {
            return 0.0;
        }

        $gaps = [];

        for ($i = 1; $i < count($keyTimes); $i++) {
            $gaps[] = $keyTimes[$i] - $keyTimes[$i - 1];
        }

        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }

    /**
     * Close each block at the first keyframe >= $chunkSeconds past its start, so every
     * boundary lands on a source keyframe and the worker's `-ss` seek decodes no partial GOP.
     *
     * @param  list<float>  $keyTimes
     * @return list<array{0:float,1:float}>
     */
    private function groupKeyframes(array $keyTimes, float $duration, int $chunkSeconds): array
    {
        if ($duration <= 0) {
            return [];
        }

        $windows = [];
        $start = 0.0;

        foreach ($keyTimes as $t) {
            if ($t - $start >= $chunkSeconds && $t < $duration) {
                $windows[] = [$start, $t];
                $start = $t;
            }
        }

        $windows[] = [$start, $duration];

        return $windows;
    }
}
