<?php

namespace App\Jobs;

use App\Enums\StreamStatus;
use App\Enums\VideoStatus;
use App\Exceptions\TrackAbortedException;
use App\Models\Stream;
use App\Observers\StreamObserver;
use App\Services\AddTrackService;
use App\Services\TrackIngestService;
use App\Services\TrackPublisher;
use App\Services\UsageService;
use App\Services\WebhookDispatcher;
use App\Support\Scratch;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Adds an uploaded audio or subtitle file to a video that is already published, as one or more
 * streams of it ({@see TrackIngestService} created the placeholder row): encoded and packaged by
 * {@see AddTrackService}, grafted into the live manifests by {@see TrackPublisher}. The video stays
 * COMPLETED and playable throughout, and a failure leaves its manifests exactly as they were.
 */
class AddTrackJob implements ShouldQueue
{
    use Queueable;

    private const QUEUE = 'orchestration';

    /** Local scratch root. Not a ULID, so the sweep of video staging never mistakes a run in
     *  progress for an orphan; every run removes its own directory when it ends, and what a killed
     *  run leaves behind is swept by age ({@see PruneScratchJob}). */
    public const SCRATCH_DIR = 'add-track';

    // No $tries: every release by WithoutOverlapping counts as an attempt, and ten subtitle
    // languages added at once queue behind each other for minutes. retryUntil() bounds the wait
    // instead, and maxExceptions bounds the real failures — a second one is not transient.
    public $maxExceptions = 2;

    // A first failure is most often S3 or a worker blip: half a minute is enough for either, and
    // the second wait gives a node restart room before the last attempt.
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

    public function handle(AddTrackService $tracks, TrackPublisher $publisher): void
    {
        $placeholder = Stream::with(['video.template', 'video.templateVersion', 'video.outputs.streams'])->find($this->streamId);

        if (! $placeholder || $placeholder->status !== StreamStatus::PROCESSING) {
            return;
        }

        $video = $placeholder->video;

        if ($video->status !== VideoStatus::COMPLETED->value) {
            throw TrackAbortedException::videoChanged();
        }

        $relative = self::SCRATCH_DIR."/{$placeholder->ulid}";
        $scratch = Storage::disk('local')->path($relative);
        // A killed attempt on this node left its files here; none of them may be published.
        Storage::disk('local')->deleteDirectory($relative);
        Scratch::ensureDirectory("{$scratch}/raw");
        Scratch::ensureDirectory("{$scratch}/package");

        // Every command shares the job's budget, minus room to publish and clean up.
        $budget = $this->timeout - 120;

        try {
            // The job's own writes are announced once, as video.track_ready, not per column.
            StreamObserver::withoutAnnouncing(function () use ($tracks, $publisher, $video, $placeholder, $scratch, $budget) {
                $startedAt = microtime(true);

                try {
                    $prepared = $tracks->prepare($video, $placeholder, $scratch, $budget);
                } finally {
                    UsageService::record(
                        $video->user_id,
                        'encoding_cpu',
                        round(microtime(true) - $startedAt, 2),
                        $placeholder->meta['external_user_id'] ?? $video->external_user_id ?? '',
                        (int) $video->project_id,
                    );
                }

                $publisher->publish($video, $prepared['tracks'], "{$scratch}/package", $prepared['edits'], $budget);
            });
        } catch (TrackAbortedException $e) {
            if ($e->tracksAreGone()) {
                return;
            }

            throw $e;
        } finally {
            Storage::disk('local')->deleteDirectory($relative);
        }

        $upload = $placeholder->meta['track_upload'];

        // The upload has become the stream's own files; nothing reads it again.
        Storage::disk('s3')->delete($upload);

        WebhookDispatcher::forVideo('video.track_ready', $video->fresh(), [
            'tracks' => $video->streamsOfTrackUpload($upload)->pluck('ulid')->all(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $placeholder = Stream::with('video')->find($this->streamId);

        if ($placeholder) {
            app(AddTrackService::class)->fail($placeholder, $e->getMessage());
        }
    }
}
