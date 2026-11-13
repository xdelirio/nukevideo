<?php

/**
 * The fallback attribution for minted links. A session or personal-token request that names no
 * tracking id is the account user acting as their own viewer — the panel never sends one — so the
 * link's token is recorded under their ULID and the traffic lands in the analytics instead of the
 * unattributed bucket. A project key without one stays unattributed on purpose: there is no user
 * behind it, and inventing one would pollute the integrator's own id space.
 */

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\ApiTokenService;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function attributableVideo(): Video
{
    return Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

/** The tracking id recorded for a minted link, read the way the ingest will: off the token in the URL. */
function attributedTo(string $url): ?string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return app(TrackingRegistry::class)->resolve(hash('sha256', $query['__hdnea__']));
}

function attributableTrack(Video $video): Stream
{
    $stream = $video->streams()->create([
        'path' => "{$video->ulid}/audio/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => 'audio',
        'meta' => [],
        // A retained track carries the size `recordStoredSizes` wrote; null is what the mint
        // reads as "never retained".
        'file_size' => 5,
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
});

it('attributes a session-minted download link to the user when no tracking id is sent', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $stream = attributableTrack(attributableVideo());

    $url = $this->postJson("/api/streams/{$stream->ulid}/download")->assertOk()->json('data.url');

    expect(attributedTo($url))->toBe($this->user->ulid);
});

it('lets an explicit tracking id override the session fallback', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $stream = attributableTrack(attributableVideo());

    $url = $this->postJson("/api/streams/{$stream->ulid}/download", ['trackingId' => 'client-42'])
        ->assertOk()->json('data.url');

    expect(attributedTo($url))->toBe('client-42');
});

it('leaves a project-key download link unattributed when no tracking id is sent', function () {
    // A real Bearer token, not acting-as: the fallback keys off who the actor is, and a project
    // key authenticates as the project itself.
    $key = app(ApiTokenService::class)->regenerateProjectKey($this->project)->plainTextToken;

    $video = attributableVideo();
    $stream = attributableTrack($video);

    $url = $this->withToken($key)->postJson("/api/streams/{$stream->ulid}/download")
        ->assertOk()->json('data.url');

    expect($url)->toContain('__hdnea__=')
        ->and(attributedTo($url))->toBeNull();
});

it('attributes a session-minted playback link to the user when no tracking id is sent', function () {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $video = attributableVideo();
    $output = Output::create(['video_id' => $video->id, 'status' => 'completed']);
    $output->recordFormats(['dash', 'hls']);

    $url = $this->postJson("/api/videos/{$video->ulid}/play")
        ->assertOk()->json('data.sources.0.url');

    // The URL names nobody — the attribution is the mapping the mint recorded under the token.
    expect(parse_url($url, PHP_URL_PATH))->toStartWith("/{$video->ulid}/")
        ->and(attributedTo($url))->toBe($this->user->ulid);
});
