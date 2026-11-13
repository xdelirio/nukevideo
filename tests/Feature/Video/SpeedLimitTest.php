<?php

/**
 * The per-link speed limit. What these pin is that the limit is part of the SIGNATURE: the edge
 * reads it back out of the token after the HMAC passed, so a limit that rode next to the token
 * instead of inside it would be one the viewer deletes from the URL.
 */

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function speedLimitedVideo(): Video
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

function speedLimitedTrack(Video $video): Stream
{
    return $video->streams()->create([
        'path' => "{$video->ulid}/audio/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => 'audio',
        'meta' => [],
        'file_size' => 5,
    ]);
}

/** The `__hdnea__` value of a minted URL. */
function hdneaOf(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['__hdnea__'];
}

/**
 * The `limit` the edge would apply, read with the same pattern as its `map` — or null when the
 * token carries none, which the edge serves unlimited.
 */
function edgeLimitOf(string $token): ?int
{
    return preg_match('/(^|~)limit=(?<l>[0-9]+)(~|$)/', $token, $m) === 1 ? (int) $m['l'] : null;
}

/** Whether the edge's validator would accept the token: HMAC over everything before `~hmac=`. */
function edgeAccepts(string $token): bool
{
    [$signed, $hmac] = explode('~hmac=', $token, 2);

    return hash_equals(hash_hmac('sha256', $signed, 'secret'), $hmac);
}

function fakeSpeedLimitCdn(string $secret = 'secret'): void
{
    CdnSettings::fake(['token_secret' => $secret === '' ? '' : bin2hex($secret), 'token_window' => 3600]);
}

beforeEach(function () {
    Storage::fake('s3');
    fakeSpeedLimitCdn();

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

it('signs the limit into the playback token, in bytes per second', function () {
    $video = speedLimitedVideo();

    $url = $this->postJson("/api/videos/{$video->ulid}/play", ['speedLimitKbps' => 3000])
        ->assertOk()->json('data.sources.0.url');
    $token = hdneaOf($url);

    // 3000 kbps is 375 000 bytes a second: bitrate kilobits, not nginx's 1024-byte `k`.
    expect(edgeLimitOf($token))->toBe(375_000)
        ->and(edgeAccepts($token))->toBeTrue();
});

it('makes the limit tamper-proof: a token with the rate edited fails the HMAC', function () {
    $video = speedLimitedVideo();

    $token = hdneaOf($this->postJson("/api/videos/{$video->ulid}/play", ['speedLimitKbps' => 3000])
        ->assertOk()->json('data.sources.0.url'));

    $lifted = str_replace('~limit=375000', '~limit=0', $token);
    $dropped = str_replace('~limit=375000', '', $token);

    expect($lifted)->not->toBe($token)
        ->and(edgeAccepts($lifted))->toBeFalse()
        ->and(edgeAccepts($dropped))->toBeFalse();
});

it('carries no rate when none was asked for', function () {
    $video = speedLimitedVideo();

    $token = hdneaOf($this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.sources.0.url'));

    expect(edgeLimitOf($token))->toBeNull()
        ->and($token)->not->toContain('limit=');
});

it('signs the limit into a single download and into every link of a batch', function () {
    $video = speedLimitedVideo();
    $first = speedLimitedTrack($video);
    speedLimitedTrack($video);

    $single = $this->postJson("/api/streams/{$first->ulid}/download", ['speedLimitKbps' => 8000])
        ->assertOk()->json('data.url');

    $batch = $this->postJson("/api/videos/{$video->ulid}/downloads", ['speedLimitKbps' => 8000])
        ->assertOk()->json('data.links');

    expect(edgeLimitOf(hdneaOf($single)))->toBe(1_000_000)
        ->and($batch)->toHaveCount(2);

    foreach ($batch as $link) {
        $token = hdneaOf($link['url']);

        // Each link is still scoped to its one object: the limit is added to the token, it does
        // not replace the exact-match ACL.
        expect(edgeLimitOf($token))->toBe(1_000_000)
            ->and(edgeAccepts($token))->toBeTrue()
            ->and($token)->toContain('~acl='.parse_url($link['url'], PHP_URL_PATH).'~');
    }
});

it('validates the limit', function (mixed $value) {
    $video = speedLimitedVideo();

    $this->postJson("/api/videos/{$video->ulid}/play", ['speedLimitKbps' => $value])
        ->assertStatus(422)
        ->assertJsonValidationErrors('speedLimitKbps');
})->with([
    'below the floor' => 63,
    'above the ceiling' => 10_000_001,
    'not a number' => 'fast',
]);

it('refuses a limit an unsigned edge cannot enforce instead of minting without it', function () {
    fakeSpeedLimitCdn('');
    $video = speedLimitedVideo();
    $track = speedLimitedTrack($video);

    $this->postJson("/api/videos/{$video->ulid}/play", ['speedLimitKbps' => 3000])
        ->assertStatus(422)->assertJsonValidationErrors('speedLimitKbps');
    $this->postJson("/api/videos/{$video->ulid}/downloads", ['speedLimitKbps' => 3000])
        ->assertStatus(422)->assertJsonValidationErrors('speedLimitKbps');
    $this->postJson("/api/streams/{$track->ulid}/download", ['speedLimitKbps' => 3000])
        ->assertStatus(422)->assertJsonValidationErrors('speedLimitKbps');

    // Without a limit the same edge mints as before.
    $this->postJson("/api/videos/{$video->ulid}/play")->assertOk();
});
