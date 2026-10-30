<?php

/**
 * A track added to a finished video lives through its own processing status while the video stays
 * COMPLETED ({@see AddTrackJob}). The status is per stream, it must not cost a
 * `video.updated` delivery per flip, and a track still being grafted must not be edited or deleted
 * underneath the job that is about to publish it.
 */

use App\Data\StreamData;
use App\Enums\StreamStatus;
use App\Jobs\AddTrackJob;
use App\Jobs\DispatchWebhookJob;
use App\Models\Output;
use App\Models\Project;
use App\Models\User;
use App\Services\CreateVideoStreamsService;
use FFMpeg\FFProbe\DataMapping\Stream as FFStream;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->video = projectVideo($this->project);

    $this->track = fn (array $attributes = []) => $this->video->streams()->create([
        'path' => "{$this->video->ulid}/subtitle/".Str::random(8).'.vtt',
        'type' => 'subtitle',
        'name' => 'English',
        'language' => 'en',
        'meta' => [],
        ...$attributes,
    ]);

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('exposes stream status in StreamData', function () {
    $stream = ($this->track)(['status' => StreamStatus::PROCESSING]);

    expect(StreamData::fromModel($stream->fresh())->status)->toBe(StreamStatus::PROCESSING);
});

it('refuses to edit a processing stream', function () {
    $stream = ($this->track)(['status' => StreamStatus::PROCESSING]);

    $this->putJson("/api/streams/{$stream->ulid}", ['name' => 'Renamed', 'language' => 'en', 'forced' => false])
        ->assertStatus(409);
});

it('refuses to delete a processing stream', function () {
    $stream = ($this->track)(['status' => StreamStatus::PROCESSING]);

    $this->deleteJson("/api/streams/{$stream->ulid}")->assertStatus(409);

    expect($stream->fresh())->not->toBeNull();
});

it('deletes a failed added stream without touching manifests', function () {
    $output = Output::create(['video_id' => $this->video->id, 'status' => 'completed']);
    $hls = "{$this->video->playPrefix()}/{$output->ulid}.m3u8";
    Storage::disk('s3')->put($hls, "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nv/index.m3u8");

    $stream = ($this->track)(['status' => StreamStatus::FAILED, 'error_log' => 'boom']);

    $this->deleteJson("/api/streams/{$stream->ulid}")->assertOk();

    expect($stream->fresh())->toBeNull()
        ->and(Storage::disk('s3')->get($hls))->toBe("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nv/index.m3u8");
});

it('does not announce a status-only change', function () {
    $this->project->update(['settings' => ['webhookUrl' => 'https://example.test/hook']]);
    $stream = ($this->track)(['status' => StreamStatus::PROCESSING]);

    Queue::fake();

    $stream->update(['status' => StreamStatus::READY]);
    $stream->update(['status' => StreamStatus::FAILED, 'error_log' => 'boom']);

    Queue::assertNotPushed(DispatchWebhookJob::class);
});

it('records the template index on each output', function () {
    $this->video->template->update(['query' => ['outputs' => [
        ['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720, 'video_codec' => 'libx264']]],
        ['video_codec' => 'libx264', 'variants' => [['width' => 640, 'height' => 360, 'video_codec' => 'libx264']]],
    ]]]);

    $streams = new StreamCollection([
        new FFStream(['index' => 0, 'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080]),
    ]);

    $service = new CreateVideoStreamsService;
    $video = $this->video->fresh();
    (fn () => $this->createOutputsAndStreams($video, $streams, $video->template->query['outputs']))->call($service);

    expect($video->outputs()->orderBy('id')->pluck('template_index')->all())->toBe([0, 1]);
});
