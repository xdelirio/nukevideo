<?php

/**
 * An added track whose job is gone — a worker on an image that predates it, a flushed queue —
 * would stay processing forever, locked against edits and deletes. The reaper fails it.
 */

use App\Enums\StreamStatus;
use App\Jobs\DispatchWebhookJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('fails tracks stuck processing past the job retry window, and only those', function () {
    Storage::fake('s3');
    Queue::fake();
    $project = Project::factory()->for(User::factory())->create(['settings' => ['webhookUrl' => 'https://example.test/hook']]);
    $video = projectVideo($project);

    $track = fn (string $age) => tap($video->streams()->create([
        'path' => 'tmp-videos/'.Str::ulid().'.srt', 'type' => 'subtitle', 'name' => Str::random(6), 'language' => 'en',
        'status' => StreamStatus::PROCESSING, 'meta' => [],
    ]), function ($stream) use ($age) {
        $stream->meta = ['track_upload' => $stream->path];
        $stream->timestamps = false;
        $stream->forceFill(['updated_at' => now()->sub($age)])->save();
    });

    $stuck = $track('8 hours');
    $fresh = $track('1 hour');

    $this->artisan('videos:reap')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and($stuck->fresh()->error_log)->toContain('stopped without finishing')
        ->and($fresh->fresh()->status)->toBe(StreamStatus::PROCESSING);

    Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => $job->payload['event'] === 'video.track_failed'
        && $job->payload['tracks'] === [$stuck->ulid]);
});
