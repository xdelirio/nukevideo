<?php

/**
 * A project's customers — the `externalUserId` an integration uploads with — read and acted on
 * directly ({@see ExternalUserController}).
 *
 * The property that matters most is the boundary: the id is the integration's own free text and
 * unique per project only, so every case below puts the same id in a second project and checks it
 * never bleeds through.
 */

use App\Http\Controllers\Api\ExternalUserController;
use App\Jobs\DeleteExternalUserVideosJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use App\Services\ApiTokenService;
use App\Services\UsageService;
use App\Services\VideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A video of `$customer` in `$project`, with an original and a packaged rendition of the given sizes. */
function customerVideo(Project $project, ?string $customer, string $status = 'completed', int $original = 1000, int $packaged = 300): Video
{
    $video = projectVideo($project, 'clip-'.uniqid(), $status);
    $video->update(['external_user_id' => $customer]);
    $video->streams()->create(['path' => "{$video->ulid}/original.mp4", 'type' => 'original', 'meta' => [], 'file_size' => $original]);
    $video->streams()->create(['path' => "{$video->ulid}/video/720.mp4", 'type' => 'video', 'meta' => [], 'package_size' => $packaged]);

    return $video;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    // Same account, same customer id, different person.
    $this->other = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $this->videoA = customerVideo($this->project, 'cust-1');
    customerVideo($this->project, 'cust-1', 'failed', original: 500, packaged: 0);
    customerVideo($this->project, 'cust-2', original: 10, packaged: 20);
    customerVideo($this->project, null);
    customerVideo($this->other, 'cust-1', original: 99999, packaged: 99999);

    $delivery = ['date' => '2026-04-10', 'user_id' => $this->user->id, 'external_user_id' => 'cust-1', 'node_id' => 1];
    UsageService::add([
        $delivery + ['project_id' => $this->project->id, 'metric' => 'streaming_bytes', 'video_ulid' => $this->videoA->ulid, 'ip' => '1.1.1.1', 'tracking_id' => 'v1', 'value' => 100, 'requests' => 4],
        $delivery + ['project_id' => $this->project->id, 'metric' => 'download_bytes', 'video_ulid' => $this->videoA->ulid, 'ip' => '1.1.1.1', 'tracking_id' => 'v1', 'value' => 50, 'requests' => 1],
        $delivery + ['project_id' => $this->project->id, 'metric' => 'streaming_bytes', 'video_ulid' => $this->videoA->ulid, 'ip' => '2.2.2.2', 'tracking_id' => '', 'value' => 10, 'requests' => 2],
        array_merge($delivery, ['date' => '2026-04-11', 'project_id' => $this->project->id, 'metric' => 'upload_bytes', 'value' => 1000]),
        array_merge($delivery, ['date' => '2026-04-11', 'project_id' => $this->project->id, 'metric' => 'encoding_cpu', 'value' => 42.5, 'requests' => 3]),
        $delivery + ['project_id' => $this->other->id, 'metric' => 'streaming_bytes', 'ip' => '9.9.9.9', 'value' => 77777],
    ]);
});

