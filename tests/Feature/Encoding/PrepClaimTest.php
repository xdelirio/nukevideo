<?php

use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Support\PrepClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * A claimed video's preparation is invisible while it waits in the queue: nothing beats its
 * heartbeat, so the reaper used to fail it after 41 minutes of honest waiting (two in one Redis-flap
 * hour on 11-03). The claim key says "a prep is owed to this video" for as long as one is.
 */
function claimableVideo(string $codec = 'libx264'): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => $codec, 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 60,
        'aspect_ratio' => '16:9',
        'status' => 'pending',
    ]);

    $video->streams()->create([
        'path' => "{$video->ulid}/source/original.mp4",
        'type' => 'original',
        'name' => 'Original',
        'meta' => [],
    ]);

    return $video;
}

it('issues a claim at dispatch and hands its token to the job', function () {
    Queue::fake();
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);
    $video = claimableVideo();

    $this->artisan('videos:dispatch')->assertSuccessful();

    $token = Cache::get("prep-pending:{$video->id}");
    expect($token)->not->toBeNull();
    Queue::assertPushed(PrepareVideoJob::class, fn (PrepareVideoJob $job) => $job->claim === $token);
});

it('keeps the claim across a node hop', function () {
    Queue::fake();
    Node::create(['name' => 'gpu-01', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel']);
    $video = claimableVideo('av1_qsv');
    $video->update(['status' => 'running']);
    $token = PrepClaim::issue($video->id);

    // This CPU-only worker lacks the template's hardware, so the job hands itself on.
    config(['ffmpeg.node_accel' => null]);
    $hopped = (fn () => $this->handedToCapableNode($video))->call((new PrepareVideoJob($video->id, 'o.mp4'))->withClaim($token));

    expect($hopped)->toBeTrue();
    Queue::assertPushed(PrepareVideoJob::class, fn (PrepareVideoJob $job) => $job->nodeHops === 1 && $job->claim === $token);
    expect(PrepClaim::pending($video->id))->toBeTrue();
});

it('drops the claim when the preparation hands the video back', function () {
    // No GPU node is active, so the intel video goes back to PENDING; the next claim issues anew.
    $video = claimableVideo('av1_qsv');
    $video->update(['status' => 'running']);
    $job = (new PrepareVideoJob($video->id, 'o.mp4'))->withClaim(PrepClaim::issue($video->id));

    $released = (fn () => $this->releasedForMissingCapacity($video))->call($job);

    expect($released)->toBeTrue()
        ->and(PrepClaim::pending($video->id))->toBeFalse();
});

it('drops the claim when the preparation fails for good', function () {
    $video = claimableVideo();
    $video->update(['status' => 'running']);
    $job = (new PrepareVideoJob($video->id, 'o.mp4'))->withClaim(PrepClaim::issue($video->id));

    $job->failed(new RuntimeException('preflight refused the parameters'));

    expect(PrepClaim::pending($video->id))->toBeFalse()
        ->and($video->fresh()->status)->toBe('failed');
});

it('exits without work when its claim was superseded', function () {
    // The video was failed and retried while this job sat in the queue: the retry's own prep
    // holds the key now, and two preps on one video would race on its scratch and its batches.
    $video = claimableVideo();
    $video->update(['status' => 'running']);
    $stale = PrepClaim::issue($video->id);
    PrepClaim::issue($video->id);

    Log::spy();

    // No source exists anywhere: getting past the claim check would throw "missing in S3".
    app()->call([(new PrepareVideoJob($video->id, 'o.mp4'))->withClaim($stale), 'handle']);

    Log::shouldHaveReceived('info')->with('Preparation skipped: claim superseded', ['video' => $video->id]);
});

it('runs as today when its payload carries no claim', function () {
    $video = claimableVideo();
    PrepClaim::issue($video->id);

    expect(PrepClaim::holds($video->id, null))->toBeTrue();
});

it('lets a refresh fail without failing the job', function () {
    $video = claimableVideo();
    $token = PrepClaim::issue($video->id);
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis went away'));

    $thrown = null;

    try {
        PrepClaim::refresh($video->id, $token);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

it('never forgets a claim it does not hold', function () {
    $video = claimableVideo();
    PrepClaim::issue($video->id);

    PrepClaim::forget($video->id, 'someone-else');
    PrepClaim::forget($video->id, null);

    expect(PrepClaim::pending($video->id))->toBeTrue();
});

it('ignores failed() from a job whose claim no longer holds', function () {
    // A job lost in a long outage pops after its retryUntil: the framework calls failed() without
    // handle(). If the video was reaped and retried meanwhile, the retry's prep holds the key, and
    // failing the video now would kill the healthy retry.
    $video = claimableVideo();
    $video->update(['status' => 'running']);
    $stale = PrepClaim::issue($video->id);
    PrepClaim::issue($video->id);

    (new PrepareVideoJob($video->id, 'o.mp4'))->withClaim($stale)->failed(new RuntimeException('max attempts exceeded'));

    expect($video->fresh()->status)->toBe('running');
});

it('runs as before when the cache cannot be read', function () {
    // A Redis flap at the claim check must not become a counted failure of the preparation: a key
    // nobody can read tells runs apart no better than a missing one.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis went away'));

    $thrown = null;

    try {
        $holds = PrepClaim::holds(1, 'run-1');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull()
        ->and($holds ?? null)->toBeTrue();
});
