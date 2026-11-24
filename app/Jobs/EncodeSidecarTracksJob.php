<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Exceptions\EncodeInterruptedException;
use App\Jobs\Concerns\CompletesVideo;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ChunkTranscodeService;
use App\Services\Concerns\EmitsHeartbeat;
use App\Services\CreateVideoStreamsService;
use App\Services\EncodeCommandBuilder;
use App\Support\MediaDuration;
use App\Support\Scratch;
use App\Support\WebVtt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encodes every audio and subtitle track over the mirrored source, in passes ({@see passes()}),
 * and uploads each straight to S3 — no chunking, no concat. Chunking exists
 * only to bound the heavy video encode; per-segment concat would corrupt gapless codecs like Opus
 * (each segment keeps its own pre-skip priming, so a `-c copy` merge drifts shorter than the manifest).
 *
 * Runs in parallel with the video batch and settles its own outputs via {@see CompletesVideo};
 * the lock there resolves the race over which job finalizes the video.
 */
class EncodeSidecarTracksJob implements ShouldBeUnique, ShouldQueue
{
    use CompletesVideo, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounds the unique lock, so a worker dying mid-pass cannot wedge the video's sidecar tracks.
     * Sized well past the worst legitimate run — the lock is taken once at dispatch and never
     * renewed, so anything near `$timeout` would expire while the job is still encoding and admit
     * the duplicate this exists to prevent.
     */
    public int $uniqueFor = 10800;

    // tries absorbs redeliveries after a dead worker (OOM/restart/network); real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    public $backoff = [30, 120];

    public $timeout = 1800;

    public function __construct(
        public int $videoId,
        public string $mirrorPath,
    ) {}

