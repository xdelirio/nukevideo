<?php

namespace App\Services;

use App\Console\Commands\DispatchPendingVideosCommand;
use App\Console\Commands\RetryVideos;
use App\Enums\VideoStatus;
use App\Jobs\CleanupVideoResourcesJob;
use App\Jobs\EncodeSidecarTracksJob;
use App\Jobs\PackageVideoJob;
use App\Jobs\PrepareVideoJob;
use App\Models\Project;
use App\Models\Video;
use App\Observers\VideoObserver;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class VideoService
{
    /**
     * The statuses a video may be deleted in.
     *
     * The two terminal ones, plus PENDING. What makes PENDING safe is not that the video is
     * untouched — a requeued one sits here too, with the mirror, chunks and outputs of the run
     * that failed ({@see retry()}) — but that nothing is RUNNING against it: no worker holds it
     * and no batch has jobs in flight, so there is no run to strand. What it does leave behind is
     * cleaned up the same way a failed video's is ({@see VideoObserver::deleting},
     * and PruneScratchJob for the LAN scratch).
     *
     * Everything between is mid-flight, and deleting there leaves jobs encoding against a video
     * that no longer exists; that stays refused.
     */
    public const DELETABLE_STATUSES = [
        VideoStatus::PENDING->value,
        VideoStatus::COMPLETED->value,
        VideoStatus::FAILED->value,
    ];

    public function update(string $ulid, array $data, Project $project): Video
    {
        $video = $project->videos()->where('ulid', $ulid)->firstOrFail();
        $video->update($data);

        return $video;
    }

    public function destroy(string $ulid, Project $project): void
    {
        $video = $project->videos()->where('ulid', $ulid)->firstOrFail();

        // Re-read under a row lock and re-check inside the transaction, because PENDING is the one
        // deletable status a scheduler can move out from under us: `videos:dispatch` claims
        // PENDING → RUNNING with a guarded update ({@see DispatchPendingVideosCommand}). Checking
        // the status we read a moment ago would let a tick land in that gap and hand
        // {@see PrepareVideoJob} a video whose rows and S3 prefix are being deleted. With the lock
        // the claim waits, then matches zero rows and the dispatcher skips the video, which is
        // exactly what it already does for a video it loses the race for.
        DB::transaction(function () use ($video) {
            $locked = Video::whereKey($video->id)->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            if (! in_array($locked->status, self::DELETABLE_STATUSES)) {
                throw ValidationException::withMessages(['message' => 'You cannot delete a video if it is still in progress.'])->status(400);
            }

            $locked->delete();
        });
    }

    /**
     * Why this video cannot be requeued right now, or null when it can.
     *
     * Separate from {@see retry()} so the CLI can report the reason for each video it was given
     * and carry on with the rest ({@see RetryVideos}), while the API turns the same reason into
     * a 409.
     */
    public function retryBlocker(Video $video): ?string
    {
        if ($video->status !== VideoStatus::FAILED->value) {
            return "This video is {$video->status}, not failed — there is nothing to retry.";
        }

        // A retry that cannot read the source is a 20-minute walk to the same failure. The mirror
        // is the cheap path; the upload it was mirrored from is the fallback PrepareVideoJob takes.
        if (! $this->sourceIsReachable($video)) {
            return 'The source is gone from both the internal mirror and S3: this video cannot be retried.';
        }

        // An unfinished batch means jobs are still on their way to this video; let them land
        // rather than fan out a second set alongside them.
        $live = $this->liveEncodeBatches($video);

        if ($live > 0) {
            return "{$live} encode batch(es) still have jobs out — queued, or still finishing after the failure. Wait for them.";
        }

        if ($this->sidecarPassRunning($video)) {
            return 'The audio and subtitle pass of the failed run is still encoding. Wait for it to finish.';
        }

        // Same reason as the sidecar: while the failed run's package job holds its lock, the
        // retry's own would be dropped. A lost job's lock expires ({@see PackageVideoJob::$uniqueFor}).
        if ($this->holdsUniqueLock(new PackageVideoJob($video->id))) {
            return 'The failed run\'s packaging still holds its lock. Wait for it to finish or expire.';
        }

        // A sidecar pass that fails fast settles the video FAILED while its PrepareVideoJob is
        // still probing; retried then, that Prepare fanned its batches out into the retry. Every
        // live job beats at least every few minutes, so wait until the failed run has gone quiet.
        if ($video->last_heartbeat_at?->gt(now()->subMinutes(6))) {
            return 'The failed run reported progress less than 6 minutes ago. Retry in a few minutes.';
        }

        return null;
    }

    /**
     * Puts a failed video back in line for the encoder.
     *
     * The pipeline has no other way in: a video is dispatched only out of PENDING, and everything
     * a failed run left behind — its batch rows, the error on its streams, the progress of the
     * attempt that died — has to be cleared first or the retry either hangs or lies about what it
     * is doing.
     *
     * @param  bool  $reprobe  Drop the derived streams and outputs so the source is probed from
     *                         scratch. Costlier (nothing is reused) but the only way out of a run
     *                         whose failure is baked into what the probe produced.
     */
    public function retry(Video $video, bool $reprobe = false): void
    {
        if ($blocker = $this->retryBlocker($video)) {
            throw ValidationException::withMessages(['message' => $blocker])->status(409);
        }

        // The finished batches of the run that failed. PrepareVideoJob treats any of them as
        // "fan-out already happened" and would skip planning entirely, leaving the video queued
        // behind a job that does nothing; they are spent bookkeeping either way
        // ({@see queue:prune-batches} drops them a week later).
        DB::table('job_batches')->where('name', 'like', "encode video {$video->id} %")->delete();

        // Nothing else ever clears these: the panel would show the failed run's error and its
        // progress bar frozen wherever it died, on top of a video that is encoding again.
        $video->outputs()->get()->each->clearChunkProgress();
        $video->streams()->whereNotNull('error_log')->update(['error_log' => null]);

        if ($reprobe) {
            // Outputs too: CreateVideoStreamsService creates a fresh one per template output, so
            // keeping the old ones would leave a second, streamless set attached to the video.
            // The output_stream pivot cascades on both sides. Both go through Eloquent, never a
            // mass delete on the builder: the observers are what remove the old renditions,
            // segments and manifests from S3, and a re-probe mints new ULIDs, so anything they
            // miss is never pointed at again — and the video row survives, so the prefix wipe a
            // video delete would do never comes either.
            $video->outputs()->get()->each->delete();
            $video->streams()->where('type', '!=', 'original')->get()->each->delete();

            // What the old streams staged on the mirror is keyed by ULIDs nothing will name
            // again, yet packaging syncs those directories whole: the old chunks were pulled down
            // for nothing and the old sidecars published into `download/`, unreferenced. Mirror
            // names only (see Video::SOURCE_DIR), and the mirrored source stays for the retry.
            Storage::disk('chunks')->deleteDirectory($video->chunksDir());
            Storage::disk('chunks')->deleteDirectory($video->finalDir());
        } else {
            $video->outputs()->update(['status' => VideoStatus::PENDING->value]);
        }

        $video->update([
            'status' => VideoStatus::PENDING->value,
            'last_heartbeat_at' => null,
        ]);

        activity('video')
            ->performedOn($video)
            ->causedBy($video->user)
            ->event('video_retried')
            ->withProperties(['reprobe' => $reprobe])
            ->log("Video queued for another run: {$video->name}");
    }

    /**
     * Whether the failed run's audio and subtitle pass still holds its unique lock — it does from
     * dispatch until it finishes or fails. It is in no batch, so nothing above sees it, and it ran
     * on long after the chunks failed: failing late, it cancelled the retry's batches and failed
     * the retry. And while the lock is held the retry's own pass is not even dispatched — the
     * framework drops a unique job it cannot lock — so a retry let through here would hang.
     */
    public function sidecarPassRunning(Video $video): bool
    {
        return $this->holdsUniqueLock(new EncodeSidecarTracksJob($video->id, ''));
    }

    /**
     * Whether a package job for the video still holds its unique lock: from dispatch until it
     * finishes, fails or the lock expires ({@see PackageVideoJob::$uniqueFor}). A queued or
     * redelivered package job beats no heartbeat until it starts, so this is what tells the reaper
     * the queue still owes the video its packaging.
     */
    public function packagingHoldsLock(Video $video): bool
    {
        return $this->holdsUniqueLock(new PackageVideoJob($video->id));
    }

    /**
     * Read, never taken: acquiring the lock to test it — even for a second — would make a dispatch
     * landing in that second find it held, and the framework silently drops a unique job it cannot
     * lock.
     */
    private function holdsUniqueLock(object $job): bool
    {
        return Cache::lock(UniqueLock::getKey($job))->isLocked();
    }

    /** Encode batches of this video that have not finished, i.e. jobs still on their way to it. */
    private function liveEncodeBatches(Video $video): int
    {
        // A cancelled batch reads as finished — the framework stamps finished_at along with
        // cancelled_at — but cancelling only stops the jobs that have not started: one already
        // inside ffmpeg runs on, uploads its chunk into the retry, and on failure used to fail the
        // retry itself. Jobs still out are its pending ones not yet failed. Only for as long as a
        // job can live, though: a worker killed mid-job never settles its count, and that must
        // not block the retry forever.
        // The nodes' own limit, not this host's env: the jobs run there ({@see \App\Console\Commands\ReapStuckVideos}).
        $drainedBy = now()->subSeconds(NodeService::WORKER_STOP_GRACE)->getTimestamp();

        return DB::table('job_batches')
            ->where('name', 'like', "encode video {$video->id} %")
            ->where(fn ($query) => $query->whereNull('finished_at')->orWhere(fn ($query) => $query
                ->where('cancelled_at', '>=', $drainedBy)
                ->whereColumn('pending_jobs', '>', 'failed_jobs')))
            ->count();
    }

    /**
     * Whether the source can still be read from somewhere: the LAN mirror the failed run left
     * behind, or the upload it was mirrored from.
     *
     * The mirror is asked first — it is on the LAN, a FAILED video keeps it
     * ({@see CleanupVideoResourcesJob}) and it is the copy PrepareVideoJob prefers, so
     * the common case answers without a round trip to S3.
     *
     * Answers false only when a store actually said no. A store that cannot be asked at all — the
     * disk misconfigured, the endpoint down, credentials rejected — makes `exists()` throw, and
     * that must not become "your source is gone": it is the one answer an operator cannot argue
     * with, and inventing it from a transport error would send them looking for a file that is
     * still there. So the retry is let through and fails the way it did before this check existed,
     * which is slow but honest.
     */
    private function sourceIsReachable(Video $video): bool
    {
        $original = $video->streams()->where('type', 'original')->value('path');
        $extension = pathinfo((string) $original, PATHINFO_EXTENSION) ?: 'mp4';

        try {
            if (Storage::disk('chunks')->exists($video->sourceMirrorPath($extension))) {
                return true;
            }

            return (bool) $original && Storage::disk('s3')->exists($original);
        } catch (Throwable $e) {
            Log::warning('Could not verify a retry\'s source; letting the retry through', [
                'video' => $video->ulid,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
