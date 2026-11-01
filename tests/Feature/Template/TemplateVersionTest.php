<?php

/**
 * A template's encoding definition is versioned and every video pins the version it was ingested
 * with. Editing a template used to change, after the fact, what its existing videos are retried,
 * re-probed and extended with: a retry after an edit encoded differently from what was already
 * published, and a track added later could be encoded with a codec the video never used.
 */

use App\DTOs\UploadMeta;
use App\Models\Project;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Models\Video;
use App\Services\OnVideoUploadedService;
use App\Services\UppyS3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const AAC_OUTPUT = ['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720]], 'audio' => ['audio_codec' => 'aac', 'channels' => [['channels' => '2', 'audio_bitrate' => '128k']]]];
const OPUS_OUTPUT = ['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720]], 'audio' => ['audio_codec' => 'libopus', 'channels' => [['channels' => '2', 'audio_bitrate' => '96k']]]];

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
    $this->template = Template::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'name' => 'HLS',
        'query' => ['outputs' => [AAC_OUTPUT]],
    ]);
});

it('creates version 1 with the template and points the template at it', function () {
    $version = $this->template->fresh()->currentVersion;

    expect($version)->toBeInstanceOf(TemplateVersion::class)
        ->and($version->version)->toBe(1)
        ->and($version->query)->toBe(['outputs' => [AAC_OUTPUT]]);
});

it('cuts a new version when the encoding changes, and only then', function () {
    $this->template->update(['name' => 'Renamed', 'enabled' => false]);
    expect($this->template->versions()->count())->toBe(1);

    $this->template->update(['query' => ['outputs' => [OPUS_OUTPUT]]]);
    $template = $this->template->fresh();

    expect($template->versions()->count())->toBe(2)
        ->and($template->currentVersion->version)->toBe(2)
        ->and($template->currentVersion->query)->toBe(['outputs' => [OPUS_OUTPUT]])
        // The old version is untouched: it is what older videos keep pointing at.
        ->and($template->versions()->where('version', 1)->first()->query)->toBe(['outputs' => [AAC_OUTPUT]]);
});

it('cuts a new version through the update endpoint', function () {
    Sanctum::actingAs($this->user);

    $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->putJson("/api/templates/{$this->template->ulid}", [
            'name' => 'HLS',
            'query' => ['outputs' => [OPUS_OUTPUT]],
        ])->assertOk();

    expect($this->template->fresh()->currentVersion->version)->toBe(2);
});

it('encodes a video with no version yet with its template, as before the backfill', function () {
    $video = Video::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id, 'template_id' => $this->template->id,
        'name' => 'legacy', 'duration' => 10, 'aspect_ratio' => '16:9', 'status' => 'completed',
    ]);

    expect($video->encodingQuery())->toBe(['outputs' => [AAC_OUTPUT]])
        ->and($video->encodingDefinition())->toBeInstanceOf(Template::class);
});

it('keeps the original encoding as version 1 when a template without versions is edited', function () {
    // As a template created before versions stands until the backfill runs.
    DB::table('template_versions')->delete();
    DB::table('templates')->update(['current_version_id' => null]);

    $this->template->fresh()->update(['query' => ['outputs' => [OPUS_OUTPUT]]]);
    $template = $this->template->fresh();

    // What its existing videos were encoded with is not lost to the edit.
    expect($template->versions()->orderBy('version')->pluck('query')->all())->toBe([['outputs' => [AAC_OUTPUT]], ['outputs' => [OPUS_OUTPUT]]])
        ->and($template->currentVersion->version)->toBe(2);
});

it('cuts a version at ingest for a template that has none yet', function () {
    Queue::fake();
    DB::table('template_versions')->delete();
    DB::table('templates')->update(['current_version_id' => null]);
    $key = 'tmp-videos/'.Str::ulid().'.mp4';
    app(UppyS3Service::class)->storeUploadMeta($key, new UploadMeta(
        user: $this->user->ulid, project: $this->project->ulid, template: $this->template->ulid, filename: 'clip.mp4',
    ));

    app(OnVideoUploadedService::class)->handle($key, 1024);

    $video = Video::firstOrFail();
    expect($video->template_version_id)->not->toBeNull()
        ->and($video->templateVersion->query)->toBe(['outputs' => [AAC_OUTPUT]]);
});

it('pins the current version on a video at ingest', function () {
    Queue::fake();
    $key = 'tmp-videos/'.Str::ulid().'.mp4';
    app(UppyS3Service::class)->storeUploadMeta($key, new UploadMeta(
        user: $this->user->ulid, project: $this->project->ulid, template: $this->template->ulid, filename: 'clip.mp4',
    ));

    app(OnVideoUploadedService::class)->handle($key, 1024);

    $video = Video::firstOrFail();
    expect($video->template_version_id)->toBe($this->template->fresh()->current_version_id);
});

it('keeps encoding a video with its own version after the template is edited', function () {
    $video = Video::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'template_id' => $this->template->id, 'template_version_id' => $this->template->fresh()->current_version_id,
        'name' => 'clip', 'duration' => 10, 'aspect_ratio' => '16:9', 'status' => 'completed',
    ]);

    $this->template->update(['query' => ['outputs' => [OPUS_OUTPUT]]]);

    expect($video->fresh()->encodingQuery())->toBe(['outputs' => [AAC_OUTPUT]]);
});

it('reports the template version a video was encoded with, and the template its current one', function () {
    Sanctum::actingAs($this->user);
    $video = Video::create([
        'user_id' => $this->user->id, 'project_id' => $this->project->id,
        'template_id' => $this->template->id, 'template_version_id' => $this->template->fresh()->current_version_id,
        'name' => 'clip', 'duration' => 10, 'aspect_ratio' => '16:9', 'status' => 'completed',
    ]);
    $this->template->update(['query' => ['outputs' => [OPUS_OUTPUT]]]);

    $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->getJson("/api/videos/{$video->ulid}")
        ->assertOk()
        ->assertJsonPath('data.template', ['ulid' => $this->template->ulid, 'name' => 'HLS', 'version' => 1]);

    $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->getJson('/api/templates')
        ->assertOk()
        ->assertJsonPath('data.0.version', 2);
});
