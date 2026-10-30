<?php

/**
 * Uploading an audio or subtitle file for a video that already completed. It rides the same S3
 * endpoints as a video upload, told apart by `metadata.video`, and lands under its own prefix so
 * nothing on the way — the bucket webhook, the lost-webhook replay — can mistake it for a new video.
 * Everything that can be refused is refused before a single byte is uploaded.
 */

use App\DTOs\TrackUploadMeta;
use App\Enums\StreamStatus;
use App\Jobs\AddTrackJob;
use App\Jobs\OnVideoUploaded;
use App\Jobs\PruneScratchJob;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\ApiTokenService;
use App\Services\TrackIngestService;
use App\Services\UppyS3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->video = projectVideo($this->project);

    // An output that can take AAC audio: the template's entry has an audio block.
    $this->video->template->update(['query' => ['outputs' => [[
        'variants' => [['width' => 1280, 'height' => 720]],
        'audio' => ['audio_codec' => 'aac', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k']]],
    ]]]]);
    $output = Output::create(['video_id' => $this->video->id, 'template_index' => 0, 'status' => 'completed']);
    $output->recordFormats(['hls', 'dash']);

    $this->params = fn (array $metadata = [], string $filename = 'dub.wav') => '/api/s3/params?'.http_build_query([
        'filename' => $filename,
        'metadata' => [
            'project' => $this->project->ulid,
            'video' => $this->video->ulid,
            'trackType' => 'audio',
            'trackName' => 'English dub',
            'language' => 'en',
            ...$metadata,
        ],
    ]);

    Sanctum::actingAs($this->user);
});

it('stores a TrackUploadMeta under the shared upload folder', function () {
    $key = $this->getJson(($this->params)())->assertOk()->json('key');

    // Same folder as a video: what the upload IS lives in its metadata, so a bucket notification
    // filtered on that folder still fires for tracks.
    expect($key)->toStartWith('tmp-videos/')
        ->and(app(UppyS3Service::class)->getUploadMeta($key))->toBeInstanceOf(TrackUploadMeta::class)
        ->and(app(UppyS3Service::class)->getUploadMeta($key)->video)->toBe($this->video->ulid);
});

it('rejects a track upload for a video that is not completed', function () {
    $this->video->update(['status' => 'running']);

    $this->getJson(($this->params)())->assertStatus(422)->assertJsonValidationErrors('metadata.video');
});

it('rejects a track upload for another project video', function () {
    $other = projectVideo(Project::factory()->for($this->user)->create());

    $this->getJson(($this->params)(['video' => $other->ulid]))->assertNotFound();
});

it('rejects another project video under a project key', function () {
    $other = projectVideo(Project::factory()->for($this->user)->create());
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($key)->getJson(($this->params)(['video' => $other->ulid]))->assertNotFound();
    $this->withToken($key)->getJson(($this->params)())->assertOk();
});

it('rejects a name that could break the manifest', function () {
    $this->getJson(($this->params)(['trackName' => 'a"b']))->assertStatus(422)->assertJsonValidationErrors('metadata.trackName');
    $this->getJson(($this->params)(['language' => 'not a language']))->assertStatus(422)->assertJsonValidationErrors('metadata.language');
});

it('rejects a name already used by a track of the same type', function () {
    $this->video->streams()->create(['path' => 'x/audio/a.mp4', 'type' => 'audio', 'name' => 'english DUB', 'meta' => []]);

    $this->getJson(($this->params)())->assertStatus(422)->assertJsonValidationErrors('metadata.trackName');

    // A forced subtitle may share a full subtitle's name.
    $this->video->streams()->create(['path' => 'x/subtitle/s.vtt', 'type' => 'subtitle', 'name' => 'English', 'forced' => false, 'meta' => []]);
    $this->getJson(($this->params)(['trackType' => 'subtitle', 'trackName' => 'English', 'forced' => true], 'en.srt'))->assertOk();
});

it('rejects an extension outside the allowlist', function () {
    $this->getJson(($this->params)([], 'dub.exe'))->assertStatus(422);
    $this->getJson(($this->params)(['trackType' => 'subtitle'], 'subs.wav'))->assertStatus(422);
    $this->getJson(($this->params)(['trackType' => 'subtitle'], 'subs.ass'))->assertOk();
});

