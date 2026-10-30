<?php

namespace App\Jobs;

use App\Console\Commands\DispatchPendingVideosCommand;
use App\Enums\VideoStatus;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ChunkPlanner;
use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use App\Services\PerTitleCrfService;
use App\Services\QualityBitrateProbe;
use App\Services\RenditionPreflight;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Downloads the uploaded source once to local scratch, mirrors it to the internal store, creates
 * the video's rendition/audio/subtitle streams from a local probe, then plans keyframe-aligned
 * chunk windows and fans out one {@see ProcessChunkJob} per (window × rendition). Audio + subtitles
 * run separately via {@see EncodeSidecarTracksJob}.
 */
class PrepareVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaS5cmd;

    // tries absorbs redeliveries after a dead worker (OOM/restart/network); real errors stop at maxExceptions.
    public $tries = 5;

    public $maxExceptions = 2;

    // A transient failure here is almost always the source download or a probe hitting a busy
    // store; retrying instantly just burns an attempt against the same congestion.
    public $backoff = [60, 180];

    // Must stay under the queue's retry_after (NodeService exports REDIS_QUEUE_RETRY_AFTER=1850
    // to workers) or the job is re-delivered mid-run and two attempts race on the local scratch.
    public $timeout = 1800;

    // Light orchestration queue every worker drains (thumbnail/storyboard/sidecar); the heavy
    // chunk transcode fans out per-hardware via Stream::encodeQueue(), not this queue.
    private const QUEUE = 'orchestration';

    // Hops allowed while looking for a node with the template's hardware, before going ahead
    // without a measurement. Kept out of $tries, which exists for dead workers, not for scheduling.
    private const MAX_NODE_HOPS = 3;

    private const NODE_HOP_DELAY = 5;

    public function __construct(
        public int $videoId,
        public string $originalPath,
        public int $nodeHops = 0,
    ) {}

    /**
     * This job runs on `orchestration`, which every worker drains — GPU or not — but it encodes
     * sample windows itself ({@see resolveRenditions}), and a hardware encoder needs its hardware.
     * So hand the video to a node that has it; the fleet is known to have one
     * ({@see releasedForMissingCapacity}) and GPU nodes drain this queue too. Bounded, because running
     * unmeasured beats failing a video over scheduling.
     */
    private function handedToCapableNode(Video $video): bool
    {
        $accels = $video->encodingDefinition()?->accels() ?? [];

        if ($accels === [] || in_array(config('ffmpeg.node_accel'), $accels, true)) {
            return false;
        }

        if ($this->nodeHops >= self::MAX_NODE_HOPS) {
            Log::warning('Preparing on a node without the template hardware; renditions go unmeasured', [
                'video' => $this->videoId,
                'needs' => $accels,
            ]);

            return false;
        }

        self::dispatch($this->videoId, $this->originalPath, $this->nodeHops + 1)
            ->onQueue(self::QUEUE)
            ->delay(now()->addSeconds(self::NODE_HOP_DELAY));

        return true;
    }

    /**
     * Presigned URL of the mirrored source on the internal store (LAN, no egress). The downstream
     * thumbnail/storyboard/sidecar/chunk jobs read this instead of the costly main S3, which this
     * job reads exactly once. Generated fresh per job; TTL only outlasts a single pass.
     */
    public static function sourceUrl(string $mirrorPath): string
    {
        return Storage::disk('chunks')->temporaryUrl($mirrorPath, now()->addHours(6));
    }

    public function handle(CreateVideoStreamsService $streamsService, ChunkPlanner $planner): void
    {
        $video = Video::find($this->videoId);

        if (! $video) {
            return;
        }

        // Redelivery guard: if an encode batch already exists, fan-out ran. Names are
        // "encode video {id} {queue}"; the trailing space keeps id 12 from matching 123.
        //
        // Deliberately counts FINISHED batches too. A second fan-out re-encodes nothing (every
        // chunk job skips an uploaded chunk), but its then() would transition the video into
        // UPLOADING a second time and dispatch a second PackageVideoJob alongside the one already
        // running — they share a gather directory and a sync target. So a retry does not get here
        // with the old rows in place: {@see \App\Services\VideoService::retry()} clears them, which
        // is also what stops a hand-rolled status flip from hanging silently.
        if (DB::table('job_batches')->where('name', 'like', "encode video {$video->id} %")->exists()) {
            Log::info('Segments already planned; skipping redelivery', ['video' => $this->videoId]);

            return;
        }

        if (! Storage::disk('s3')->exists($this->originalPath)) {
            throw new RuntimeException("Original {$this->originalPath} missing in S3");
        }

        // Before anything is claimed or downloaded, so a hop costs nothing.
        if ($this->handedToCapableNode($video)) {
            return;
        }

        // Guarded transition: never revive a video the reaper (or a failure path) already moved to
        // a terminal state — that would re-run work after `video.error` was emitted. affected-rows
        // can't be the guard here: MariaDB reports CHANGED rows and the dispatcher already set
        // RUNNING, so a same-second heartbeat refresh (last_heartbeat_at is second-precision) changes
        // nothing and returns 0 without the video being terminal. Disambiguate a 0 with a status read.
        $claimed = Video::whereKey($video->id)
            ->whereIn('status', Video::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::RUNNING->value, 'last_heartbeat_at' => now()]);

        if (! $claimed && ! Video::whereKey($video->id)->whereIn('status', Video::ACTIVE_STATUSES)->exists()) {
            Log::info('Preparation skipped: video no longer active', ['video' => $this->videoId]);

            return;
        }

        $ext = pathinfo($this->originalPath, PATHINFO_EXTENSION) ?: 'mp4';
        $mirrorPath = $video->sourceMirrorPath($ext);
        // Resolved before the try so the finally always cleans the scratch file, even if the
        // download itself throws partway through.
        $localPath = Storage::disk('local')->path($mirrorPath);

        try {
            if ($this->releasedForMissingCapacity($video)) {
                return;
            }

            $this->ensureLocalSource($video, $mirrorPath, $localPath);

            // Probe + rendition/audio/subtitle streams run on the LOCAL file: mkvmerge needs it for
            // BCP-47 language tags, and ffprobe is faster off local disk than the HTTP mirror.
            // Non-original streams present means a redelivery already ran it. Duration/aspect ratio
            // are empty until this fills them.
            if (! $video->streams()->where('type', '!=', 'original')->exists()) {
                $this->heartbeat($video);
                $streamsService->handle($video, $localPath);
                $video->refresh();
            }

            // Thumbnail + storyboard run in parallel with the encode; they swallow their own
            // errors so they never block or fail the video.
            ExtractThumbnailJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
            GenerateVideoStoryboard::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);

            // Audio + subtitles encode in one pass, not chunked: per-chunk audio concat corrupts
            // gapless codecs like Opus (per-segment pre-skip).
            if ($video->streams()->whereIn('type', ['audio', 'subtitle'])->exists()) {
                EncodeSidecarTracksJob::dispatch($video->id, $mirrorPath)->onQueue(self::QUEUE);
            }

            $this->resolveRenditions($video, $localPath);

            $windows = $this->planWindows($video, $localPath, $planner);

            if (empty($windows)) {
                throw new RuntimeException("Segment planner produced no windows for video {$this->videoId}");
            }

            if ($this->fanOut($video, $windows, $mirrorPath)) {
                Log::info('Preparation planned', ['video' => $this->videoId, 'chunks' => count($windows)]);
            }
        } finally {
            // Downstream jobs read the mirror, not this scratch file; drop it either way.
            @unlink($localPath);
        }
    }

    /**
     * Download the source to the caller-resolved local scratch path (needed by mkvmerge/ffprobe) and
     * guarantee the internal mirror exists. First run: pull the original from main S3 with s5cmd,
     * then push it to the `chunks` mirror straight away so retries never hit main S3 again. On a
     * redelivery where the mirror already exists, pull from the LAN mirror instead. The caller
     * resolves `$localPath` and removes it in a finally.
     */
    private function ensureLocalSource(Video $video, string $mirrorPath, string $localPath): void
    {
        File::ensureDirectoryExists(dirname($localPath));

        $video->update(['status' => VideoStatus::DOWNLOADING->value]);

        if (Storage::disk('chunks')->exists($mirrorPath)) {
            $this->s5cmdCopy('chunks', $this->s3Uri('chunks', $mirrorPath), $localPath, $video, $this->timeout);
        } else {
            $this->s5cmdCopy('s3', $this->s3Uri('s3', $this->originalPath), $localPath, $video, $this->timeout);
            $this->s5cmdCopy('chunks', $localPath, $this->s3Uri('chunks', $mirrorPath), $video, $this->timeout);
        }

        $video->update(['status' => VideoStatus::RUNNING->value]);
    }

    /**
     * Settle every rendition against THIS source before chunks fan out. Per rendition, in order:
     * steer the CRF to the template's target VMAF, measure what the resulting quality mode costs
     * (the number the ceiling needs where the encoder honours no VBV), then prove the parameters
     * that came out of it actually encode. Either probe failing leaves the template as it stands;
     * the preflight is the only one that can fail the video, and it does so before the fan-out.
     */
    private function resolveRenditions(Video $video, string $localPath): void
    {
        $duration = (float) $video->duration;
        $tick = fn () => $this->heartbeat($video);

        foreach ($video->streams()->where('type', 'video')->get() as $stream) {
            (new PerTitleCrfService($stream))->apply($localPath, $duration, $tick);
            (new QualityBitrateProbe($stream))->measure($localPath, $duration, $tick);
            (new RenditionPreflight($stream))->assert($localPath, $duration, $tick);
        }
    }

    /**
     * Read keyframe timestamps from the local source file and group them into keyframe-aligned
     * blocks of at least the video's adaptive chunk-window length.
     *
     * @return list<array{0:float,1:float}> ordered [start, end] windows in seconds
     */
    private function planWindows(Video $video, string $localPath, ChunkPlanner $planner): array
    {
        $command = ['ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'packet=pts_time,flags', '-of', 'csv=p=0', $localPath];

        $process = Process::timeout($this->timeout)->run($command, function () use ($video) {
            $this->heartbeat($video);
        });

        if (! $process->successful()) {
            Log::error('Keyframe probe failed', ['video' => $this->videoId, 'error' => $process->errorOutput()]);
            throw new RuntimeException($process->errorOutput());
        }

        $plan = $planner->plan($video, $process->output(), (float) $video->duration);
        $context = ['video' => $this->videoId] + $plan->context();

        // Said here or not at all: when a chunk overruns the per-chunk timeout, the first question
        // is what window it was handed and why, and by then the streams it was sized from are gone.
        if ($plan->withinBudget()) {
            Log::info('Windows planned', $context);
        } else {
            // Not a failure: the chunks may still finish, and refusing the video over an estimate
            // would hand it to `videos:prune`. But nothing downstream can size these windows any
            // smaller, so a timeout here is the planner's doing and the log has to say so.
            Log::warning('Windows exceed their sizing budget', $context);
        }

        return $plan->windows;
    }

    /**
     * Second line of defense behind {@see DispatchPendingVideosCommand},
     * which refuses to claim a video whose hardware is missing: the node can still go away in the
     * window between that check and this one, and its chunks would then sit on a queue nobody
     * drains until the reaper.
     *
     * Hands the video back to PENDING rather than failing it. A missing node is nearly always a
     * reboot or a redeploy, and a terminally-failed video is deleted — with its source, the user's
     * only copy — by `videos:prune` a week later. Returned to the queue it simply waits, and the
     * dispatcher skips it until the hardware is back, so it blocks nothing meanwhile.
     */
    private function releasedForMissingCapacity(Video $video): bool
    {
        $missing = $video->encodingDefinition()?->missingCapacity();

        if (! $missing) {
            return false;
        }

        Video::whereKey($video->id)
            ->whereIn('status', Video::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::PENDING->value]);

        Log::info('Preparation deferred: no active worker for the template hardware', [
            'video' => $this->videoId,
            'missing' => $missing,
        ]);

        return true;
    }

    /**
     * One flat batch per hardware queue with one {@see ProcessChunkJob} per (window × rendition);
     * each job encodes AND uploads its own chunk, so no job depends on another job's local disk
     * and any node on that queue can pick up any of its jobs. The last batch to finish flips the
     * video to UPLOADING and fires packaging once every chunk is staged.
     *
     * @param  list<array{0:float,1:float}>  $windows
     */
    /** False when the video went terminal while this job was probing, so nothing was dispatched. */
    private function fanOut(Video $video, array $windows, string $mirrorPath): bool
    {
        $streams = $video->streams()->where('type', 'video')->get();

        if ($streams->isEmpty()) {
            throw new RuntimeException("No video rendition streams found for video {$this->videoId}");
        }

        // Re-assert the claim taken in handle(): minutes of probing separate the two, and a sidecar
        // job that failed meanwhile has already settled the video, told the consumer through
        // `video.error` and cancelled the batches. Fanning out regardless would flip every output
        // back to RUNNING and put the whole fleet to work on a video declared dead — the batch's
        // own then() gate would then refuse to finish it, leaving the outputs stuck forever.
        if (! Video::whereKey($video->id)->whereIn('status', Video::ACTIVE_STATUSES)->exists()) {
            Log::info('Fan-out skipped: video no longer active', ['video' => $this->videoId]);

            return false;
        }

        $this->discardStaleChunks($video, $streams, $windows);

        $chunkCount = count($windows);

        // Authoritative window count: the packager asserts each rendition concatenated exactly this
        // many chunks, so a prematurely-completed batch can't publish a short rendition.
        $video->update(['chunk_count' => $chunkCount]);

        // A fresh attempt starts with a clean slate: nothing else ever clears these, so a retried
        // video would keep showing the error and the progress of the run that failed.
        $video->streams()->whereNotNull('error_log')->update(['error_log' => null]);

        // Progress is tracked per Output as one field per (chunk × rendition it contains);
        // seed each output's Redis hash so its percent is meaningful from the first tick.
        foreach ($video->outputs as $output) {
            $output->update(['status' => VideoStatus::RUNNING->value]);

            $videoStreamIds = $output->streams()->where('type', 'video')->pluck('streams.id')->all();
            $output->seedChunkProgress($chunkCount, $videoStreamIds);
        }

        // One batch per hardware queue: the framework bulk-pushes batched jobs onto the BATCH's
        // queue (a job's own queue is ignored), so a mixed CPU+GPU template needs parallel batches.
        $jobsByQueue = [];

        foreach ($windows as $index => [$start, $end]) {
            foreach ($streams as $stream) {
                $jobsByQueue[$stream->encodeQueue()][] = new ProcessChunkJob($stream->id, $mirrorPath, $index, $start, $end);
            }
        }

        // Only primitives in the closure — Eloquent models are not serializable there.
        $videoId = $video->id;

        foreach ($jobsByQueue as $queue => $jobs) {
            Bus::batch($jobs)
                ->onQueue($queue)
                ->name("encode video {$video->id} {$queue}")
                ->then(function () use ($videoId) {
                    // Every batch fires this, but only the last finisher passes the gate: the
                    // framework marks a batch finished BEFORE running then(), so whoever sees
                    // no unfinished sibling knows every chunk window is encoded.
                    $stillEncoding = DB::table('job_batches')
                        ->where('name', 'like', "encode video {$videoId} %")
                        ->whereNull('finished_at')
                        ->exists();

                    if ($stillEncoding) {
                        return;
                    }

                    $video = Video::find($videoId);

                    if (! $video || ! in_array($video->status, Video::ACTIVE_STATUSES, true)) {
                        return;
                    }

                    $video->update(['status' => VideoStatus::UPLOADING->value]);
                    PackageVideoJob::dispatchIfReady($video);
                })
                ->dispatch();
        }

        return true;
    }

    /**
     * A staged chunk is reused by its index alone ({@see ProcessChunkJob}), so it is only a cache
     * hit while index N still means the same window encoded the same way. A deploy between a
     * failure and its retry can change either — the window sizing, or how the rate control
     * resolves — and the retry would then concat old chunks with new ones: content repeated or
     * skipped at the joins, audio drifting, one rendition at two VBV settings. Each rendition's
     * plan is recorded next to its chunks; a retry that plans differently starts that rendition
     * over. Chunks staged before plans were recorded adopt the current one, as they always did.
     *
     * @param  Collection<int, Stream>  $streams
     * @param  list<array{0:float,1:float}>  $windows
     */
    private function discardStaleChunks(Video $video, $streams, array $windows): void
    {
        $disk = Storage::disk('chunks');
        $bounds = array_map(fn (array $window) => array_map(fn (float $t) => sprintf('%.4f', $t), $window), $windows);

        foreach ($streams as $stream) {
            // Beside the rendition's directory, not in it: a file in there reads as an encoded chunk.
            $key = "{$video->chunksDir()}/{$stream->ulid}.plan";
            $dir = "{$video->chunksDir()}/{$stream->ulid}";

            // Thread counts are the node's, not the plan's: the node that encodes a chunk is rarely
            // the one that planned it, and that makes the same bytes.
            $arguments = preg_replace(
                '/-threads \d+ ?|[:=]?(?:lp|pools)=\d+/',
                '',
                (new ChunkTranscodeService($stream))->buildVideoArguments(windowed: true),
            );
            $plan = hash('sha256', json_encode([$bounds, $arguments]));
            $recorded = $disk->exists($key) ? trim((string) $disk->get($key)) : null;

            if ($recorded !== null && $recorded !== $plan && $disk->files($dir) !== []) {
                Log::warning('Retry planned differently; discarding staged chunks', [
                    'video' => $this->videoId,
                    'stream' => $stream->id,
                ]);
                $disk->deleteDirectory($dir);
            }

            if ($recorded !== $plan) {
                $disk->put($key, $plan);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Video::find($this->videoId)?->markAsFailed("Preparation failed: {$e->getMessage()}");
    }
}
