<?php

use App\Jobs\PackageVideoJob;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\NodeService;
use App\Support\PrepClaim;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function stalledVideo(int $minutesSinceHeartbeat): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => 'running',
    ]);

    $video->forceFill(['last_heartbeat_at' => now()->subMinutes($minutesSinceHeartbeat)])->save();

    return $video;
}

/** An encode batch row shaped the way `PrepareVideoJob::fanOut()` names and counts them. */
function encodeBatch(Video $video, int $pendingJobs, bool $finished = false): void
{
    DB::table('job_batches')->insert([
        'id' => (string) Str::ulid(),
        'name' => "encode video {$video->id} video-processing",
        'total_jobs' => 40,
        'pending_jobs' => $pendingJobs,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        // `markAsFailed` reconstructs the batch through Bus::findBatch to cancel it, and that
        // unserializes this column — an empty row would blow up there rather than in the reaper.
        'options' => serialize([]),
        'created_at' => now()->subHours(2)->timestamp,
        'finished_at' => $finished ? now()->subHour()->timestamp : null,
    ]);
}

describe('videos:reap', function () {
    it('leaves a video alone while the queue can still re-deliver its lost job', function () {
        // A worker killed mid-chunk stops beating the heartbeat, but the queue hands that job to
        // another worker after retry_after and the batch finishes. Reaping inside that window
        // failed videos whose chunks were all encoded and waiting on one redelivery.
        $video = stalledVideo((int) (NodeService::QUEUE_RETRY_AFTER / 60));

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('running');
    });

    it('fails a video once the redelivery window has passed with no heartbeat', function () {
        $window = (int) ceil((NodeService::QUEUE_RETRY_AFTER + NodeService::WORKER_TIMEOUT) / 60);
        $video = stalledVideo($window + 5);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('still honours an explicit threshold', function () {
        $video = stalledVideo(30);

        $this->artisan('videos:reap', ['--minutes' => 20])->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('leaves a video alone while its chunks are still queued behind another video', function () {
        // A stale heartbeat only says nothing is RUNNING for this video. Every CPU node drains one
        // shared FIFO list, so a video dispatched behind a long one sits there silent for hours —
        // healthy, just waiting its turn. Failing it deletes the user's source a day later.
        $video = stalledVideo(120);
        encodeBatch($video, pendingJobs: 40);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('running');
    });

    it('fails it anyway once the work has been pending far too long to still be coming', function () {
        $video = stalledVideo(13 * 60);
        encodeBatch($video, pendingJobs: 40);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('fails a video whose batch is finished but never transitioned', function () {
        // Nothing left to deliver: this is the case the reaper genuinely owns.
        $video = stalledVideo(120);
        encodeBatch($video, pendingJobs: 0, finished: true);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('leaves a video alone while its preparation is still owed by the queue', function () {
        // A claimed prep waiting behind other preps beats no heartbeat, and neither does one whose
        // worker died until its redelivery 31 minutes later: the claim key is what says it is coming.
        $video = stalledVideo(60);
        PrepClaim::issue($video->id);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('running');
    });

    it('fails a stale video once nothing is owed to it', function () {
        $video = stalledVideo(60);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('leaves a video alone while its packaging still holds its unique lock', function () {
        // A queued or redelivered package job beats nothing until it starts; its lock is held
        // from dispatch until it finishes or expires, and reading it must never take it.
        $video = stalledVideo(60);
        $video->update(['status' => 'uploading']);
        $lock = Cache::lock(UniqueLock::getKey(new PackageVideoJob($video->id)), 10800);
        $lock->get();

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('uploading')
            ->and($lock->isOwnedByCurrentProcess())->toBeTrue();
    });
});
