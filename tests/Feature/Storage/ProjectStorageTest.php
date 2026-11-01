<?php

/**
 * What a project's catalogue holds on storage ({@see StorageController}), summed live off the
 * streams so it is always what the bucket holds now.
 */

use App\Http\Controllers\Api\StorageController;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $user = User::factory()->create();
    $this->project = Project::factory()->for($user)->create();
    Sanctum::actingAs($user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $stream = fn ($video, string $type, array $sizes) => $video->streams()->create(['path' => "{$video->ulid}/{$type}-".uniqid(), 'type' => $type, 'meta' => [], ...$sizes]);

    $done = projectVideo($this->project, 'done');
    $stream($done, 'original', ['file_size' => 1000]);
    $stream($done, 'video', ['package_size' => 300]);
    $stream($done, 'audio', ['package_size' => 20, 'file_size' => 5]);

    $failed = projectVideo($this->project, 'failed', 'failed');
    $stream($failed, 'original', ['file_size' => 700]);

    // Uploaded, nothing derived yet: one video, nothing stored beyond what it will have.
    projectVideo($this->project, 'pending', 'pending');

    // Another tenant's catalogue is not part of the figure.
    $elsewhere = projectVideo(Project::factory()->for($user)->create(), 'elsewhere');
    $stream($elsewhere, 'video', ['package_size' => 99999]);
});

it('adds up the project catalogue and splits it by status', function () {
    $data = $this->getJson('/api/storage')->assertOk()->json('data');

    expect($data['videos'])->toBe(3)
        ->and($data['storage'])->toBe(['totalBytes' => 2025, 'servedBytes' => 320, 'sourceBytes' => 1700])
        ->and(collect($data['byStatus'])->keyBy('status')->map(fn ($s) => [$s['videos'], $s['storage']['totalBytes']])->all())
        ->toEqual(['completed' => [1, 1325], 'failed' => [1, 700], 'pending' => [1, 0]]);
});

it('needs project context', function () {
    $this->withHeaders(['X-Project-Ulid' => ''])->getJson('/api/storage')->assertStatus(400);
});
