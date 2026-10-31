<?php

namespace App\Services;

use App\Enums\StreamStatus;
use App\Jobs\AddTrackJob;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Observers\StreamObserver;
use App\Support\TrackProbe;
use App\Support\WebVtt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Turns an uploaded audio or subtitle file into packaged streams of an already-published video,
 * ready for {@see TrackPublisher} to graft ({@see AddTrackJob} drives it). Only the new track is
 * encoded and packaged; nothing published is touched here.
 *
 * Audio follows the ladder of the template version the video was encoded with, output by output
 * ({@see TrackParamsResolver}), so one upload can become several streams — a 5.1 dub under a
 * [stereo, 5.1] template is two. They share the upload key in `meta.track_upload`
 * ({@see Video::streamsOfTrackUpload}), are published together and fail together.
 */
class AddTrackService
{
    /** Within this a dub is the right file with a different tail; past it, it is almost certainly
     *  another cut or another video altogether. */
    private const MAX_DURATION_DRIFT = 0.10;

    public function __construct(
        private TrackParamsResolver $params,
        private ManifestEditor $editor,
        private SubtitlePackager $subtitles,
        private StreamManagementService $streams,
    ) {}

    /**
     * Encode and package the upload behind `$placeholder` into `$scratch`, and describe how to
     * publish it: each stream with its final key and outputs, and per manifest key the grafts to
     * apply ({@see TrackPublisher::publish}).
     *
     * @return array{tracks: list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>, edits: array<string, list<callable(string): ?string>>}
     */
    public function prepare(Video $video, Stream $placeholder, string $scratch, int $timeout): array
    {
        if ((float) $video->duration <= 0) {
            throw new RuntimeException('The video has no known duration to fit the track to.');
        }

        $source = Storage::disk('s3')->temporaryUrl($placeholder->meta['track_upload'], now()->addSeconds($timeout + 600));
        $probe = app(TrackProbe::class)->probe($source, $placeholder->type);

        if ($probe === null) {
            throw new RuntimeException("The file has no {$placeholder->type} track.");
        }

        $prepared = $placeholder->type === 'audio'
            ? $this->prepareAudio($video, $placeholder, $probe, $source, $scratch, $timeout)
            : $this->prepareSubtitle($video, $placeholder, $source, $scratch, $timeout);

        if ($prepared['edits'] === []) {
            throw new RuntimeException('No published manifest of this video can take this track.');
        }

        return $prepared;
    }

    /**
     * Fail every stream of the upload behind `$placeholder`, explained, and announce it. Nothing on
     * S3 is touched but the upload itself: segments only ever reach S3 inside the publish, which
     * removes them when it fails ({@see TrackPublisher}), while a worker killed in the instant
     * between its manifest writes and its commit leaves them referenced — deleting them here would
     * break playback. A failed track still in a manifest is cleaned when it is deleted
     * ({@see StreamManagementService::destroy}). Idempotent: only processing rows change.
     */
    public function fail(Stream $placeholder, string $reason): void
    {
        // A job timing out mid-publish is failed from inside the transaction it is about to lose;
        // writes made in it would vanish with the rollback. Only that transaction is unwound.
        TrackPublisher::abandonOpenPublish();

        $video = $placeholder->video;
        $upload = $placeholder->meta['track_upload'] ?? null;

        if (! $video || $upload === null) {
            return;
        }

        $failed = $video->streamsOfTrackUpload($upload)
            ->filter(fn (Stream $stream) => $stream->status === StreamStatus::PROCESSING);

        if ($failed->isEmpty()) {
            return;
        }

        StreamObserver::withoutAnnouncing(function () use ($failed, $reason) {
            foreach ($failed as $stream) {
                $stream->update([
                    // A ladder renamed the rows "Dub (Stereo)", "Dub (5.1)"; the user named it "Dub".
                    'name' => count($failed) > 1 ? $stream->name : ($stream->meta['track_name'] ?? $stream->name),
                    'status' => StreamStatus::FAILED,
                    'error_log' => Video::trimReason($reason),
                ]);
            }
        });

        try {
            Storage::disk('s3')->delete($upload);
        } catch (Throwable $e) {
            Log::warning('Could not delete a failed track upload', ['key' => $upload, 'error' => $e->getMessage()]);
        }

        Log::warning('Adding a track failed', ['video' => $video->id, 'upload' => $upload, 'error' => $reason]);

        WebhookDispatcher::forVideo('video.track_failed', $video->fresh(), ['tracks' => $failed->pluck('ulid')->values()->all()]);
    }

