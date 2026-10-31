<?php

namespace App\Console\Commands;

use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Jobs\AddTrackJob;
use App\Models\Stream;
use App\Models\Video;
use App\Services\AddTrackService;
use App\Services\NodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovery backstop for video status: Horizon recovers the queue, but a worker killed
 * mid-flight leaves a video stuck in an active state with no job to complete or fail it.
 * Fails terminally any active video whose heartbeat has gone stale.
 */
class ReapStuckVideos extends Command
{
    protected $signature = 'videos:reap
        {--minutes= : Minutes without a heartbeat before an active video is considered stuck; defaults to one queue redelivery window}';

    protected $description = 'Fail videos whose worker died mid-processing (detected via stale heartbeat)';

    private const STALLABLE = [
        VideoStatus::RUNNING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::UPLOADING->value,
    ];

    // The queue is the first line of recovery; this floor only applies if it reports no window.
    private const MIN_STALE_MINUTES = 20;

    /** Past this, queued work is not "waiting its turn" any more — nothing is going to deliver it. */
    private const ABANDONED_MINUTES = 720;

    /**
     * Past this an added track is not processing any more, whatever its row says: its job gives up
     * after six hours of retries plus one last 30-minute run ({@see AddTrackJob::retryUntil}). What
     * gets here lost its job — a worker on an image that predates it, a flushed queue, a `failed()`
     * that itself died — and would otherwise stay locked against edits and deletes forever.
     */
    private const STUCK_TRACK_MINUTES = 420;

    public function handle(AddTrackService $tracks): int
    {
        $this->reapStuckTracks($tracks);

        $minutes = $this->staleMinutes();
        $threshold = now()->subMinutes($minutes);

        $stuck = Video::whereIn('status', self::STALLABLE)
            ->where(function ($q) use ($threshold) {
                $q->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_heartbeat_at')->where('updated_at', '<', $threshold);
                    });
            })
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck videos found.');

            return self::SUCCESS;
        }

        $failed = 0;
        $waiting = 0;

        foreach ($stuck as $video) {
            if ($this->isWaitingOnTheQueue($video)) {
                $waiting++;
                $this->line("Video {$video->id} has queued work outstanding; leaving it alone.");

                continue;
            }

            $video->markAsFailed("No worker reported progress for over {$minutes} minutes; the video was left mid-processing.");
            $failed++;
            $this->warn("Video {$video->id} failed (no heartbeat for >{$minutes} minutes).");
        }

        $this->info("Reap complete: {$failed} failed, {$waiting} still queued.");

        return self::SUCCESS;
    }

    /** Fail the added tracks whose job is gone ({@see STUCK_TRACK_MINUTES}), one upload at a time. */
    private function reapStuckTracks(AddTrackService $tracks): void
    {
        $stuck = Stream::with('video')
            ->where('status', StreamStatus::PROCESSING->value)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_TRACK_MINUTES))
            ->get()
            // Every rung of one upload fails together; the first of each is enough.
            ->unique(fn (Stream $stream) => $stream->meta['track_upload'] ?? $stream->id);

        foreach ($stuck as $stream) {
            $tracks->fail($stream, 'Processing the track stopped without finishing; upload it again.');
            $this->warn("Track {$stream->ulid} of video {$stream->video_id} failed (processing for over ".self::STUCK_TRACK_MINUTES.' minutes).');
        }
    }

    /**
     * Whether the queue still owes this video work it has simply not reached yet.
     *
     * A stale heartbeat means nothing is RUNNING for the video — it does not say why. Chunk jobs
     * only beat the heartbeat while they execute, and every CPU node drains one shared FIFO list,
     * so a video dispatched behind a long one has its jobs sitting in the queue, untouched and
     * silent, for as long as the one ahead takes. That is a healthy video waiting its turn, and
     * failing it deletes the user's source a week later ({@see PruneStaleVideos}).
     *
     * An unfinished batch with pending jobs is the difference: the queue owns the recovery there,
     * including redelivering whatever a dead worker dropped. The reaper's real job is the case the
     * queue cannot fix — no batch at all (preparation died), or every batch finished with the
     * video never transitioning (packaging died).
     *
     * The ceiling is the escape hatch: if the jobs are still pending long after any queue could
     * plausibly deliver them (a flushed Redis, a fleet that never came back), they are not coming.
     */
    private function isWaitingOnTheQueue(Video $video): bool
    {
        if ($video->last_heartbeat_at?->lt(now()->subMinutes(self::ABANDONED_MINUTES))) {
            return false;
        }

        return DB::table('job_batches')
            ->where('name', 'like', "encode video {$video->id} %")
            ->whereNull('finished_at')
            ->where('pending_jobs', '>', 0)
            ->exists();
    }

    /**
     * A worker that dies takes its job's heartbeat with it, but the queue re-delivers that job
     * after `retry_after` and the batch carries on by itself. Reaping any sooner kills videos
     * the queue would have finished, so wait out one redelivery plus the job it hands back.
     * Read off {@see NodeService}, the authority on what workers run with — this command runs on
     * the scheduler host, whose own queue env is unrelated to theirs.
     */
    private function staleMinutes(): int
    {
        if ($given = $this->option('minutes')) {
            return (int) $given;
        }

        $window = NodeService::QUEUE_RETRY_AFTER + NodeService::WORKER_TIMEOUT;

        return (int) max(self::MIN_STALE_MINUTES, ceil($window / 60));
    }
}
