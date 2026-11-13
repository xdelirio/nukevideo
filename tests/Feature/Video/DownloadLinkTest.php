<?php

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function downloadableVideo(string $status = 'completed'): Video
{
    $video = Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);

    Output::create(['video_id' => $video->id, 'status' => 'completed']);

    return $video;
}

function track(Video $video, string $type, array $attributes = []): Stream
{
    $stream = $video->streams()->create([
        // `streams.path` is unique, and the file ULID is minted separately from the stream's own.
        'path' => "{$video->ulid}/{$type}/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => $type,
        'meta' => [],
        // What `recordStoredSizes` writes for a track the template kept. Null is the absence the
        // mint checks for, so a fixture without it would read as "never retained".
        'file_size' => 5,
        ...$attributes,
    ]);

    Storage::disk('s3')->put($stream->storedPath($video), 'bytes');

    return $stream;
}

beforeEach(function () {
    Storage::fake('s3');

    // Signed links only: attribution hangs off the token, and an unsigned edge mints none.
    CdnSettings::fake(['token_secret' => bin2hex('secret'), 'token_window' => 3600]);

    Node::create([
        'name' => 'edge',
        'user' => 'root',
        'ip_address' => '10.0.0.1',
        'hostname' => 'edge.example.com',
        'type' => 'proxy',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

it('mints a link for a track', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio', ['name' => 'Español', 'language' => 'es']);

    $this->postJson("/api/streams/{$stream->ulid}/download")
        ->assertOk()
        ->assertJsonPath('data.type', 'audio')
        ->assertJsonPath('data.filename', basename($stream->path))
        ->assertJsonStructure(['data' => ['url', 'expiresAt', 'filename', 'type', 'size']]);
});

it('names each track by its stored file, so two never collide', function () {
    $video = downloadableVideo();
    $tall = track($video, 'video', ['height' => 1080]);
    $short = track($video, 'video', ['height' => 720]);

    // Renditions carry no label of their own, so any human-facing name would have to be invented
    // and could repeat. The stored name is a ULID and is unique by construction.
    $first = $this->postJson("/api/streams/{$tall->ulid}/download")->assertOk()->json('data.filename');
    $second = $this->postJson("/api/streams/{$short->ulid}/download")->assertOk()->json('data.filename');

    expect($first)->toBe(basename($tall->path))
        ->and($second)->toBe(basename($short->path))
        ->and($first)->not->toBe($second);
});

it('refuses to hand out the untouched original', function () {
    $video = downloadableVideo();
    $original = $video->streams()->create([
        'path' => "tmp-videos/{$video->ulid}.mkv",
        'type' => 'original',
        'meta' => [],
    ]);

    $this->postJson("/api/streams/{$original->ulid}/download")->assertStatus(422);
});

it('refuses while the video is still processing', function () {
    $video = downloadableVideo('running');
    $stream = track($video, 'audio');

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(409);
});

it('404s when the track was never retained', function () {
    $video = downloadableVideo();

    // A template with `keep_processed_files` off has the rendition dropped before the sync, so
    // `recordStoredSizes` leaves the row's size null and no object ever reaches S3.
    $stream = track($video, 'video', ['height' => 720, 'file_size' => null]);

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(404);
});

it('reads retention off the row instead of asking S3 for it', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    // The object is gone from the bucket while the row still records a size. The mint has to
    // answer from the row: that HEAD was one S3 round trip per track, on a path a caller walks
    // once per track, asking what the record in hand already knew.
    Storage::disk('s3')->delete($stream->storedPath($video));

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertOk();
});

it('does not hand a track to another project', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    $other = Project::factory()->for(User::factory()->create())->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(404);
});

it('answers 503 rather than 500 when no node can serve it', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    Node::query()->update(['is_active' => false]);

    $this->postJson("/api/streams/{$stream->ulid}/download")->assertStatus(503);
});

it('records the tracking id against the token hash instead of putting it in the link', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    $data = $this->postJson("/api/streams/{$stream->ulid}/download", ['trackingId' => 'client-42'])
        ->assertOk()->json('data');

    parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $query);

    // The URL is the same whoever asked for it: no leading path segment, no `tid`, and the
    // response carries nothing about the viewer either. The attribution is the mapping the mint
    // recorded under the hash of the token inside the link.
    expect($data)->toHaveKeys(['url', 'expiresAt'])
        ->and($data)->not->toHaveKeys(['token', 'tokenHash'])
        ->and(parse_url($data['url'], PHP_URL_PATH))->toStartWith("/{$video->ulid}/download/audio/")
        ->and($query)->not->toHaveKey('tid')
        ->and(app(TrackingRegistry::class)->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');
});

it('rejects a tracking id that could reshape the signed parameters', function () {
    $video = downloadableVideo();
    $stream = track($video, 'audio');

    // The alphabet is the contract: the id is a cache label and an analytics column, and was once a
    // URL component, so it stays narrow. Both spellings, because Spatie also binds the bare
    // property name — a payload using it must not skip the charset check.
    foreach (['trackingId'] as $key) {
        foreach (['a&b=c', 'a=b', 'has space', str_repeat('x', 65)] as $bad) {
            $this->postJson("/api/streams/{$stream->ulid}/download", [$key => $bad])->assertStatus(422);
        }
    }
});