    /**
     * @param  array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}  $probe
     * @return array{tracks: list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>, edits: array<string, list<callable(string): ?string>>}
     */
    private function prepareAudio(Video $video, Stream $placeholder, array $probe, string $source, string $scratch, int $timeout): array
    {
        $duration = (float) $video->duration;
        $actual = (float) ($probe['duration'] ?? 0);

        // A source whose length cannot be read (a raw ADTS stream states none) is fitted anyway:
        // refusing it as "0s long" would blame the user for the probe's blind spot.
        if ($actual > 0 && abs($actual - $duration) / $duration > self::MAX_DURATION_DRIFT) {
            throw new RuntimeException(sprintf(
                'Audio is %ss long but the video is %ss; check it is the right file.',
                round($actual, 1),
                round($duration, 1),
            ));
        }

        $sets = $this->audioSets($video, $probe);
        $streams = $this->audioStreams($video, $placeholder, $sets);
        $keep = (bool) ($video->template?->keep_processed_files ?? false);

        // One read of the source for every rung — it sits on primary S3, read once per video.
        $raws = [];
        foreach ($streams as $i => $stream) {
            $raws[$stream->id] = "{$scratch}/raw/{$stream->ulid}.".ChunkTranscodeService::formatForCodec($sets[$i]['params']['audio_codec'] ?? 'aac');
        }
        $this->run(EncodeCommandBuilder::build(collect($streams), $source, $raws, fitDuration: $duration), $timeout);

        $builder = PackagerCommandBuilder::fromConfig();
        $tracks = [];
        $edits = [];

        foreach ($streams as $i => $stream) {
            $packaged = [];

            foreach (collect($sets[$i]['outputs'])->pluck('formats')->flatten()->unique() as $format) {
                $this->run($builder->buildAudio([[
                    'path' => $raws[$stream->id],
                    'type' => 'audio',
                    'ulid' => $stream->ulid,
                    'language' => $stream->language,
                    'name' => $stream->name,
                    'hearing_impaired' => (bool) ($stream->meta['hearing_impaired'] ?? false),
                    'visual_impaired' => (bool) ($stream->meta['visual_impaired'] ?? false),
                ]], "{$scratch}/package", $format), $timeout);

                $packaged[$format] = $this->takeManifest("{$scratch}/package/".($format === 'hls'
                    ? PackagerCommandBuilder::AUDIO_HLS_MANIFEST
                    : PackagerCommandBuilder::AUDIO_DASH_MANIFEST));
            }

            foreach ($sets[$i]['outputs'] as ['output' => $output, 'formats' => $formats]) {
                // A rate-less (VBR) group's peak is unknown: count the whole track rather than
                // understate what the variants need.
                $peak = $output->nominalAudioPeakBps() ?? 0;

                foreach ($this->editor->existingManifests($video, $output) as $manifest) {
                    if (in_array($manifest['format'], $formats, true)) {
                        $throwaway = $packaged[$manifest['format']];
                        $edits[$manifest['path']][] = $manifest['format'] === 'hls'
                            ? fn (string $master) => $this->editor->hlsAddAudio($master, $throwaway, $peak)
                            : fn (string $master) => $this->editor->importDashAudio($master, $throwaway);
                    }
                }
            }

            $tracks[] = [
                'stream' => $stream,
                'path' => $sets[$i]['path'],
                'outputs' => array_keys($sets[$i]['outputs']),
                // Mirrors the main run: raw audio is a packager input, kept only on request.
                'raw' => $keep ? $raws[$stream->id] : null,
            ];
        }

        return ['tracks' => $tracks, 'edits' => $edits];
    }

    /**
     * One entry per distinct resolved encode, shared by every output that resolves to it — the
     * same sharing ingest does — each with its final name and storage key.
     *
     * @param  array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}  $probe
     * @return list<array{params: array<string, mixed>, channels: int, name: string, path: string, outputs: array<int, array{output: Output, formats: list<string>}>}>
     */
    private function audioSets(Video $video, array $probe): array
    {
        $entries = $this->params->forAudio($video, $probe['channels'], $probe['codec'], $probe['profile'], $probe['bit_rate']);

        if ($entries === []) {
            throw new RuntimeException("None of this video's outputs carries audio in its template.");
        }

        $sets = [];
        foreach ($entries as $entry) {
            $signature = CreateVideoStreamsService::streamSignature($entry['params']);
            $sets[$signature] ??= ['params' => $entry['params'], 'channels' => $entry['channels'], 'outputs' => []];
            $sets[$signature]['outputs'][$entry['output']->id] = ['output' => $entry['output'], 'formats' => $entry['formats']];
        }

        return array_map(fn (array $set) => [
            ...$set,
            'path' => "{$video->ulid}/audio/".Str::ulid().'.'.ChunkTranscodeService::formatForCodec($set['params']['audio_codec'] ?? 'aac'),
        ], array_values($sets));
    }

