<?php

namespace App\Jobs;

use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Exceptions\TrackAbortedException;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Observers\StreamObserver;
use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use App\Services\EncodeCommandBuilder;
use App\Services\ManifestEditor;
use App\Services\PackagerCommandBuilder;
use App\Services\TrackIngestService;
use App\Services\TrackParamsResolver;
use App\Services\TrackPublisher;
use App\Services\UsageService;
use App\Services\WebhookDispatcher;
use App\Support\MediaSource;
use App\Support\Scratch;
use App\Support\TrackProbe;
use App\Support\WebVtt;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Adds an uploaded audio or subtitle file to a video that is already published, as one more
 * stream of it ({@see TrackIngestService} created the placeholder row). Only the new
 * track is encoded and packaged; it is then grafted into the live manifests by
 * {@see TrackPublisher}. The video stays COMPLETED and playable throughout, and a failure leaves
 * its manifests exactly as they were.
 *
 * Audio follows the template's ladder per output ({@see TrackParamsResolver}), so one upload can
 * become several streams — a 5.1 dub under a [stereo, 5.1] template is two. They share the upload
 * key in `meta.track_upload`, are published together and fail together.
 */
class AddTrackJob implements ShouldQueue
{
    use Queueable;

    private const QUEUE = 'orchestration';

    /** Within the template's tolerance a dub is the right file with a different tail; past it, it
     *  is almost certainly another cut or another video altogether. */
    private const MAX_DURATION_DRIFT = 0.10;

    // No $tries: every release by WithoutOverlapping counts as an attempt, and ten subtitle
    // languages added at once queue behind each other for minutes. retryUntil() bounds the wait
    // instead, and maxExceptions bounds the real failures — a second one is not transient.
    public $maxExceptions = 2;

    public $backoff = [30, 120];

    // An audio encode of a feature-length source is the long pole; the orchestration supervisor
    // allows 1800s.
    public $timeout = 1800;

    public $failOnTimeout = true;

    public function __construct(
        public int $streamId,
        public int $videoId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * One add at a time per video: every track of a video shares the same manifest files. The lock
     * outlives the timeout so a worker killed mid-run cannot leave it held forever, nor expire it
     * while the job still runs.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("add-track:{$this->videoId}"))->releaseAfter(30)->expireAfter(1900)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $placeholder = Stream::with(['video.template', 'video.outputs.streams'])->find($this->streamId);

        if (! $placeholder || $placeholder->status !== StreamStatus::PROCESSING) {
            return;
        }

        $video = $placeholder->video;

        if ($video->status !== VideoStatus::COMPLETED->value) {
            throw TrackAbortedException::videoChanged();
        }

        $scratch = Storage::disk('local')->path($placeholder->ulid);
        Scratch::ensureDirectory("{$scratch}/raw");
        Scratch::ensureDirectory("{$scratch}/package");

        try {
            // The job's own writes are announced once, as video.track_ready, not per column.
            StreamObserver::withoutAnnouncing(fn () => $this->process($video, $placeholder, $scratch));
        } catch (TrackAbortedException $e) {
            if ($e->tracksAreGone()) {
                return;
            }

            throw $e;
        } finally {
            Storage::disk('local')->deleteDirectory($placeholder->ulid);
        }

        // The upload has become the stream's own files; nothing reads it again.
        Storage::disk('s3')->delete($placeholder->meta['track_upload']);

        $ulids = $video->streams()->get()
            ->filter(fn (Stream $s) => ($s->meta['track_upload'] ?? null) === $placeholder->meta['track_upload'])
            ->pluck('ulid')
            ->values()
            ->all();

