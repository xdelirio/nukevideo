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
use App\Support\Cpu;
use App\Support\MediaDuration;
use App\Support\MediaSource;
use App\Support\Memory;
use App\Support\OomScore;
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

    /** Seconds a chunk may wait for memory: what the job's timeout leaves over ffmpeg's own. */
    private const ROOM_WAIT = 90;

    /** Long enough for the memory peak that killed it to pass, short enough not to idle the slot. */
    private const OOM_RELEASE_DELAY = 30;

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
            $encoded = $this->encode($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration);
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

        // Released after an OOM kill: the next attempt encodes the window again.
        if (! $encoded || ! $this->assertWindowEncoded($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration)) {
            return;
        }

        $this->upload($chunkKey, $localPath);
        $this->reportDone($outputs);
    }

    /**
     * Same silent-truncation guard as the sidecar pass ({@see EncodeSidecarTracksJob}): a source
     * read that dies mid-window still exits 0, and a chunk that stops short would concat into a
     * rendition shorter than the manifest declares.
     */
    /** False when the gap-fill re-encode was OOM-killed and the job released itself. */
    private function assertWindowEncoded(Stream $stream, string $sourceUrl, string $localPath, Video $video, $outputs, float $windowDuration): bool
    {
        $short = MediaDuration::truncated($localPath, $windowDuration);

        if ($short === null) {
            return true;
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

            if (! $this->encode($stream, $sourceUrl, $localPath, $video, $outputs, $windowDuration, $timing)) {
                return false;
            }

            $short = MediaDuration::truncated($localPath, $windowDuration);
        }

        if ($short !== null) {
            throw new RuntimeException(
                "Chunk {$this->chunkIndex} of stream {$this->streamId} encoded {$short}s of {$windowDuration}s; source read truncated"
            );
        }

        return true;
    }

    /**
     * Start ffmpeg only once the node has the memory this encode will take. The pools are sized
     * at boot from the hardware, which cannot know what will be queued: this is the half of the
     * sizing that looks at the node as it is right now — the chunk store on the same host, an
     * orphaned process, a pool that happens to hold nothing but full-frame 1080p.
     *
     * A common chunk asks for its own picture plus the node's reserve. A rendition above 1080p
     * asks for room for every heavy encode the node may run at once, which is what keeps two jobs
     * that read the same free memory in the same second from both taking it. The wait is short —
     * chunks finish every half minute and hand their memory back — and bounded by what the job's
     * timeout leaves over ffmpeg's own. Past it the encode starts anyway and, if the kernel does
     * kill it, is released and tried again ({@see OomScore}).
     */
    private function waitForRoom(Stream $stream, Video $video): void
    {
        $encode = Cpu::encodeMemoryGb((int) $stream->width, (int) $stream->height);

        // Never more than half the node for a common chunk: on a box too small for the budget the
        // gate would otherwise hold every chunk for its whole wait, and such a box encodes one at
        // a time anyway.
        $needed = $stream->isHeavyEncode()
            ? $encode * Cpu::heavyWorkerProcesses()
            : min($encode + Cpu::reservedGb(), Cpu::memoryGb() / 2);

        if (! Memory::waitFor($needed, self::ROOM_WAIT, fn () => $this->heartbeat($video))) {
            Log::warning('Chunk starts without the memory it wanted', [
                'stream' => $this->streamId,
                'chunk' => $this->chunkIndex,
                'wanted_gb' => round($needed, 1),
                'available_gb' => round((float) Memory::availableGb(), 1),
            ]);
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
     * False when ffmpeg was SIGKILLed — the OOM killer, given {@see OomScore} — and the job released
     * itself: memory pressure is a reason to try again, not a verdict on the window, and as an
     * exception it would spend one of the two counted failures that fail the whole video.
     *
     * @param  \Illuminate\Support\Collection<int,Output>  $outputs
     */
    private function encode(Stream $stream, string $sourceUrl, string $localPath, Video $video, $outputs, float $windowDuration, ?array $gapFill = null): bool
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

        $this->waitForRoom($stream, $video);

        $process = Process::timeout(self::ffmpegTimeout())->run(
            OomScore::wrap($command, OomScore::chunk()),
            function (string $_type, string $output) use ($video, $progress) {
                $this->heartbeat($video);
                $progress->handle($output);
            }
        );

        if ($process->successful()) {
            return true;
        }

        if (OomScore::killed($process->exitCode())) {
            Log::warning('Chunk ffmpeg killed by SIGKILL (likely OOM)', [
                'stream' => $this->streamId,
                'chunk' => $this->chunkIndex,
                'attempt' => $this->attempts(),
            ]);

            // Bounded by $tries: a window that is killed on every attempt is too big for any node
            // it lands on, and that is a failure worth reporting rather than retrying forever.
            if ($this->attempts() >= $this->tries) {
                throw new RuntimeException("Chunk OOM-killed {$this->attempts()} times");
            }

            // Returned at once: an exception after a release would still be counted.
            $this->release(self::OOM_RELEASE_DELAY);

            return false;
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
