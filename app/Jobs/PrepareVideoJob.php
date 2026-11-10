<?php

namespace App\Jobs;

use App\Console\Commands\DispatchPendingVideosCommand;
use App\Enums\VideoStatus;
use App\Exceptions\StoreTransferException;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Stream;
use App\Models\Video;
use App\Services\ChunkPlanner;
use App\Services\ChunkTranscodeService;
use App\Services\CreateVideoStreamsService;
use App\Services\PerTitleCrfService;
use App\Services\QualityBitrateProbe;
use App\Services\RenditionPreflight;
use App\Support\PrepClaim;
use App\Support\PrimaryStore;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SyncsViaS5cmd {
        SyncsViaS5cmd::heartbeat as private beatVideo;
    }

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

    /**
     * Seconds of the timeout kept for what follows the probes: the preflights, the keyframe scan
     * and the fan-out, which pushes a long film's ~1,200 chunk jobs at 0.16-0.36 s each over the
     * link to Redis (three preparations were killed in exactly that step).
     */
    private const AFTER_PROBES_RESERVE = 420;

    /** When this attempt began; never serialised with a value, a redelivery starts its own clock. */
    private float $startedAt = 0.0;

    private const NODE_HOP_DELAY = 5;

    /** How often the claim's TTL is pushed out while the job works; well inside its six hours. */
    private const CLAIM_REFRESH_SECONDS = 60;

    /**
     * The dispatcher's claim on the video ({@see PrepClaim}). Outside the constructor and null by
     * default, so payloads pushed before claims existed still deserialize, and run as they did.
     */
    public ?string $claim = null;

    private ?float $lastClaimRefreshAt = null;

    public function __construct(
        public int $videoId,
        public string $originalPath,
        public int $nodeHops = 0,
    ) {}

    public function withClaim(?string $token): static
    {
        $this->claim = $token;

        return $this;
    }

    /**
     * The video's heartbeat, plus the claim's TTL: a prep that is working is a prep still owed, and
     * the reaper reads the claim, not the heartbeat, for a prep that has not started yet.
     */
    private function heartbeat(Video $video): void
    {
        $this->beatVideo($video);

        $now = microtime(true);

        if ($this->lastClaimRefreshAt === null || $now - $this->lastClaimRefreshAt >= self::CLAIM_REFRESH_SECONDS) {
            $this->lastClaimRefreshAt = $now;
            PrepClaim::refresh($this->videoId, $this->claim);
        }
    }

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

        // The claim travels with it: the video is still owed this same prep, on another node.
        dispatch((new self($this->videoId, $this->originalPath, $this->nodeHops + 1))->withClaim($this->claim))
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
        $this->startedAt = microtime(true);
        $claimWait = $this->claimWaitSeconds();

        // A job from a run that was failed and retried while it sat in the queue: the retry's own
        // prep owns the video now, and two preps would race on its scratch and its batches.
        if (! PrepClaim::holds($this->videoId, $this->claim)) {
            Log::info('Preparation skipped: claim superseded', ['video' => $this->videoId]);

            return;
        }

        $video = Video::find($this->videoId);

        if (! $video) {
            PrepClaim::forget($this->videoId, $this->claim);

            return;
        }

        // Redelivery guard: skip once every hardware queue holds a batch with jobs
        // ({@see fanOutComplete}).
        //
        // Deliberately counts FINISHED batches too. A second fan-out re-encodes nothing (every
        // chunk job skips an uploaded chunk), but its then() would transition the video into
        // UPLOADING a second time and dispatch a second PackageVideoJob alongside the one already
        // running — they share a gather directory and a sync target. So a retry does not get here
        // with the old rows in place: {@see \App\Services\VideoService::retry()} clears them, which
        // is also what stops a hand-rolled status flip from hanging silently.
        if ($this->fanOutComplete($video)) {
            Log::info('Segments already planned; skipping redelivery', ['video' => $this->videoId]);
            PrepClaim::forget($this->videoId, $this->claim);

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
            PrepClaim::forget($this->videoId, $this->claim);

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

            try {
                $this->ensureLocalSource($video, $mirrorPath, $localPath);
            } catch (StoreTransferException $e) {
                // The provider cutting a download short is not the video's fault: wait it out
                // rather than spend one of the two exceptions that fail it.
                if ($e->disk !== 's3' || ! $this->job || $this->attempts() >= $this->tries) {
                    throw $e;
                }

                PrimaryStore::markUnavailable($e->getMessage());

                Log::warning('Primary store unavailable; preparation will run again', [
                    'video' => $this->videoId,
                    'attempt' => $this->attempts(),
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);

                $this->release(300 * $this->attempts());

                return;
            }

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

            $timings = [];

            $stepStartedAt = microtime(true);
            $this->resolveRenditions($video, $localPath);
            $timings['probe_s'] = round(microtime(true) - $stepStartedAt, 1);

            $stepStartedAt = microtime(true);
            $windows = $this->planWindows($video, $localPath, $planner);
            $timings['plan_s'] = round(microtime(true) - $stepStartedAt, 1);

            if (empty($windows)) {
                throw new RuntimeException("Segment planner produced no windows for video {$this->videoId}");
            }

            $stepStartedAt = microtime(true);
            $fannedOut = $this->fanOut($video, $windows, $mirrorPath);
            $timings['fanout_s'] = round(microtime(true) - $stepStartedAt, 1);

            if ($fannedOut) {
                $renditions = $video->streams()->where('type', 'video')->count();
                $this->logPlanned($video, count($windows), count($windows) * $renditions, $timings, $claimWait);
            }

            // Settled either way: fanned out, or the video went terminal meanwhile. Only here and
            // on the other clean exits — a crash or a timeout keeps the claim, because the queue
            // still owes this video a prep: its redelivery.
            PrepClaim::forget($this->videoId, $this->claim);
        } finally {
            // Downstream jobs read the mirror, not this scratch file; drop it either way.
            @unlink($localPath);
        }
    }

    /**
     * Where a preparation's time went. A prep is as long as the encode it feeds, so each part —
     * waiting for a worker, probing, planning, pushing the jobs — is what tells a slow link from a
     * busy node from a crowded queue.
     *
     * @param  array{probe_s: float, plan_s: float, fanout_s: float}  $timings
     */
    private function logPlanned(Video $video, int $windows, int $jobs, array $timings, ?int $claimWait): void
    {
        Log::info('Preparation planned', [
            'video' => $video->id,
            'chunks' => $windows,
            'jobs' => $jobs,
            'attempt' => $this->attempts(),
            'claim_wait_s' => $claimWait,
            ...$timings,
        ]);
    }

    /**
     * Seconds between the push and now, read as the attempt starts: how long the job waited for a
     * worker. The payload's `createdAt` survives a release, so on a later attempt this spans every
     * attempt before it; `attempt` says which one it is.
     */
    private function claimWaitSeconds(): ?int
    {
        $createdAt = $this->job?->payload()['createdAt'] ?? null;

        return $createdAt === null ? null : max(0, now()->getTimestamp() - (int) $createdAt);
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

        // exists() still decides: a mirror that cannot be read right now must fail the attempt as
        // it always did, not pass for a missing one and send the copy back to primary S3. The size
        // is only for the fetch log, which needs it to turn seconds into a link rate — video 17621
        // spent two thirds of its 1800s budget on this copy, and nothing recorded it.
        $from = Storage::disk('chunks')->exists($mirrorPath) ? 'mirror' : 's3';
        $bytes = $from === 'mirror' ? $this->objectSize('chunks', $mirrorPath) : $this->objectSize('s3', $this->originalPath);
        $startedAt = microtime(true);

        Log::info('Source fetch started', ['video' => $this->videoId, 'from' => $from, 'bytes' => $bytes]);

        if ($from === 'mirror') {
            $this->s5cmdCopy('chunks', $this->s3Uri('chunks', $mirrorPath), $localPath, $video, $this->timeout);
        } else {
            $this->s5cmdCopy('s3', $this->s3Uri('s3', $this->originalPath), $localPath, $video, $this->timeout);
            $this->s5cmdCopy('chunks', $localPath, $this->s3Uri('chunks', $mirrorPath), $video, $this->timeout);
        }

        Log::info('Source fetched', [
            'video' => $this->videoId,
            'from' => $from,
            'bytes' => $bytes,
            's' => round(microtime(true) - $startedAt, 1),
            'attempt' => $this->attempts(),
        ]);

        $video->update(['status' => VideoStatus::RUNNING->value]);
    }

    /** The object's size for a log line, or null when the store cannot say. Never decides anything. */
    private function objectSize(string $disk, string $key): ?int
    {
        try {
            return Storage::disk($disk)->size($key);
        } catch (Throwable) {
            return null;
        }
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

        $streams = $video->streams()->where('type', 'video')->get();

        foreach ($streams->values() as $index => $stream) {
            (new PerTitleCrfService($stream))->apply($localPath, $duration, $tick, $this->probeBudget($streams->count() - $index));
            (new QualityBitrateProbe($stream))->measure($localPath, $duration, $tick);
            (new RenditionPreflight($stream))->assert($localPath, $duration, $tick);
        }
    }

    /**
     * Seconds the next rendition's probe may take and still measure another anchor: an even share
     * of what this attempt has left, once the steps that follow the probes are set aside. Derived
     * from the clock rather than fixed, so an idle node and a short download buy a better answer
     * and a slow one still leaves the job time to plan and fan out.
     */
    private function probeBudget(int $renditionsLeft): float
    {
        $left = $this->timeout - (microtime(true) - $this->startedAt) - self::AFTER_PROBES_RESERVE;

        return $left / max(1, $renditionsLeft);
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

        // Back in the queue, nothing is owed to it until the dispatcher claims it again.
        PrepClaim::forget($this->videoId, $this->claim);

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
    /**
     * Whether an earlier attempt finished the fan-out: every hardware queue the renditions route to
     * holds a batch with jobs. A row alone proves nothing — `PendingBatch::dispatch()` commits it
     * with total_jobs 0 before a single job is pushed, so a timeout in between (video 17621) leaves
     * a row for a fan-out that never happened. Names are "encode video {id} {queue}".
     */
    private function fanOutComplete(Video $video): bool
    {
        $queues = $this->expectedQueues($video);

        return $queues !== [] && array_diff($queues, $this->queuesWithJobs($video)) === [];
    }

    /** @return list<string> the hardware queues this video's renditions encode on */
    private function expectedQueues(Video $video): array
    {
        return $video->streams()->where('type', 'video')->get()
            ->map(fn (Stream $stream) => $stream->encodeQueue())
            ->unique()->values()->all();
    }

    /** @return list<string> the queues that already hold a batch with jobs for this video */
    private function queuesWithJobs(Video $video): array
    {
        $prefix = "encode video {$video->id} ";

        return DB::table('job_batches')
            ->where('name', 'like', "{$prefix}%")
            ->where('total_jobs', '>', 0)
            ->pluck('name')
            ->map(fn (string $name) => substr($name, strlen($prefix)))
            ->unique()->values()->all();
    }

    /**
     * Remove this video's batch rows that hold no job: what a fan-out leaves when it dies between
     * the framework committing the row and pushing the jobs. Attempts never overlap (retry_after
     * outlasts the timeout), so an empty row here is always a dead attempt's, never a live one's.
     */
    private function dropEmptyBatches(Video $video): void
    {
        $prefix = "encode video {$video->id} ";
        $empty = DB::table('job_batches')->where('name', 'like', "{$prefix}%")->where('total_jobs', 0)->get(['id', 'name']);

        foreach ($empty as $row) {
            Log::warning('Empty encode batch removed', ['video' => $video->id, 'queue' => substr($row->name, strlen($prefix))]);
        }

        DB::table('job_batches')->whereIn('id', $empty->pluck('id'))->delete();
    }

    /** False when the video went terminal while this job was probing, so nothing was dispatched. */
    private function fanOut(Video $video, array $windows, string $mirrorPath): bool
    {
        $allStreams = $video->streams()->where('type', 'video')->get();

        if ($allStreams->isEmpty()) {
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

        // A row the framework committed before the attempt that wrote it died (video 17621): it
        // holds no job and never will, and left in place it would read as a queue already handled.
        // Gone, a late job of that attempt reads its run as over and skips ({@see ProcessChunkJob}).
        $this->dropEmptyBatches($video);

        // Only the queues still missing a batch: a queue that already has one is encoding (or done)
        // from the previous attempt, and a second batch there would package the video twice.
        $done = $this->queuesWithJobs($video);
        $expectedQueues = $allStreams->map(fn (Stream $stream) => $stream->encodeQueue())->unique()->values()->all();
        $streams = $allStreams->reject(fn (Stream $stream) => in_array($stream->encodeQueue(), $done, true))->values();

        if ($streams->isEmpty()) {
            return true;
        }

        $this->discardStaleChunks($video, $streams, $windows);

        $chunkCount = count($windows);

        // Authoritative window count: the packager asserts each rendition concatenated exactly this
        // many chunks, so a prematurely-completed batch can't publish a short rendition. Left as the
        // first attempt wrote it when a sibling queue is already encoding against it.
        if ($done === []) {
            $video->update(['chunk_count' => $chunkCount]);
        }

        // A fresh attempt starts with a clean slate: nothing else ever clears these, so a retried
        // video would keep showing the error and the progress of the run that failed.
        $video->streams()->whereIn('id', $streams->pluck('id'))->whereNotNull('error_log')->update(['error_log' => null]);

        // Progress is tracked per Output as one field per (chunk × rendition it contains);
        // seed each output's Redis hash so its percent is meaningful from the first tick. HMSET
        // writes only these fields, so a sibling queue's progress survives a partial fan-out.
        foreach ($video->outputs as $output) {
            $videoStreamIds = $output->streams()->where('type', 'video')->pluck('streams.id')
                ->intersect($streams->pluck('id'))->values()->all();

            if ($videoStreamIds === []) {
                continue;
            }

            $output->update(['status' => VideoStatus::RUNNING->value]);
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
                ->then(function () use ($videoId, $expectedQueues) {
                    // Every batch fires this, but only the last finisher passes the gate: the
                    // framework marks a batch finished BEFORE running then(), so whoever sees
                    // no unfinished sibling knows every chunk window is encoded.
                    //
                    // Self-contained on purpose (DB queries and primitives only): during a rolling
                    // deploy an older worker may be the one that runs it.
                    $rows = DB::table('job_batches')
                        ->where('name', 'like', "encode video {$videoId} %")
                        ->get(['name', 'total_jobs', 'finished_at', 'cancelled_at']);

                    if ($rows->contains(fn ($row) => $row->finished_at === null)) {
                        return;
                    }

                    // No unfinished row is not enough: a queue whose batch was never written (a
                    // prep killed between two queues' fan-outs) or was cancelled has encoded
                    // nothing, and packaging now would publish a video missing that rendition.
                    foreach ($expectedQueues as $queue) {
                        $encoded = $rows->contains(fn ($row) => $row->name === "encode video {$videoId} {$queue}"
                            && $row->total_jobs > 0
                            && $row->cancelled_at === null);

                        if (! $encoded) {
                            return;
                        }
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
        // A job of a superseded run: out of attempts, it is failed at its pop without handle() ever
        // checking the claim, and the video it would fail is a retry another prep now owns.
        if (! PrepClaim::holds($this->videoId, $this->claim)) {
            Log::info('Preparation failure ignored: claim superseded', ['video' => $this->videoId, 'error' => $e->getMessage()]);

            return;
        }

        PrepClaim::forget($this->videoId, $this->claim);

        Video::find($this->videoId)?->markAsFailed("Preparation failed: {$e->getMessage()}");
    }
}
