<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\ExternalUserService;
use App\Services\VideoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Deletes every video of one customer that can be deleted when the job runs
 * ({@see ExternalUserService::queueVideoDeletion()}).
 *
 * Each video goes through {@see VideoService::destroy()}, the same path a single delete takes, so
 * the source object, the queued jobs and the `video.deleted` webhook are handled exactly once and
 * exactly as they always are. A video that started processing since the request is refused there
 * under its row lock and skipped here; the integration sees it in its next summary and asks again.
 */
class DeleteExternalUserVideosJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The API host's queue: this is bookkeeping on the API's own database, not media work.
    public const QUEUE = 'default';

    // Safe to repeat: the videos already deleted are gone from the lookup, so a retry only takes
    // the ones a failure left behind. Spaced out because the usual failure is S3 or the database
    // being briefly unavailable.
    public $tries = 3;

    public $backoff = [30, 120, 300];

    // Hundreds of videos, each a transaction and an S3 delete through its observer.
    public $timeout = 900;

    public function __construct(
        public int $projectId,
        public string $externalUserId,
    ) {}

    /** One deletion per customer at a time: a second request while the first runs adds nothing. */
    public function uniqueId(): string
    {
        return $this->projectId.':'.$this->externalUserId;
    }

    public function handle(VideoService $videos): void
    {
        $project = Project::find($this->projectId);

        if (! $project) {
            return;
        }

        $deleted = 0;
        $skipped = 0;

        $project->videos()
            ->where('external_user_id', $this->externalUserId)
            ->whereIn('status', VideoService::DELETABLE_STATUSES)
            ->pluck('ulid')
            ->each(function (string $ulid) use ($videos, $project, &$deleted, &$skipped) {
                try {
                    $videos->destroy($ulid, $project);
                    $deleted++;
                } catch (ValidationException) {
                    // Started processing between the lookup and its row lock.
                    $skipped++;
                } catch (ModelNotFoundException) {
                    // Deleted by someone else in the meantime: already the outcome asked for.
                }
            });

        Log::info("Deleted {$deleted} videos of external user {$this->externalUserId} in project {$this->projectId}, skipped {$skipped} in progress.");
    }
}