    /**
     * {@see PrepareVideoJob} dispatches this before it spends minutes probing, so a retry of the
     * preparation dispatches it again while the first copy is still encoding — and both passes
     * write the same scratch path, so one truncates the track the other is about to verify.
     */
    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function handle(): void
    {
        $video = Video::with(['streams' => fn ($q) => $q->whereIn('type', ['audio', 'subtitle']), 'outputs'])
            ->find($this->videoId);

        if (! $video) {
            Log::info('EncodeSidecarTracks skipped: video gone', ['video' => $this->videoId]);

            return;
        }

        if (! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        $streams = $video->streams;

        if ($streams->isEmpty()) {
            return;
        }

        // Idempotent: a prior run already staged some/all tracks on the mirror.
        $pending = $streams->reject(fn ($stream) => Storage::disk('chunks')->exists($stream->stagingPath()))->values();

        if ($pending->isNotEmpty()) {
            $video->heartbeat();
            $this->encodeAndUpload($video, $pending);
        }

        PackageVideoJob::dispatchIfReady($video);
    }

    /**
     * @param  Collection<int,Stream>  $streams
     */
    private function encodeAndUpload(Video $video, Collection $streams): void
    {
        $outputPaths = [];
        foreach ($streams as $stream) {
            $ext = $stream->type === 'subtitle' ? 'vtt' : 'mp4';
            $local = Storage::disk('local')->path($video->sidecarPath($stream, $ext));
            Scratch::ensureDirectory(dirname($local));
            $outputPaths[$stream->id] = $local;
        }

        $startedAt = microtime(true);

        try {
            $sourceUrl = PrepareVideoJob::sourceUrl($this->mirrorPath);

            foreach (self::passes($streams) as $pass) {
                $this->runPass($video, EncodeCommandBuilder::build($pass, $sourceUrl, $outputPaths), $startedAt);

                // Staged pass by pass, not all at the end. `$pending` in handle() reads the
                // mirror, so a track that is already up there is work a retry never repeats —
                // holding every upload until the last pass finished meant a timeout during the
                // second pass threw away the first one too, on every attempt.
                foreach ($pass as $stream) {
                    $this->assertCoversSource($video, $stream, $outputPaths[$stream->id]);
                    $this->repairSubtitle($stream, $outputPaths[$stream->id]);
                    $this->uploadTrack($stream, $outputPaths[$stream->id]);
                }
            }
        } finally {
            foreach ($outputPaths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * One ffmpeg pass per source audio track (all its rungs: stereo and 5.1 decode it once), and
     * one for every subtitle together. All the audio used to share one pass: a film with six
     * tracks and a stereo plus a 5.1 rung each was twelve Opus encodes in a single ffmpeg, which
     * on a loaded node overran the job's budget twice and failed the video with every track lost,
     * since nothing is staged before its pass ends. Per source track, each pass is a fraction of
     * that, and a timeout costs only the pass it cut: the retry skips whatever was staged.
     *
     * @param  Collection<int,Stream>  $streams
     * @return Collection<string,Collection<int,Stream>>
     */
    public static function passes(Collection $streams): Collection
    {
        return $streams->groupBy(fn (Stream $stream) => $stream->type === 'audio'
            ? 'audio '.(new ChunkTranscodeService($stream))->mapTarget()
            : $stream->type);
    }

    /**
     * One ffmpeg run per pass. Audio and subtitle outputs must never share a run: once the
     * sparse subtitle output runs dry ffmpeg ends the whole session with exit 0, cutting every
     * audio output minutes into the source without a single line on stderr.
     */
    private function runPass(Video $video, string $command, float $startedAt): void
    {
        Log::debug($command);

        // Every pass shares ONE job timeout. Handing each the full budget let a two-pass job
        // (audio plus subtitles) ask for twice the wall clock the worker allows, so the kill
        // landed mid-pass instead of on a pass boundary. The floor keeps a nearly-exhausted
        // budget from starting a pass that is certain to be cut off.
        $remaining = (int) ($this->timeout - 120 - (microtime(true) - $startedAt));

        $process = Process::timeout(max(60, $remaining))->run($command, function () use ($video) {
            $this->heartbeat($video);
        });

        if ($process->successful()) {
            return;
        }

        if (EncodeInterruptedException::causedTermination($process->errorOutput())) {
            Log::warning('Sidecar ffmpeg killed by signal; job will be retried', ['video' => $this->videoId]);
            throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
        }

        Log::error('Sidecar ffmpeg failed', ['video' => $this->videoId, 'error' => $process->errorOutput()]);
        throw new RuntimeException($process->errorOutput());
    }

    /**
     * A source read that dies mid-file ends the pass with a success exit code, so an audio track
     * can stop minutes into a full-length video and still get staged and packaged — the player
     * then clamps the whole timeline to where the audio ends. Fail the attempt instead.
     */
    private function assertCoversSource(Video $video, Stream $stream, string $localPath): void
    {
        if ($stream->type !== 'audio') {
            return;
        }

        $expected = self::expectedSeconds($stream, $video);
        $short = MediaDuration::truncated($localPath, $expected);

        if ($short !== null) {
            throw new RuntimeException(
                "Audio track {$stream->id} encoded {$short}s of {$expected}s; source read truncated"
            );
        }
    }

    /**
     * Seconds this track's encode has to cover: the end of the track itself, probed at ingestion
     * ({@see CreateVideoStreamsService}). The video's duration is the CONTAINER's,
     * i.e. its longest track, so it only stands in for sources probed before per-track ends were
     * recorded — where a shorter audio track still reads as truncated.
     */
    public static function expectedSeconds(Stream $stream, Video $video): float
    {
        $end = $stream->meta['source_end'] ?? null;

        return is_numeric($end) && (float) $end > 0.0 ? (float) $end : (float) $video->duration;
    }

    /**
     * ffmpeg muxes an ASS payload with a double line break into a cue containing a blank line, which
     * is invalid WebVTT and makes shaka fail the packager run later ({@see WebVtt}). Repair it before
     * the upload so the copy that reaches S3 — the one every retry and repackage reads back — is valid.
     */
    private function repairSubtitle(Stream $stream, string $localPath): void
    {
        if ($stream->type !== 'subtitle') {
            return;
        }

        $vtt = (string) file_get_contents($localPath);
        $repaired = WebVtt::sanitize($vtt);

        if ($repaired === $vtt) {
            return;
        }

        file_put_contents($localPath, $repaired);
        Log::info('Repaired malformed WebVTT', ['stream' => $stream->id]);
    }

    private function uploadTrack(Stream $stream, string $localPath): void
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException("Sidecar track output missing after encode: {$localPath}");
        }

        $handle = fopen($localPath, 'r');
        try {
            if ($handle === false || ! Storage::disk('chunks')->writeStream($stream->stagingPath(), $handle)) {
                throw new RuntimeException("Failed to stage sidecar track on mirror: {$stream->stagingPath()}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        // Size is recorded authoritatively from the packaged CMAF later ({@see \App\Jobs\PackageVideoJob}).
        Log::info('Sidecar track encoded', ['stream' => $stream->id, 'type' => $stream->type, 'size' => $size]);
    }

    public function failed(Throwable $e): void
    {
        $video = Video::with(['streams' => fn ($q) => $q->whereIn('type', ['audio', 'subtitle']), 'outputs'])
            ->find($this->videoId);

        if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
            return;
        }

        // Stop the parallel video batches — this video can no longer complete. Cancel BEFORE the
        // finalize below, or their in-flight chunks race it and die against a source it removed.
        $this->cancelEncodeBatches($video);

        // Video-wide, not per stream: subtitles are never attached to an output
        // ({@see CreateVideoStreamsService::createRenditionOutput}), and an output whose template
        // declares no audio has no sidecar stream either — walking the streams would settle
        // nothing, and finalizeVideoIfReady would then leave the video hanging until the reaper
        // fails it with a message that has nothing to do with the real error. Mirrors
        // {@see PackageVideoJob::failed()}.
        $video->outputs()
            ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
            ->update(['status' => VideoStatus::FAILED->value]);

        foreach ($video->streams as $stream) {
            // The pass encodes every track in one go, so the ones that never reached the mirror are
            // the ones it died on. Same field the panel already renders for a failed chunk.
            if (! Storage::disk('chunks')->exists($stream->stagingPath())) {
                $stream->update(['error_log' => Video::trimReason($e->getMessage())]);
            }
        }

        // The in-memory `outputs` relation still holds the pre-update statuses; the gate below
        // re-queries, but refresh keeps the model honest for anything reading it afterwards.
        $video->load('outputs');

        $this->finalizeVideoIfReady($video, $e->getMessage());
    }
}
