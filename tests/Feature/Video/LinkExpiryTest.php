<?php

/**
 * The per-link lifetime. The token window is the default, not the ceiling: a stream wants an hour
 * and an offline download a day, from the same API. What these pin is that the lifetime asked for
 * is the one SIGNED — the `exp` the edge checks — that `expiresAt` reports that same moment, and that the tracking mapping lives as long as the link it describes.
 */

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use App\Support\LinkExpiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function expiringVideo(): Video
{
    $video = Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);

    $output = Output::create(['video_id' => $video->id, 'status' => 'completed']);
    $output->forceFill(['packaged_formats' => ['hls']])->save();

    $rendition = $video->streams()->create([
        'path' => "{$video->ulid}/play/".strtoupper((string) Str::ulid()),
        'type' => 'video',
        'meta' => [],
        'input_params' => ['video_codec' => 'libx264'],
        'height' => 1080,
        'width' => 1920,
        'package_size' => 10,
    ]);
    $output->streams()->attach($rendition->id);

    return $video;
}

function expiringTrack(Video $video): Stream
{
    return $video->streams()->create([
        'path' => "{$video->ulid}/audio/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => 'audio',
        'meta' => [],
        'file_size' => 5,
    ]);
}

/** The `exp` the edge validates, read out of a self-hosted link's `__hdnea__` token. */
function signedExpiryOf(string $url): int
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    preg_match('/(^|~)exp=(?<exp>[0-9]+)~/', $query['__hdnea__'], $m);

    return (int) $m['exp'];
}

function fakeExpiryCdn(): void
{
    CdnSettings::fake(['token_secret' => bin2hex('secret'), 'token_window' => 3600]);
}

beforeEach(function () {
    Storage::fake('s3');
    fakeExpiryCdn();
    Carbon::setTestNow('2026-10-23 12:00:00');

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

it('signs the lifetime asked for into the playback token, and reports that same moment', function () {
    $video = expiringVideo();

    $data = $this->postJson("/api/videos/{$video->ulid}/play", ['expiresIn' => 86_400])->assertOk()->json('data');

    expect(signedExpiryOf($data['sources'][0]['url']))->toBe(now()->timestamp + 86_400)
        ->and(Carbon::parse($data['expiresAt'])->timestamp)->toBe(now()->timestamp + 86_400);
});

it('falls back to the token window when no lifetime is asked for', function () {
    $video = expiringVideo();

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    expect(signedExpiryOf($data['sources'][0]['url']))->toBe(now()->timestamp + 3600)
        ->and(Carbon::parse($data['expiresAt'])->timestamp)->toBe(now()->timestamp + 3600);
});

it('signs the lifetime into a single download and into every link of a batch', function () {
    $video = expiringVideo();
    $first = expiringTrack($video);
    expiringTrack($video);

    $single = $this->postJson("/api/streams/{$first->ulid}/download", ['expiresIn' => 600])->assertOk()->json('data');
    $batch = $this->postJson("/api/videos/{$video->ulid}/downloads", ['expiresIn' => 7200])->assertOk()->json('data.links');

    expect(signedExpiryOf($single['url']))->toBe(now()->timestamp + 600)
        ->and(Carbon::parse($single['expiresAt'])->timestamp)->toBe(now()->timestamp + 600)
        ->and($batch)->toHaveCount(2);

    foreach ($batch as $link) {
        expect(signedExpiryOf($link['url']))->toBe(now()->timestamp + 7200)
            ->and(Carbon::parse($link['expiresAt'])->timestamp)->toBe(now()->timestamp + 7200);
    }
});

it('keeps the tracking id of a long link for as long as the link is valid', function () {
    // A mapping that died at the token window would leave the last 23 hours of a day-long
    // download unattributed: the bytes counted, the customer label lost.
    $video = expiringVideo();

    $url = $this->postJson("/api/videos/{$video->ulid}/play", ['expiresIn' => 86_400, 'trackingId' => 'customer-7'])
        ->assertOk()->json('data.sources.0.url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $hash = hash('sha256', $query['__hdnea__']);

    $this->travel(23)->hours();

    expect(app(TrackingRegistry::class)->resolve($hash))->toBe('customer-7');
});

it('validates the lifetime on every mint', function (mixed $value) {
    $video = expiringVideo();
    $track = expiringTrack($video);

    $this->postJson("/api/videos/{$video->ulid}/play", ['expiresIn' => $value])
        ->assertStatus(422)->assertJsonValidationErrors('expiresIn');
    $this->postJson("/api/videos/{$video->ulid}/downloads", ['expiresIn' => $value])
        ->assertStatus(422)->assertJsonValidationErrors('expiresIn');
    $this->postJson("/api/streams/{$track->ulid}/download", ['expiresIn' => $value])
        ->assertStatus(422)->assertJsonValidationErrors('expiresIn');
})->with([
    'below the floor' => LinkExpiry::MIN_SECONDS - 1,
    'above a week' => LinkExpiry::MAX_SECONDS + 1,
    'not a number' => 'tomorrow',
]);