it('rejects audio when no output can take it', function () {
    $this->video->template->update(['query' => ['outputs' => [['variants' => [['width' => 1280, 'height' => 720]]]]]]);

    $this->getJson(($this->params)())->assertStatus(422)->assertJsonValidationErrors('metadata.trackType');
});

it('ingests a track upload as a track through the shared webhook', function () {
    Queue::fake([AddTrackJob::class]);
    config(['nuke.webhook.secret' => 'shh']);
    $key = 'tmp-videos/'.Str::ulid().'.wav';
    cacheTrackMeta($this, $key);

    $this->withToken('shh')->postJson('/api/webhooks/video-uploaded', [
        'EventName' => 's3:ObjectCreated:CompleteMultipartUpload',
        'Records' => [['s3' => ['object' => ['key' => $key, 'size' => 10]]]],
    ])->assertOk();

    // A placeholder track on the existing video, never a new video holding nothing but a dub.
    expect(Video::count())->toBe(1)
        ->and(Stream::where('path', $key)->first()?->status)->toBe(StreamStatus::PROCESSING);
    Queue::assertPushed(AddTrackJob::class);
});

/** Cache the meta a track upload would have stored, as the endpoint does. */
function cacheTrackMeta($test, string $key, array $overrides = []): void
{
    app(UppyS3Service::class)->storeUploadMeta($key, new TrackUploadMeta(...[
        'user' => $test->user->ulid,
        'project' => $test->project->ulid,
        'video' => $test->video->ulid,
        'type' => 'audio',
        'name' => 'English dub',
        'language' => 'en',
        'forced' => false,
        'hearingImpaired' => false,
        'visualImpaired' => true,
        'filename' => 'dub.wav',
        ...$overrides,
    ]));
}

it('creates a processing placeholder and dispatches AddTrackJob', function () {
    Queue::fake();
    $key = 'tmp-videos/'.Str::ulid().'.wav';
    cacheTrackMeta($this, $key);

    app(TrackIngestService::class)->handle($key, 1234);

    $stream = Stream::where('path', $key)->firstOrFail();

    expect($stream->status)->toBe(StreamStatus::PROCESSING)
        ->and($stream->type)->toBe('audio')
        ->and($stream->name)->toBe('English dub')
        ->and($stream->language)->toBe('en')
        ->and($stream->meta)->toMatchArray(['track_upload' => $key, 'visual_impaired' => true])
        ->and(app(UppyS3Service::class)->getUploadMeta($key))->toBeNull();

    Queue::assertPushed(AddTrackJob::class, fn ($job) => $job->streamId === $stream->id && $job->videoId === $this->video->id);

    // A redelivered webhook changes nothing.
    app(TrackIngestService::class)->handle($key, 1234);
    expect(Stream::where('path', $key)->count())->toBe(1);
});

it('creates a failed row when the video stopped being completed before ingest', function () {
    Queue::fake();
    $key = 'tmp-videos/'.Str::ulid().'.wav';
    cacheTrackMeta($this, $key);
    $this->video->update(['status' => 'running']);

    app(TrackIngestService::class)->handle($key, 1234);

    expect(Stream::where('path', $key)->first()->status)->toBe(StreamStatus::FAILED);
    Queue::assertNotPushed(AddTrackJob::class);
});

it('replays a lost track webhook, which ingests it as a track', function () {
    Queue::fake();
    Storage::fake('s3');
    Storage::fake('chunks');
    $key = 'tmp-videos/'.Str::ulid().'.wav';
    Storage::disk('s3')->put($key, 'audio');
    cacheTrackMeta($this, $key);

    app(PruneScratchJob::class)->handle(app(UppyS3Service::class));

    Queue::assertPushed(OnVideoUploaded::class);
});

it('prunes orphaned track uploads after the grace window', function () {
    Queue::fake();
    Storage::fake('s3');
    Storage::fake('chunks');
    $old = 'tmp-videos/'.Str::ulid(now()->subDays(3)).'.wav';
    Storage::disk('s3')->put($old, 'audio');

    app(PruneScratchJob::class)->handle(app(UppyS3Service::class));

    expect(Storage::disk('s3')->exists($old))->toBeFalse();
});