    /**
     * The stream rows of the ladder: the placeholder becomes the first rung, the rest are created.
     * Named as ingest names a source ladder — the layout suffixed only when there is more than
     * one — and held to the same uniqueness as every other track, the suffixed names included: a
     * 5.1 dub named "English" on a video whose source already gave "English (Stereo)" would put two
     * renditions with one NAME in the HLS audio group.
     *
     * @param  list<array{params: array<string, mixed>, channels: int, name: string, path: string, outputs: array}>  $sets
     * @return list<Stream>
     */
    private function audioStreams(Video $video, Stream $placeholder, array &$sets): array
    {
        $upload = $placeholder->meta['track_upload'];

        // A retry finds the rungs a dead attempt left behind; they are rebuilt from scratch, or
        // they would linger as processing forever, locked against edits and deletes.
        $video->streamsOfTrackUpload($upload)
            ->reject(fn (Stream $stream) => $stream->is($placeholder))
            ->filter(fn (Stream $stream) => $stream->status === StreamStatus::PROCESSING)
            ->each->delete();

        // The name the user asked for, not the placeholder's: an earlier attempt may already have
        // renamed it into its first rung.
        $base = $placeholder->meta['track_name'] ?? $placeholder->name;
        $layouts = collect($sets)->pluck('channels')->unique()->count();
        $taken = [];

        foreach ($sets as $i => $set) {
            $name = $base.($layouts > 1 ? ' ('.CreateVideoStreamsService::channelLayoutLabel($set['channels']).')' : '');

            // Two outputs resolving the same layout to different encodes would share a name.
            for ($n = 2, $candidate = $name; isset($taken[mb_strtolower($candidate)]); $n++) {
                $candidate = "{$name} ({$n})";
            }
            $taken[mb_strtolower($candidate)] = true;

            try {
                $this->streams->assertNameAvailable($video->id, 'audio', $candidate, false, $placeholder->id);
            } catch (ValidationException) {
                throw new RuntimeException("Another audio track of this video is already named \"{$candidate}\".");
            }

            $sets[$i]['name'] = $candidate;
        }

        $streams = [];
        foreach ($sets as $i => $set) {
            $attributes = ['input_params' => $set['params'], 'channels' => $set['channels'], 'name' => $set['name']];

            // The placeholder keeps the upload key as its path until it is published: that key is
            // what makes a redelivered webhook a no-op ({@see TrackIngestService}).
            $streams[] = $i === 0
                ? tap($placeholder)->update($attributes)
                : $video->streams()->create([
                    ...$attributes,
                    'path' => $set['path'],
                    'type' => 'audio',
                    'status' => StreamStatus::PROCESSING,
                    'language' => $placeholder->language,
                    'meta' => $placeholder->meta,
                ]);
        }

        return $streams;
    }

    /**
     * @return array{tracks: list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>, edits: array<string, list<callable(string): ?string>>}
     */
    private function prepareSubtitle(Video $video, Stream $stream, string $source, string $scratch, int $timeout): array
    {
        $raw = "{$scratch}/raw/{$stream->ulid}.vtt";
        $this->run(EncodeCommandBuilder::build(collect([$stream]), $source, [$stream->id => $raw]), $timeout);

        $vtt = WebVtt::sanitize((string) @file_get_contents($raw));

        // shaka fails the run on a cue-less file ({@see \App\Jobs\PackageVideoJob::subtitleInputs}).
        if (! str_contains($vtt, '-->')) {
            throw new RuntimeException('The subtitle file has no cues.');
        }

        file_put_contents($raw, $vtt);

        $input = [
            'path' => $raw,
            'type' => 'subtitle',
            'ulid' => $stream->ulid,
            'language' => $stream->language,
            'forced' => (bool) $stream->forced,
            'hearing_impaired' => (bool) ($stream->meta['hearing_impaired'] ?? false),
            'name' => $stream->name,
        ];

        // Every output carries every subtitle, in whichever formats each one was packaged in.
        $packaged = [];
        foreach ($video->outputs->flatMap(fn ($output) => $output->formats())->unique() as $format) {
            $manifests = $this->subtitles->package(
                [$input],
                "{$scratch}/package",
                SubtitlePackager::segmentDurationFor($video),
                $timeout,
                $format,
                ['video' => $video->id, 'stream' => $stream->id],
            );

            if ($manifests === []) {
                throw new RuntimeException("The subtitle track could not be packaged for {$format}.");
            }

            $packaged[$format] = $manifests[0];
        }

        $edits = [];
        foreach ($video->outputs as $output) {
            foreach ($this->editor->existingManifests($video, $output) as $manifest) {
                if (isset($packaged[$manifest['format']])) {
                    $throwaway = $packaged[$manifest['format']];
                    $edits[$manifest['path']][] = $manifest['format'] === 'hls'
                        ? fn (string $master) => $this->editor->hlsAddSubtitles($master, $throwaway)
                        : fn (string $master) => $this->editor->importDashSubtitles($master, $throwaway);
                }
            }
        }

        return ['tracks' => [[
            'stream' => $stream,
            'path' => "{$video->ulid}/subtitle/".Str::ulid().'.vtt',
            'outputs' => [],
            // Always kept: the standalone VTT is the only input a subtitle repair can read back.
            'raw' => $raw,
        ]], 'edits' => $edits];
    }

    /** @param  string|list<string>  $command */
    private function run(string|array $command, int $timeout): void
    {
        $result = Process::timeout($timeout)->run($command);

        if (! $result->successful()) {
            Log::warning('Added track command failed', ['error' => $result->errorOutput()]);

            throw new RuntimeException('Processing the track failed: '.Str::limit(trim($result->errorOutput()), 500));
        }
    }

    /** Read a throwaway manifest and remove it, so the next run in the same dir cannot reread it. */
    private function takeManifest(string $path): string
    {
        $contents = @file_get_contents($path);
        @unlink($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The packager wrote no manifest for the track.');
        }

        return $contents;
    }
}
