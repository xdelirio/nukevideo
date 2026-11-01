<?php

namespace App\Jobs;

use App\Exceptions\EncodeInterruptedException;
use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ChunkPlanner;
use App\Services\ChunkProgressReporter;
use App\Services\ChunkTranscodeService;
use App\Services\Concerns\EmitsHeartbeat;
use App\Services\EncodeCommandBuilder;
use App\Services\UsageService;
use App\Services\VideoService;
use App\Support\MediaDuration;
use App\Support\MediaSource;
use App\Support\Scratch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encodes ONE rendition of one chunk window and uploads it straight to the shared chunk store.
 * One job per (window × rendition) keeps every job's runtime bounded by a single ffmpeg pass —
 * it can never outgrow the worker timeout as rendition counts climb — and leaves nothing on the
 * worker's local disk for a later job to depend on, so jobs are free to land on any worker node.
 */
class ProcessChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, EmitsHeartbeat, InteractsWithQueue, Queueable, SerializesModels;

    // tries absorbs redeliveries after a dead worker (OOM/restart/network); real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    public $backoff = [30];

    public function __construct(
        public int $streamId,
        public string $mirrorPath,
        public int $chunkIndex,
        public float $start,
        public float $end,
    ) {}

    public function middleware(): array
    {
        return [new SkipIfBatchCancelled];
    }

    /**
     * The wall time one chunk's ffmpeg may take. Public because the planner sizes windows against
     * this exact number ({@see ChunkPlanner}) and a second copy of the arithmetic
     * would drift from it silently — the drift only ever showing up as a timeout.
     */
    public static function ffmpegTimeout(): int
    {
        return (int) config('nuke.video.worker_timeout') - 120;
    }

    public function handle(): void
    {
        // A job of a run that is already over. SkipIfBatchCancelled lets it through, since a batch
        // row that is gone reads as "not cancelled": a timed-out job redelivered after the retry
        // would encode its window into the new run.
        if ($this->belongsToFinishedRun()) {
            Log::info('ProcessChunk skipped: its run was retried', ['stream' => $this->streamId, 'chunk' => $this->chunkIndex]);

            return;
        }

        $stream = Stream::with(['video', 'outputs'])->find($this->streamId);

        if (! $stream || ! $stream->video) {
            Log::info('ProcessChunk skipped: stream or video gone', ['stream' => $this->streamId]);

            return;
        }

        $video = $stream->video;
        $outputs = $stream->outputs;
        $chunkKey = $video->chunkKey($stream, $this->chunkIndex);
        $localPath = $this->localPath($chunkKey);

        // Idempotent: a prior complete run already uploaded this chunk. Re-settle progress and bail.
        // Every name the index may carry is checked, not just the one this build writes — a video
        // staged before the padding widened would otherwise read as a miss and re-encode in full,
        // losing the "a retry is a cache hit" property retries are built on.
        if ($this->stagedChunkKey($video, $stream) !== null) {
            $this->reportDone($outputs);
            @unlink($localPath);

            return;
        }

        $video->heartbeat();

        Scratch::ensureDirectory(dirname($localPath));

        $startedAt = microtime(true);
        $windowDuration = max(0.0, $this->end - $this->start);

        // Generated here (not when queued) so its TTL only spans this run's encode.
        $sourceUrl = PrepareVideoJob::sourceUrl($this->mirrorPath);

        try {
            // A failed encode may leave a partial .part behind; harmless — the retry overwrites
            // it (`-y`) and only a whole output is uploaded below.
            $this->encode($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration);
        } finally {
            UsageService::record(
                $video->user_id,
                'encoding_cpu',
                round(microtime(true) - $startedAt, 2),
                $video->external_user_id ?? '',
                (int) $video->project_id,
                $video->ulid,
            );
        }

        $this->assertWindowEncoded($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration);
        $this->upload($chunkKey, $localPath);
        $this->reportDone($outputs);
    }

    /**
     * Same silent-truncation guard as the sidecar pass ({@see EncodeSidecarTracksJob}): a source
     * read that dies mid-window still exits 0, and a chunk that stops short would concat into a
     * rendition shorter than the manifest declares.
     */
    private function assertWindowEncoded(Stream $stream, string $sourceUrl, string $localPath, Video $video, $outputs, float $windowDuration): void
    {
        $short = MediaDuration::truncated($localPath, $windowDuration);

        if ($short === null) {
            return;
        }

        // A source that simply holds no picture there — a damaged rip, a few seconds with audio
        // and no video — reads short on every attempt, on every node: it failed seven episodes,
        // always the same chunk. Windows open on a keyframe, so such a hole only ever cuts the end
        // of one. Re-encode it repeating the last frame across the hole, as a player shows a copy
        // of the source; a short read over the network still fails and is retried.
        // The fill keeps the short encode's own rate and timescale, so it concatenates with its
        // neighbours ({@see ChunkTranscodeService::buildVideoArguments()}).
        $timing = MediaSource::videoTiming($localPath);

        if ($timing !== null && $this->sourceHoldsNoPicture($stream, $sourceUrl, $this->start + $short, $this->end)) {
            Log::warning('Source has no picture at the end of this chunk; filling it with the last frame', [
                'stream' => $this->streamId,
                'chunk' => $this->chunkIndex,
                'from' => round($this->start + $short, 3),
                'to' => round($this->end, 3),
            ]);

            $this->encode($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration, $timing);
            $short = MediaDuration::truncated($localPath, $windowDuration);
        }

        if ($short !== null) {
            throw new RuntimeException(
                "Chunk {$this->chunkIndex} of stream {$this->streamId} encoded {$short}s of {$windowDuration}s; source read truncated"
            );
        }
    }

    /** Whether the source has no video packet at all from where the chunk stopped to its end. */
    private function sourceHoldsNoPicture(Stream $stream, string $sourceUrl, float $from, float $to): bool
    {
        // Past the last encoded frame, which starts up to a frame before the shortfall.
        return MediaSource::packetsBetween($sourceUrl, $stream->meta['index'] ?? 'v:0', $from + 0.05, $to) === 0;
    }

    /**
     * Encode this rendition's chunk, beating the heartbeat and reporting per-chunk progress off
     * ffmpeg's `time=` output to every output the stream belongs to.
     *
     * @param  \Illuminate\Support\Collection<int,Output>  $outputs
     */
    private function encode(Stream $stream, string $sourceUrl, string $localPath, Video $video, $outputs, float $windowDuration, ?array $gapFill = null): void
    {
        $command = EncodeCommandBuilder::build(
            new Collection([$stream]),
            $sourceUrl,
            [$stream->id => $localPath],
            $this->start,
            $this->end,
            $gapFill,
        );

        Log::debug($command);

        $progress = new ChunkProgressReporter($outputs, $this->chunkIndex, $this->streamId, $windowDuration);

        $process = Process::timeout(self::ffmpegTimeout())->run(
            $command,
            function (string $_type, string $output) use ($video, $progress) {
                $this->heartbeat($video);
                $progress->handle($output);
            }
        );

        if ($process->successful()) {
            return;
        }

        if (EncodeInterruptedException::causedTermination($process->errorOutput())) {
            Log::warning('Chunk ffmpeg killed by signal; job will be retried', ['stream' => $this->streamId]);
            throw EncodeInterruptedException::fromErrorOutput($process->errorOutput());
        }

        Log::error('Chunk ffmpeg failed', [
            'stream' => $this->streamId,
            'chunk' => $this->chunkIndex,
            'error' => $process->errorOutput(),
        ]);
        throw new RuntimeException($process->errorOutput());
    }

    /**
     * Stream the encoded chunk to the shared store, retrying transient store errors in-process
     * so they never cost a re-encode. The local .part is deleted only after a confirmed upload.
     */
    private function upload(string $chunkKey, string $localPath): void
    {
        if (! file_exists($localPath)) {
            throw new RuntimeException("Encoded chunk file missing on local disk: {$chunkKey}");
        }

        retry([2000, 10000], function () use ($chunkKey, $localPath) {
            $handle = fopen($localPath, 'r');
            if ($handle === false) {
                throw new RuntimeException("Failed to open encoded chunk for upload: {$chunkKey}");
            }

            try {
                if (! Storage::disk('chunks')->writeStream($chunkKey, $handle)) {
                    throw new RuntimeException("Failed to upload chunk to store: {$chunkKey}");
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        });

        @unlink($localPath);
    }

    /** @param  \Illuminate\Support\Collection<int,Output>  $outputs */
    private function reportDone($outputs): void
    {
        foreach ($outputs as $output) {
            $output->reportChunkProgress($this->chunkIndex, $this->streamId, 100);
        }
    }

    /** The key this chunk is already staged under, under any padding this codebase has written. */
    private function stagedChunkKey(Video $video, Stream $stream): ?string
    {
        $dir = "{$video->chunksDir()}/{$stream->ulid}";

        foreach (Video::chunkFilenameCandidates($this->chunkIndex) as $filename) {
            if (Storage::disk('chunks')->exists("{$dir}/{$filename}")) {
                return "{$dir}/{$filename}";
            }
        }

        return null;
    }

    private function localPath(string $chunkKey): string
    {
        return Storage::disk('local')->path($chunkKey).'.part';
    }

    public function failed(Throwable $e): void
    {
        $stream = Stream::with('video.user')->find($this->streamId);

        if (! $stream || ! $stream->video) {
            return;
        }

        $video = $stream->video;

        // Clean up the scratch file on permanent failure so it doesn't leak.
        @unlink($this->localPath($video->chunkKey($stream, $this->chunkIndex)));

        // Its run was retried while it was still inside ffmpeg: the video it would fail is the
        // retry, healthy, and failing it would cancel that run's batches too.
        if (! in_array($video->status, Video::ACTIVE_STATUSES, true) || $this->belongsToFinishedRun()) {
            return;
        }

        $stream->update(['error_log' => Video::trimReason($e->getMessage())]);

        // Video renditions carry no name (only sidecar tracks do); label them by their short edge,
        // which is the "1080p" of a vertical 1080x1920 rendition as much as of a landscape one.
        $label = $stream->name ?? min((int) $stream->height, (int) ($stream->width ?: $stream->height)).'p';
        $video->markAsFailed("Rendition {$label} failed on chunk {$this->chunkIndex}: {$e->getMessage()}");
    }

    /**
     * Whether this job's batch is gone: {@see VideoService::retry()} deletes the
     * failed run's batch rows before the new run fans out, so a missing row is the one sign a job
     * outlived its run. A cancelled batch is not: the framework cancels the batch before calling
     * {@see failed()} on the very job whose failure cancelled it.
     */
    private function belongsToFinishedRun(): bool
    {
        return $this->batchId !== null && $this->batch() === null;
    }
}