        WebhookDispatcher::forVideo('video.track_ready', $video->fresh(), ['tracks' => $ulids]);
    }

    private function process(Video $video, Stream $placeholder, string $scratch): void
    {
        $source = Storage::disk('s3')->temporaryUrl($placeholder->meta['track_upload'], now()->addHours(2));
        $probe = app(TrackProbe::class)->probe($source, $placeholder->type);

        if ($probe === null) {
            throw new RuntimeException("The file has no {$placeholder->type} track.");
        }

        $startedAt = microtime(true);

        try {
            [$tracks, $edits] = $placeholder->type === 'audio'
                ? $this->prepareAudio($video, $placeholder, $probe, $source, $scratch)
                : $this->prepareSubtitle($video, $placeholder, $source, $scratch);
        } finally {
            UsageService::record(
                $video->user_id,
                'encoding_cpu',
                round(microtime(true) - $startedAt, 2),
                $placeholder->meta['external_user_id'] ?? $video->external_user_id ?? '',
                (int) $video->project_id,
            );
        }

        if ($edits === []) {
            throw new RuntimeException('No published manifest of this video can take this track.');
        }

        app(TrackPublisher::class)->publish($video, $tracks, "{$scratch}/package", $edits);
    }

    /**
     * @param  array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}  $probe
     * @return array{0: list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>, 1: array<string, list<callable>>}
     */
    private function prepareAudio(Video $video, Stream $placeholder, array $probe, string $source, string $scratch): array
    {
        $expected = (float) $video->duration;
        $actual = (float) ($probe['duration'] ?? 0);

        // A source whose length cannot be read (a raw ADTS stream states none) is fitted anyway:
        // refusing it as "0s long" would blame the user for the probe's blind spot.
        if ($expected > 0 && $actual > 0 && abs($actual - $expected) / $expected > self::MAX_DURATION_DRIFT) {
            throw new RuntimeException(sprintf(
                'Audio is %ss long but the video is %ss; check it is the right file.',
                round($actual, 1),
                round($expected, 1),
            ));
        }

        $entries = app(TrackParamsResolver::class)->forAudio($video, $probe['channels'], $probe['codec'], $probe['profile'], $probe['bit_rate']);

        if ($entries === []) {
            throw new RuntimeException('None of this video\'s outputs carries audio in its template.');
        }

        // One stream per distinct resolved encode, shared by every output that resolves to it —
        // the same sharing ingest does.
        $sets = [];
        foreach ($entries as $entry) {
            $signature = CreateVideoStreamsService::streamSignature($entry['params']);
            $sets[$signature] ??= ['params' => $entry['params'], 'channels' => $entry['channels'], 'outputs' => []];
            $sets[$signature]['outputs'][$entry['output']->id] = ['output' => $entry['output'], 'formats' => $entry['formats']];
        }

        // A retry finds the rungs a dead attempt left behind; they are rebuilt from scratch, or
        // they would linger as processing forever, locked against edits and deletes.
        $video->streams()
            ->whereKeyNot($placeholder->id)
            ->where('status', StreamStatus::PROCESSING->value)
            ->get()
            ->filter(fn (Stream $s) => ($s->meta['track_upload'] ?? null) === $placeholder->meta['track_upload'])
            ->each->delete();

        $layouts = collect($sets)->pluck('channels')->unique()->count();
        // The name the user asked for, not the placeholder's: an earlier attempt may already have
        // renamed it into its first rung, and suffixing that again reads "Dub (Stereo) (Stereo)".
        $baseName = $placeholder->meta['track_name'] ?? $placeholder->name;
        $keep = (bool) ($video->template?->keep_processed_files ?? false);
        $builder = $this->packager();
        $editor = app(ManifestEditor::class);
        $tracks = [];
        $edits = [];

        foreach (array_values($sets) as $i => $set) {
            $attributes = [
                'input_params' => $set['params'],
                'channels' => $set['channels'],
                'name' => $baseName.($layouts > 1 ? ' ('.CreateVideoStreamsService::channelLayoutLabel($set['channels']).')' : ''),
            ];
            $extension = ChunkTranscodeService::formatForCodec($set['params']['audio_codec'] ?? 'aac');
            $path = "{$video->ulid}/audio/".Str::ulid().".{$extension}";

            // The placeholder keeps the upload key as its path until it is published: that key is
            // what makes a redelivered webhook a no-op ({@see \App\Services\TrackIngestService}).
            $stream = $i === 0
                ? tap($placeholder)->update($attributes)
                : $video->streams()->create([
                    ...$attributes,
                    'path' => $path,
                    'type' => 'audio',
                    'status' => StreamStatus::PROCESSING,
                    'language' => $placeholder->language,
                    'meta' => $placeholder->meta,
                ]);

            $raw = "{$scratch}/raw/{$stream->ulid}.{$extension}";
            $this->run($this->audioCommand($stream, $source, $raw, $expected));

            $formats = collect($set['outputs'])->pluck('formats')->flatten()->unique()->values()->all();
            $packaged = [];

            foreach ($formats as $format) {
                $this->run($builder->buildAudio([[
                    'path' => $raw,
                    'type' => 'audio',
                    'ulid' => $stream->ulid,
                    'language' => $stream->language,
                    'name' => $stream->name,
                    'hearing_impaired' => (bool) ($stream->meta['hearing_impaired'] ?? false),
                    'visual_impaired' => (bool) ($stream->meta['visual_impaired'] ?? false),
                ]], "{$scratch}/package", $format));

                $packaged[$format] = $this->takeManifest("{$scratch}/package/".($format === 'hls'
                    ? PackagerCommandBuilder::AUDIO_HLS_MANIFEST
                    : PackagerCommandBuilder::AUDIO_DASH_MANIFEST));
            }

            foreach ($set['outputs'] as ['output' => $output, 'formats' => $outputFormats]) {
                foreach ($editor->existingManifests($video, $output) as $manifest) {
                    if (! in_array($manifest['format'], $outputFormats, true)) {
                        continue;
                    }

                    $throwaway = $packaged[$manifest['format']];
                    $peak = self::audioPeak($output);
                    $edits[$manifest['path']][] = $manifest['format'] === 'hls'
                        ? fn (string $master) => $editor->hlsAddAudio($master, $throwaway, $peak)
                        : fn (string $master) => $editor->importDashAudio($master, $throwaway);
                }
            }

            $tracks[] = [
                'stream' => $stream,
                'path' => $i === 0 ? $path : $stream->path,
                'outputs' => array_keys($set['outputs']),
                // Mirrors the main run: raw audio is a packager input, kept only on request.
                'raw' => $keep ? $raw : null,
            ];
        }

        return [$tracks, $edits];
    }

    /**
     * The nominal rate of the heaviest audio rendition an output already serves, in bits/s: what
     * its variants' declared peak already counts. Null when one of them is rate-less (VBR), in
     * which case the peak is left as it is rather than guessed.
     */
    private static function audioPeak(Output $output): ?int
    {
        $rates = $output->streams->where('type', 'audio')->map(function (Stream $stream) {
            $rate = (string) data_get($stream->input_params, 'audio_bitrate', '');

            return $rate === '' ? null : (int) round((float) $rate * (str_ends_with(strtolower($rate), 'k') ? 1000 : 1));
        });

        return $rates->contains(null) ? null : (int) $rates->max();
    }

    /**
     * Audio is fitted to the video's exact length: silence padded onto a short file, a long one cut.
     * A track that ends early or runs on past the picture makes players clamp or stall the whole
     * timeline, and the manifests' period is the video's.
     */
    private function audioCommand(Stream $stream, string $source, string $output, float $duration): string
    {
        $svc = new ChunkTranscodeService($stream);
        $reconnect = MediaSource::reconnectArguments($source);
        $length = sprintf('%.3f', $duration);

        return sprintf(
            'ffmpeg -hide_banner -y %s-i "%s" %s -af apad=whole_dur=%s -t %s -movflags +faststart -f %s "%s"',
            $reconnect === [] ? '' : implode(' ', $reconnect).' ',
            $source,
            $svc->buildAudioArguments(),
            $length,
            $length,
            $svc->outputFormat(),
            $output,
        );
    }

    /**
     * @return array{0: list<array{stream: Stream, path: string, outputs: list<int>, raw: ?string}>, 1: array<string, list<callable>>}
     */
    private function prepareSubtitle(Video $video, Stream $stream, string $source, string $scratch): array
    {
        $raw = "{$scratch}/raw/{$stream->ulid}.vtt";
        $this->run(EncodeCommandBuilder::build(collect([$stream]), $source, [$stream->id => $raw]));

        $vtt = WebVtt::sanitize((string) @file_get_contents($raw));

        // shaka fails the run on a cue-less file ({@see PackageVideoJob::subtitleInputs}).
        if (! str_contains($vtt, '-->')) {
            throw new RuntimeException('The subtitle file has no cues.');
        }

        file_put_contents($raw, $vtt);

        $builder = $this->packager();
        $editor = app(ManifestEditor::class);
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
        $formats = $video->outputs->flatMap(fn ($output) => $output->formats())->unique()->values()->all();
        $packaged = [];

        foreach ($formats as $format) {
            // One segment for the whole track, as at package time.
            $this->run($builder->buildText([$input], "{$scratch}/package", (int) ceil((float) $video->duration) + 2, $format));

            $packaged[$format] = $this->takeManifest("{$scratch}/package/".($format === 'hls'
                ? PackagerCommandBuilder::SUBS_HLS_MANIFEST
                : PackagerCommandBuilder::SUBS_DASH_MANIFEST));
        }

        $edits = [];

        foreach ($video->outputs as $output) {
            foreach ($editor->existingManifests($video, $output) as $manifest) {
                if (! isset($packaged[$manifest['format']])) {
                    continue;
                }

                $throwaway = $packaged[$manifest['format']];
                $edits[$manifest['path']][] = $manifest['format'] === 'hls'
                    ? fn (string $master) => $editor->hlsAddSubtitles($master, $throwaway)
                    : fn (string $master) => $editor->importDashSubtitles($master, $throwaway);
            }
        }

        $track = [
            'stream' => $stream,
            'path' => "{$video->ulid}/subtitle/".Str::ulid().'.vtt',
            'outputs' => [],
            // Always kept: the standalone VTT is the only input a subtitle repair can read back.
            'raw' => $raw,
        ];

        return [[$track], $edits];
    }

    private function packager(): PackagerCommandBuilder
    {
        return new PackagerCommandBuilder(
            (string) config('packager.bin'),
            (int) config('packager.segment_duration'),
        );
    }

    /** @param  string|list<string>  $command */
    private function run(string|array $command): void
    {
        $result = Process::timeout($this->timeout - 120)->run($command);

        if (! $result->successful()) {
            Log::warning('Added track command failed', ['stream' => $this->streamId, 'error' => $result->errorOutput()]);

            throw new RuntimeException('Processing the track failed: '.Str::limit(trim($result->errorOutput()), 500));
        }
    }

    /** Read a throwaway manifest and remove it, so the next run of the same dir cannot reread it. */
    private function takeManifest(string $path): string
    {
        $contents = @file_get_contents($path);
        @unlink($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The packager wrote no manifest for the track.');
        }

        return $contents;
    }

    /**
     * Every stream of this upload fails together: the placeholder stays, failed and explained, so
     * the panel and the API show what happened; the extra rungs it spawned are removed with
     * whatever they uploaded. Nothing was grafted — that only happens as the job's very last step.
     */
    public function failed(Throwable $e): void
    {
        $placeholder = Stream::with('video')->find($this->streamId);

        if (! $placeholder || ! $placeholder->video) {
            return;
        }

        // Only a track still being added is this job's to fail. A timeout after the publish
        // committed lands here too, and tearing down a track that is already in the manifests
        // would leave them pointing at deleted segments.
        if ($placeholder->status !== StreamStatus::PROCESSING) {
            return;
        }

        $video = $placeholder->video;
        $upload = $placeholder->meta['track_upload'] ?? null;

        StreamObserver::withoutAnnouncing(function () use ($video, $placeholder, $upload, $e) {
            $video->streams()
                ->whereKeyNot($placeholder->id)
                ->where('status', StreamStatus::PROCESSING->value)
                ->get()
                ->filter(fn (Stream $s) => $upload !== null && ($s->meta['track_upload'] ?? null) === $upload)
                ->each->delete();

            $placeholder->outputs()->detach();
            Storage::disk('s3')->deleteDirectory($placeholder->segmentsPath($video));

            $placeholder->update([
                'name' => $placeholder->meta['track_name'] ?? $placeholder->name,
                'status' => StreamStatus::FAILED,
                'error_log' => Video::trimReason($e->getMessage()),
            ]);
        });

        Log::warning('Adding a track failed', ['video' => $video->id, 'stream' => $placeholder->id, 'error' => $e->getMessage()]);

        WebhookDispatcher::forVideo('video.track_failed', $video->fresh(), ['tracks' => [$placeholder->ulid]]);
    }
}
