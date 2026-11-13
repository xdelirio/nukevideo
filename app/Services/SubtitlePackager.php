<?php

namespace App\Services;

use App\Console\Commands\RepairSubtitlesCommand;
use App\Jobs\PackageVideoJob;
use App\Models\Stream;
use App\Models\Video;
use App\Services\Manifest\SubtitleFormatTransformer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Packages subtitle tracks into a directory that already holds the manifests they belong to, and
 * grafts the resulting text entries into each of them.
 *
 * Kept apart from {@see PackageVideoJob} because the same work has to run against a
 * directory rebuilt from S3 when an already-published video needs its subtitles repaired
 * ({@see RepairSubtitlesCommand}) — one implementation, two callers.
 *
 * Subtitles are packaged in SEPARATE single-segment runs (a few KB each — no point segmenting them
 * like video), one run PER format: DASH needs fMP4 `wvtt` (with an init segment, or dashjs 404s
 * fetching the BaseURL dir) while HLS needs raw `text/vtt` (hls.js can't parse fMP4) — see
 * {@see PackagerCommandBuilder::buildText}. Separate from the main run because `--segment_duration`
 * is global.
 */
class SubtitlePackager
{
    /** Manifests are matched by extension; the `_subs.*` throwaway is excluded by its leading
     *  underscore — a real output ulid never starts with one. */
    private const MANIFESTS = ['dash' => '[!_]*.mpd', 'hls' => '[!_]*.m3u8'];

    public function __construct(private ManifestEditor $manifests) {}

    /** Longer than the video, so each subtitle track is a single segment ({@see PackagerCommandBuilder::buildText}). */
    public static function segmentDurationFor(Video $video): int
    {
        return (int) ceil((float) $video->duration) + 2;
    }

    /**
     * Package `$subs` into `$dir` and graft them into every manifest sitting there. Subtitles are
     * non-critical: a format that cannot be packaged at all leaves its manifests untouched rather
     * than failing the caller.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     * @param  array<string,mixed>  $context  extra fields for the log lines (e.g. the video id)
     * @param  (callable():void)|null  $heartbeat
     */
    public function packageAndGraft(array $subs, string $dir, int $segmentDuration, int $timeout, array $context = [], ?callable $heartbeat = null): void
    {
        if ($subs === []) {
            return;
        }

        foreach (self::MANIFESTS as $format => $pattern) {
            $targets = glob("{$dir}/{$pattern}") ?: [];

            if ($targets === []) {
                continue;
            }

            $packaged = $this->package($subs, $dir, $segmentDuration, $timeout, $format, $context, $heartbeat);

            foreach ($packaged === [] ? [] : $targets as $target) {
                $this->graft($target, $format, $packaged);
            }
        }
    }

    /**
     * Package one format into `$dir` and return each throwaway manifest's contents: one document for
     * the joint run, or one per surviving track when that run failed. Public for callers that graft
     * the result themselves, under a lock ({@see AddTrackService}).
     *
     * Shaka fails the ENTIRE run over a single unparseable input, so one malformed track used to
     * strip every subtitle from the video — seven tracks lost over one stray blank line. Retrying
     * track by track keeps the ones that parse and costs extra processes only on that path.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     * @param  array<string,mixed>  $context
     * @param  (callable():void)|null  $heartbeat
     * @return list<string>
     */
    public function package(array $subs, string $dir, int $segmentDuration, int $timeout, string $format, array $context = [], ?callable $heartbeat = null): array
    {
        $builder = PackagerCommandBuilder::fromConfig();

        if ($this->runOnce($builder, $subs, $dir, $segmentDuration, $timeout, $format, $context, $heartbeat)) {
            $manifest = $this->takeSubsManifest($dir, $format);

            return $manifest === null ? [] : [$manifest];
        }

        // Nothing left to isolate: the single track we ran is the one that fails.
        if (count($subs) === 1) {
            return [];
        }

        Log::warning('Subtitle packaging failed; retrying one track at a time', $context + [
            'format' => $format, 'tracks' => count($subs),
        ]);

        $packaged = [];

        // A track that fails leaves a half-written segment dir behind, which syncs to S3 referenced by
        // nothing. Deleting it here is not safe: both formats share the dir, so dropping it on an HLS
        // failure would take the DASH segments the manifest already points at with it.
        foreach ($subs as $sub) {
            if (! $this->runOnce($builder, [$sub], $dir, $segmentDuration, $timeout, $format, $context, $heartbeat)) {
                continue;
            }

            if (($manifest = $this->takeSubsManifest($dir, $format)) !== null) {
                $packaged[] = $manifest;
            }
        }

        return $packaged;
    }

    /**
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     * @param  array<string,mixed>  $context
     * @param  (callable():void)|null  $heartbeat
     */
    private function runOnce(PackagerCommandBuilder $builder, array $subs, string $dir, int $segmentDuration, int $timeout, string $format, array $context, ?callable $heartbeat): bool
    {
        $result = Process::timeout($timeout)->run(
            $builder->buildText($subs, $dir, $segmentDuration, $format),
            $heartbeat === null ? null : fn () => $heartbeat(),
        );

        if ($result->successful()) {
            $this->writeSidecars($subs, $dir);

            return true;
        }

        Log::warning('Subtitle packager run failed', $context + [
            'format' => $format,
            'tracks' => array_column($subs, 'ulid'),
            'error' => $result->errorOutput(),
        ]);

        return false;
    }

    /**
     * Leave each packaged track's whole WebVTT next to its segments, as `{ulid}/sub.vtt`
     * ({@see Stream::SIDECAR_VTT}) — what a DASH manifest names for a link that asked for plain
     * `.vtt` subtitles ({@see SubtitleFormatTransformer}).
     *
     * Written here, into the tree every caller already uploads whole, so the file reaches the
     * playback zone with the segments it sits beside and is deleted with them. And only for a run
     * that succeeded: a track the packager refused has no entry in any manifest to point at it.
     * Once per format the caller packages, which rewrites the same bytes.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     */
    private function writeSidecars(array $subs, string $dir): void
    {
        foreach ($subs as $sub) {
            $target = "{$dir}/{$sub['ulid']}/".Stream::SIDECAR_VTT;

            // Non-critical like the rest of this class: without the file the track simply keeps
            // its packaged form for those links.
            if (! is_dir(dirname($target)) || ! @copy($sub['path'], $target)) {
                Log::warning('Could not write a subtitle sidecar', ['track' => $sub['ulid']]);
            }
        }
    }

    /**
     * Graft the packaged text entries into one manifest in place.
     *
     * A track-by-track retry yields one throwaway manifest per track. Both grafts are additive and
     * skip tracks already present, so HLS could chain per document like DASH; it still takes them in
     * one call, which tags the variants once.
     *
     * @param  list<string>  $packaged
     */
    private function graft(string $path, string $format, array $packaged): void
    {
        $original = (string) file_get_contents($path);
        $edited = $original;

        if ($format === 'dash') {
            foreach ($packaged as $subsXml) {
                $edited = $this->manifests->importDashSubtitles($edited, $subsXml) ?? $edited;
            }
        } else {
            $edited = $this->manifests->hlsAddSubtitles($edited, implode("\n", $packaged)) ?? $edited;
        }

        if ($edited !== $original) {
            file_put_contents($path, $edited);
        }
    }

    /**
     * Read the throwaway manifest a text run writes and remove it, so it can neither be mistaken for
     * a real output during the sync nor be re-read by the next run in a track-by-track retry. The
     * grafted text sets reference the segment dirs, which stay.
     */
    private function takeSubsManifest(string $dir, string $format): ?string
    {
        $path = "{$dir}/".($format === 'dash'
            ? PackagerCommandBuilder::SUBS_DASH_MANIFEST
            : PackagerCommandBuilder::SUBS_HLS_MANIFEST);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        @unlink($path);

        return $contents === false ? null : $contents;
    }
}
