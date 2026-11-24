<?php

/**
 * Why a video failed is recorded in the activity log (`video_failed`, `properties.reason`), so the
 * log can be narrowed to one event and one video. The filters must not widen what a caller sees:
 * a video ULID is public, so naming another tenant's must find nothing, and an admin's node
 * operations must not slip past an event filter through the OR that adds them.
 */

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function loggedVideo(Project $project, string $name = 'Clip'): Video
{
    return Video::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'name' => $name,
        'duration' => 60,
        'aspect_ratio' => '16:9',
        'status' => 'failed',
    ]);
}

function logVideoEvent(Video $video, string $event, array $properties = []): void
{
    activity('video')->performedOn($video)->event($event)->withProperties($properties)->log($event);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('narrows the log to one event', function () {
    $video = loggedVideo($this->project);
    logVideoEvent($video, 'video_processing_started');
    logVideoEvent($video, 'video_failed', ['reason' => 'The process "ffmpeg" exceeded the timeout of 1679 seconds.']);

    $this->getJson('/api/activity-log?event=video_failed')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'video_failed')
        ->assertJsonPath('data.0.properties.reason', 'The process "ffmpeg" exceeded the timeout of 1679 seconds.');
});

it('narrows the log to one video', function () {
    $failed = loggedVideo($this->project, 'Failed');
    logVideoEvent($failed, 'video_failed');
    logVideoEvent(loggedVideo($this->project, 'Other'), 'video_failed');

    $this->getJson("/api/activity-log?video={$failed->ulid}")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subjectId', $failed->id);
});

it('finds nothing for another project\'s video', function () {
    $theirs = loggedVideo(Project::factory()->for(User::factory())->create());
    logVideoEvent($theirs, 'video_failed', ['reason' => 'theirs']);

    $this->getJson("/api/activity-log?video={$theirs->ulid}")->assertOk()
        ->assertJsonCount(0, 'data');
});

it('keeps an admin\'s node operations out of an event filter', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $project = Project::factory()->for($admin)->create();
    Sanctum::actingAs($admin);

    logVideoEvent(loggedVideo($project), 'video_failed');
    activity('node')->event('node_stop')->withProperties(['status' => 'succeeded'])->log('node_stop');

    $this->withHeader('X-Project-Ulid', $project->ulid)->getJson('/api/activity-log?event=video_failed')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event', 'video_failed');

    // Unfiltered, the admin still sees both.
    $this->withHeader('X-Project-Ulid', $project->ulid)->getJson('/api/activity-log')->assertOk()
        ->assertJsonCount(2, 'data');
});

it('refuses a video that is not a ULID', function () {
    $this->getJson('/api/activity-log?video=1%20OR%201=1')->assertStatus(422)
        ->assertJsonValidationErrors(['video']);
});