it('summarises a customer: catalogue by status, what it stores, and what it consumed', function () {
    $this->getJson('/api/external-users/cust-1?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertJsonPath('data.externalUserId', 'cust-1')
        ->assertJsonPath('data.videos', ['total' => 2, 'byStatus' => ['completed' => 1, 'failed' => 1]])
        ->assertJsonPath('data.storage', ['totalBytes' => 1800, 'servedBytes' => 300, 'sourceBytes' => 1500])
        ->assertJsonPath('data.usage.deliveredBytes', 160)
        // 1.1.1.1 streamed and downloaded: one viewer. The unattributed fetch is no tracking id.
        ->assertJsonPath('data.usage.uniqueIps', 2)
        ->assertJsonPath('data.usage.uniqueTrackingIds', 1);

    $metrics = collect($this->getJson('/api/external-users/cust-1?from=2026-04-01&to=2026-04-30')->json('data.usage.metrics'))->keyBy('metric');

    expect($metrics['streaming_bytes'])->toMatchArray(['unit' => 'bytes', 'value' => 110, 'requests' => 6])
        ->and($metrics['encoding_cpu'])->toMatchArray(['unit' => 'seconds', 'value' => 42.5, 'requests' => 3])
        ->and($metrics['upload_bytes']['value'])->toEqual(1000);
});

it('answers an id nothing carries with zeros, not a 404', function () {
    // A customer is not a record NukeVideo keeps; an id with nothing behind it simply has nothing.
    $this->getJson('/api/external-users/nobody?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertJsonPath('data.videos.total', 0)
        ->assertJsonPath('data.usage.metrics', []);
});

it('takes an id with slashes in the path', function () {
    customerVideo($this->project, 'tenant/7/user');

    $this->getJson('/api/external-users/tenant/7/user?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertJsonPath('data.externalUserId', 'tenant/7/user')
        ->assertJsonPath('data.videos.total', 1);
});

it('lists the project customers with their catalogue size, and nobody else', function () {
    $response = $this->getJson('/api/external-users?sort=external_user_id&direction=asc')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->toMatchArray(['externalUserId' => 'cust-1', 'videos' => 2, 'storageBytes' => 1800])
        ->and($response->json('data.1'))->toMatchArray(['externalUserId' => 'cust-2', 'videos' => 1, 'storageBytes' => 30])
        ->and($response->json('total'))->toBe(2);
});

it('searches customers by prefix, taking wildcards literally', function () {
    customerVideo($this->project, 'cust_%x');

    expect(collect($this->getJson('/api/external-users?search=cust-')->json('data'))->pluck('externalUserId')->sort()->values()->all())
        ->toBe(['cust-1', 'cust-2'])
        ->and(collect($this->getJson('/api/external-users?search='.urlencode('cust_%'))->json('data'))->pluck('externalUserId')->all())
        ->toBe(['cust_%x']);
});

it('reports usage for many customers at once, by metric, with units', function () {
    $rows = collect($this->postJson('/api/external-users/usage', [
        'from' => '2026-04-01', 'to' => '2026-04-30', 'externalUserIds' => ['cust-1', 'cust-2', 'cust-1'],
    ])->assertOk()->json('data'));

    // cust-2 consumed nothing in the range, so it has no row.
    expect($rows->pluck('externalUserId')->unique()->all())->toBe(['cust-1'])
        ->and($rows->firstWhere('metric', 'download_bytes'))->toMatchArray(['unit' => 'bytes', 'value' => 50, 'requests' => 1, 'date' => null])
        ->and($rows->firstWhere('metric', 'encoding_cpu')['unit'])->toBe('seconds')
        // The other project's traffic for the same id is not here.
        ->and($rows->where('metric', 'streaming_bytes')->sum('value'))->toEqual(110);
});

it('splits the batch by day, and narrows it to one metric', function () {
    $rows = $this->postJson('/api/external-users/usage', [
        'from' => '2026-04-01', 'to' => '2026-04-30', 'externalUserIds' => ['cust-1'],
        'granularity' => 'daily', 'metric' => 'upload_bytes',
    ])->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['metric' => 'upload_bytes', 'date' => '2026-04-11']);
});

it('refuses a batch it should not answer', function (array $payload, string $field) {
    $this->postJson('/api/external-users/usage', ['from' => '2026-04-01', 'to' => '2026-04-30', ...$payload])
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'no ids' => [['externalUserIds' => []], 'externalUserIds'],
    'past the batch cap' => [['externalUserIds' => array_map(fn ($i) => "c{$i}", range(1, 1001))], 'externalUserIds'],
    // Carries no customer, so it could only ever answer empty.
    'the operator origin metric' => [['externalUserIds' => ['cust-1'], 'metric' => 'origin_bytes'], 'metric'],
]);

it('queues the deletion of a customer catalogue and says what is left in progress', function () {
    Queue::fake();
    customerVideo($this->project, 'cust-1', 'running');

    $this->deleteJson('/api/external-users/cust-1/videos', ['confirm' => 'cust-1'])
        ->assertStatus(202)
        ->assertJsonPath('data', ['deleting' => 2, 'inProgress' => 1]);

    Queue::assertPushedOn(DeleteExternalUserVideosJob::QUEUE, DeleteExternalUserVideosJob::class, fn ($job) => $job->projectId === $this->project->id && $job->externalUserId === 'cust-1');
});

it('refuses to delete unless the body repeats the id', function (array $body) {
    Queue::fake();

    $this->deleteJson('/api/external-users/cust-1/videos', $body)->assertStatus(422)->assertJsonValidationErrors('confirm');

    Queue::assertNothingPushed();
})->with([
    'no confirmation' => [[]],
    'another id' => [['confirm' => 'cust-2']],
]);

it('deletes only that customer deletable videos, in that project', function () {
    Queue::fake();
    Storage::fake('s3');
    $running = customerVideo($this->project, 'cust-1', 'running');

    (new DeleteExternalUserVideosJob($this->project->id, 'cust-1'))->handle(app(VideoService::class));

    expect($this->project->videos()->where('external_user_id', 'cust-1')->pluck('id')->all())->toBe([$running->id])
        ->and($this->project->videos()->where('external_user_id', 'cust-2')->count())->toBe(1)
        ->and($this->other->videos()->where('external_user_id', 'cust-1')->count())->toBe(1);
});

it('needs project context, and works with a project key', function () {
    $this->withHeaders(['X-Project-Ulid' => ''])->getJson('/api/external-users')->assertStatus(400);

    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;
    $this->app['auth']->forgetGuards();

    $this->withHeaders(['X-Project-Ulid' => ''])->withToken($key)->getJson('/api/external-users')
        ->assertOk()
        ->assertJsonPath('total', 2);
});
