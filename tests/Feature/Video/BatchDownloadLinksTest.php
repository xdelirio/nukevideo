<?php

/**
 * The batch mint. Its whole reason to exist is that the per-request work — auth, the project
 * resolution, the status check, the proxy node — is per VIDEO, so a caller fetching a whole
 * video's tracks should pay it once rather than once per track.
 */

use App\Data\Video\DownloadVideoTracksData;
use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function batchVideo(string $status = 'completed'): Video
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

function batchTrack(Video $video, string $type, array $attributes = []): Stream
{
    return $video->streams()->create([
        'path' => "{$video->ulid}/{$type}/".strtoupper((string) Str::ulid()).'.mp4',
        'type' => $type,
        'meta' => [],
        // What packaging writes for a track the template kept; null is "never retained".
        'file_size' => 5,
        ...$attributes,
    ]);
}

beforeEach(function () {
    Storage::fake('s3');

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

it('mints every downloadable track when no list is given', function () {
    $video = batchVideo();
    $tall = batchTrack($video, 'video', ['height' => 1080]);
    $short = batchTrack($video, 'video', ['height' => 720]);
    $audio = batchTrack($video, 'audio', ['language' => 'es']);

    $data = $this->postJson("/api/videos/{$video->ulid}/downloads")->assertOk()->json('data');

    expect($data['links'])->toHaveCount(3)
        ->and(array_column($data['links'], 'filename'))->toBe([
            basename($tall->path), basename($short->path), basename($audio->path),
        ])
        ->and($data['skipped'])->toBe([]);
});

it('does not report the untouched original as skipped when it was never asked for', function () {
    $video = batchVideo();
    batchTrack($video, 'audio');
    $video->streams()->create(['path' => "tmp-videos/{$video->ulid}.mkv", 'type' => 'original', 'meta' => [], 'file_size' => 900]);

    // Omitting the list asks for the downloadable tracks; the original was not among them, so it
    // is absent rather than refused. Naming it explicitly is a different question — below.
    $data = $this->postJson("/api/videos/{$video->ulid}/downloads")->assertOk()->json('data');

    expect($data['links'])->toHaveCount(1)
        ->and($data['skipped'])->toBe([]);
});

it('answers an explicit list in the order it was asked, and says why a track was skipped', function () {
    $video = batchVideo();
    $audio = batchTrack($video, 'audio');
    $dropped = batchTrack($video, 'video', ['height' => 720, 'file_size' => null]);
    $original = $video->streams()->create(['path' => "tmp-videos/{$video->ulid}.mkv", 'type' => 'original', 'meta' => [], 'file_size' => 900]);
    $stale = strtoupper((string) Str::ulid());

    $data = $this->postJson("/api/videos/{$video->ulid}/downloads", [
        'streamUlids' => [$dropped->ulid, $audio->ulid, $original->ulid, $stale],
    ])->assertOk()->json('data');

    expect($data['links'])->toHaveCount(1)
        ->and($data['links'][0]['filename'])->toBe(basename($audio->path))
        ->and($data['skipped'])->toBe([
            ['ulid' => $dropped->ulid, 'reason' => 'not_retained'],
            ['ulid' => $original->ulid, 'reason' => 'not_downloadable'],
            ['ulid' => $stale, 'reason' => 'not_found'],
        ]);
});

it('takes an empty list literally rather than as "everything"', function () {
    $video = batchVideo();
    batchTrack($video, 'audio');

    $data = $this->postJson("/api/videos/{$video->ulid}/downloads", ['streamUlids' => []])
        ->assertOk()->json('data');

    expect($data['links'])->toBe([])->and($data['skipped'])->toBe([]);
});

it('mints one link for a track named twice', function () {
    $video = batchVideo();
    $audio = batchTrack($video, 'audio');

    $data = $this->postJson("/api/videos/{$video->ulid}/downloads", [
        'streamUlids' => [$audio->ulid, $audio->ulid],
    ])->assertOk()->json('data');

    expect($data['links'])->toHaveCount(1);
});

it('does not query per track', function () {
    $video = batchVideo();
    foreach (range(1, 8) as $i) {
        batchTrack($video, 'audio', ['language' => "l{$i}"]);
    }

    DB::enableQueryLog();
    $this->postJson("/api/videos/{$video->ulid}/downloads")->assertOk();
    $log = collect(DB::getQueryLog())->pluck('query');

    // The two that used to repeat: one lookup for every track, one for the fleet, whatever N is.
    expect($log->filter(fn ($q) => str_contains($q, '"streams"'))->count())->toBe(1)
        ->and($log->filter(fn ($q) => str_contains($q, '"nodes"'))->count())->toBe(1);
});

it('attributes every link in the batch to the tracking id in one write', function () {
    $video = batchVideo();
    batchTrack($video, 'audio');
    batchTrack($video, 'video', ['height' => 720]);

    $links = $this->postJson("/api/videos/{$video->ulid}/downloads", ['trackingId' => 'client-42'])
        ->assertOk()->json('data.links');

    $registry = app(TrackingRegistry::class);

    foreach ($links as $link) {
        parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $query);
        expect($registry->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');
    }
});

it('refuses the whole batch while the video is still processing', function () {
    $video = batchVideo('running');
    batchTrack($video, 'audio');

    $this->postJson("/api/videos/{$video->ulid}/downloads")->assertStatus(409);
});

it('answers 503 for the whole batch when no node can serve it', function () {
    $video = batchVideo();
    batchTrack($video, 'audio');

    Node::query()->update(['is_active' => false]);

    $this->postJson("/api/videos/{$video->ulid}/downloads")->assertStatus(503);
});

it('does not hand another project a video of ours', function () {
    $video = batchVideo();
    batchTrack($video, 'audio');

    $other = Project::factory()->for(User::factory()->create())->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->postJson("/api/videos/{$video->ulid}/downloads")->assertStatus(404);
});

it('will not mint a track of another video, even one of ours', function () {
    $video = batchVideo();
    $other = batchVideo();
    $stranger = batchTrack($other, 'audio');

    // Keyed by video is the invariant the hoisting rests on, so a stream from elsewhere is not a
    // track of this video however legitimately the caller owns it.
    $data = $this->postJson("/api/videos/{$video->ulid}/downloads", ['streamUlids' => [$stranger->ulid]])
        ->assertOk()->json('data');

    expect($data['links'])->toBe([])
        ->and($data['skipped'])->toBe([['ulid' => $stranger->ulid, 'reason' => 'not_found']]);
});

it('rejects a list longer than the cap, a malformed ulid and a bad tracking id', function () {
    $video = batchVideo();
    $ulid = strtoupper((string) Str::ulid());

    $this->postJson("/api/videos/{$video->ulid}/downloads", [
        'streamUlids' => array_fill(0, DownloadVideoTracksData::MAX_TRACKS + 1, $ulid),
    ])->assertStatus(422);

    $this->postJson("/api/videos/{$video->ulid}/downloads", ['streamUlids' => ['nope']])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/downloads", ['trackingId' => 'a&b=c'])->assertStatus(422);
});

it('reads camelCase only, and ignores a snake_case body', function () {
    $video = batchVideo();
    $audio = batchTrack($video, 'audio');
    batchTrack($video, 'video', ['height' => 720]);

    // The properties map camelCase explicitly, so snake_case keys are simply not bound. Pinned
    // rather than left to chance: `stream_ulids` does not narrow anything, it reads as an omitted
    // list — every downloadable track — and `tracking_id` leaves the batch unattributed.
    $data = $this->postJson("/api/videos/{$video->ulid}/downloads", [
        'stream_ulids' => [$audio->ulid],
        'tracking_id' => 'client-42',
    ])->assertOk()->json('data');

    expect($data['links'])->toHaveCount(2);

    parse_str((string) parse_url($data['links'][0]['url'], PHP_URL_QUERY), $query);
    expect(app(TrackingRegistry::class)->resolve(hash('sha256', $query['__hdnea__'])))
        ->toBe(test()->user->ulid);
});
