<?php

use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function listedVideo(string $name, string $status = 'completed', float $duration = 10, array $streams = []): Video
{
    $video = Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => $name,
        'duration' => $duration,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    foreach ($streams as $i => [$package, $file]) {
        $video->streams()->create(['path' => "{$video->ulid}/video/{$i}.mp4", 'type' => 'video', 'meta' => [], 'package_size' => $package, 'file_size' => $file]);
    }

    return $video;
}

function listedNames(string $query = ''): array
{
    return collect(test()->getJson("/api/videos?{$query}")->assertOk()->json('data'))->pluck('name')->all();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('lists newest first by default', function () {
    listedVideo('first');
    $this->travel(1)->minute();
    listedVideo('second');

    expect(listedNames())->toBe(['second', 'first']);
});

it('filters by one status, or several', function () {
    listedVideo('done', 'completed');
    listedVideo('broken', 'failed');
    listedVideo('busy', 'running');

    expect(listedNames('status=failed'))->toBe(['broken'])
        ->and(listedNames('status=completed,failed&sort=name&direction=asc'))->toBe(['broken', 'done']);
});

it('refuses a status it does not know rather than silently listing everything', function () {
    $this->getJson('/api/videos?status=finished')->assertStatus(422)->assertJsonValidationErrors(['status']);
});

it('sorts by name, duration and status in either direction', function () {
    listedVideo('bravo', 'running', 30);
    listedVideo('alpha', 'completed', 10);
    listedVideo('charlie', 'failed', 20);

    expect(listedNames('sort=name&direction=asc'))->toBe(['alpha', 'bravo', 'charlie'])
        ->and(listedNames('sort=name&direction=desc'))->toBe(['charlie', 'bravo', 'alpha'])
        ->and(listedNames('sort=duration&direction=asc'))->toBe(['alpha', 'charlie', 'bravo'])
        ->and(listedNames('sort=status&direction=asc'))->toBe(['alpha', 'charlie', 'bravo']);
});

it('sorts by size as the listing shows it: package plus file bytes over every stream', function () {
    listedVideo('mid', streams: [[500, 500]]);
    listedVideo('big', streams: [[1000, 0], [0, 2000]]);
    listedVideo('empty');

    // `size` is not a column; a video with no streams still sorts, at zero.
    expect(listedNames('sort=size&direction=desc'))->toBe(['big', 'mid', 'empty'])
        ->and(listedNames('sort=size&direction=asc'))->toBe(['empty', 'mid', 'big']);
});

it('refuses an unknown sort column', function () {
    // Anything else would reach `orderBy` as a column name.
    $this->getJson('/api/videos?sort=user_id')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    $this->getJson('/api/videos?sort=name&direction=sideways')->assertStatus(422)->assertJsonValidationErrors(['direction']);
});

it('reads an empty sort, direction or page size as the default', function () {
    // `?sort=&direction=` is how a query builder spells "not set"; it must not 500 or 422.
    listedVideo('first');
    $this->travel(1)->minute();
    listedVideo('second');

    expect(listedNames('sort=&direction=&perPage='))->toBe(['second', 'first']);
});

it('clamps the page size to the cap instead of refusing it', function () {
    listedVideo('only');

    $this->getJson('/api/videos?perPage=1000')->assertOk()->assertJsonPath('perPage', 100);
    $this->getJson('/api/videos?perPage=0')->assertOk()->assertJsonPath('perPage', 1);
    $this->getJson('/api/videos?perPage=abc')->assertStatus(422)->assertJsonValidationErrors(['perPage']);
});

it('lists only what changed since a given instant, for an integration keeping its own copy in sync', function () {
    $old = listedVideo('old');
    $old->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    $changed = listedVideo('changed');
    $changed->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $since = urlencode(now()->subDay()->toIso8601String());

    expect(listedNames("updatedSince={$since}&sort=updated_at&direction=asc"))->toBe(['changed'])
        ->and($this->getJson('/api/videos')->json('data.0.updatedAt'))->not->toBeNull();
});

it('rejects an updatedSince that is not a date', function () {
    $this->getJson('/api/videos?updatedSince=yesterday-ish')->assertStatus(422)->assertJsonValidationErrors('updatedSince');
});
