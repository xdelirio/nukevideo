<?php

/**
 * The video-level playback mint. Its reason to exist is the same as the batch download's — auth,
 * the project resolution, the status check and the delivery node are per VIDEO — plus one the
 * download batch does not have: a playback token is scoped to the manifest's directory, and every
 * output of a video packages into the same one, so the whole answer authorizes the same bytes.
 *
 * The answer is FLAT: one entry per (output, format), each one self-sufficient enough to hand
 * straight to a player.
 */

use App\Data\Video\PlayVideosData;
use App\Enums\SubtitleFormat;
use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Services\Cdn\TrackingRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function playVideo(string $status = 'completed'): Video
{
    return Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);
}

/**
 * An output as packaging leaves it: a frozen format list and the renditions it packages, which is
 * what the ladder cap is resolved against.
 *
 * @param  list<int>  $heights
 * @param  list<string>|null  $formats
 */
function playOutput(
    Video $video,
    array $heights = [1080],
    ?array $formats = ['hls', 'dash'],
    string $status = 'completed',
    string $videoCodec = 'libx264',
    array $audioLanguages = [],
    string $audioCodec = 'aac',
): Output {
    $output = Output::create(['video_id' => $video->id, 'status' => $status]);
    $output->forceFill(['packaged_formats' => $formats])->save();

    // One codec's ABR ladder, which is what an output is: the variants differ in resolution only.
    foreach ($heights as $height) {
        $stream = $video->streams()->create([
            'path' => "{$video->ulid}/play/".strtoupper((string) Str::ulid()),
            'type' => 'video',
            'meta' => [],
            'input_params' => ['video_codec' => $videoCodec],
            'height' => $height,
            'width' => (int) ($height * 16 / 9),
            'package_size' => 10,
        ]);

        $output->streams()->attach($stream->id);
    }

    // Every language of an output shares its codec — the template names one `audio_codec` and
    // applies it to all of them.
    foreach ($audioLanguages as $language) {
        $stream = $video->streams()->create([
            'path' => "{$video->ulid}/play/".strtoupper((string) Str::ulid()),
            'type' => 'audio',
            'meta' => [],
            'input_params' => ['audio_codec' => $audioCodec],
            'language' => $language,
            'package_size' => 10,
        ]);

        $output->streams()->attach($stream->id);
    }

    return $output;
}

/**
 * A subtitle as packaging leaves it: the standalone WebVTT filed in the download zone, with the
 * `file_size` that records it is there. Not attached to any output — subtitles hang off the video.
 *
 * @param  array<string, mixed>  $attributes
 */
function playSubtitle(Video $video, string $language = 'en', array $attributes = []): Stream
{
    $stream = $video->streams()->create([
        'path' => "{$video->ulid}/subtitle/pending.vtt",
        'type' => 'subtitle',
        'meta' => [],
        'language' => $language,
        'name' => strtoupper($language),
        'file_size' => 2048,
        'package_size' => 10,
        ...$attributes,
    ]);

    // The file is named after the stream's own ULID, which only exists once the model has booted.
    $stream->update(['path' => "{$video->ulid}/subtitle/{$stream->ulid}.vtt"]);

    return $stream;
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

it('answers one flat source per output and format', function () {
    $video = playVideo();
    $both = playOutput($video);
    $dashOnly = playOutput($video, formats: ['dash']);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    // Three manifests out of two outputs, not two rows holding a map of formats.
    expect($data['sources'])->toHaveCount(3)
        ->and(array_column($data['sources'], 'format'))->toBe(['hls', 'dash', 'dash'])
        ->and(array_column($data['sources'], 'outputUlid'))
        ->toBe([$both->ulid, $both->ulid, $dashOnly->ulid]);

    // The manifest is named after its own output, in the video's single play prefix.
    expect($data['sources'][0]['url'])->toContain("/{$video->ulid}/play/{$both->ulid}.m3u8")
        ->and($data['sources'][2]['url'])->toContain("/{$video->ulid}/play/{$dashOnly->ulid}.mpd");

    // The published contract, pinned: this is what `docs/api/videos.md` documents, and an
    // integrator reads a field that is not here as a field that does not exist.
    expect(array_keys($data))->toBe(['videoUlid', 'thumbnailUrl', 'storyboardUrl', 'expiresAt', 'sources', 'subtitles'])
        ->and(array_keys($data['sources'][0]))
        ->toBe(['url', 'format', 'outputUlid', 'videoCodec', 'audioCodec']);
});

it('reports the decodable codec of each source, not the encoder that wrote it', function () {
    $video = playVideo();
    // Three ways to spell H.264 and one AV1: what a player can act on is the bitstream, so the
    // NVENC and QSV encoders must not leak into the answer.
    playOutput($video, formats: ['hls'], videoCodec: 'h264_nvenc', audioLanguages: ['es', 'en'], audioCodec: 'libfdk_aac');
    playOutput($video, formats: ['dash'], videoCodec: 'libsvtav1', audioLanguages: ['es'], audioCodec: 'libopus');

    $sources = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.sources');

    // One value per source, not one per stream: the first output carries two languages and still
    // reports the single codec they share.
    expect($sources[0]['videoCodec'])->toBe('h264')
        ->and($sources[0]['audioCodec'])->toBe('aac')
        ->and($sources[1]['videoCodec'])->toBe('av1')
        ->and($sources[1]['audioCodec'])->toBe('opus');
});

it('reports no audio codec for an output that carries no audio', function () {
    $video = playVideo();
    playOutput($video, formats: ['hls']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.sources');

    expect($sources[0]['audioCodec'])->toBeNull();
});

it('hoists the thumbnail and the storyboard out of the outputs', function () {
    $video = playVideo();
    playOutput($video);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    expect($data['thumbnailUrl'])->toContain("{$video->ulid}/assets/thumbnail.jpg")
        ->and($data['storyboardUrl'])->toContain("{$video->ulid}/assets/storyboard.vtt")
        ->and($data['sources'][0])->not->toHaveKey('thumbnailUrl')
        ->and($data['expiresAt'])->not->toBeEmpty();
});

it('leaves out an output that never finished packaging, without costing the others their links', function () {
    $video = playVideo();
    $good = playOutput($video, formats: ['hls']);
    // A failed output keeps `packaged_formats` null, so `formats()` falls back to a live
    // computation and would happily name a manifest that was never written. A video is completed
    // once ONE output succeeded, so this pair is an ordinary state, not a contradiction.
    playOutput($video, formats: null, status: 'failed');

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    expect($data['sources'])->toHaveCount(1)
        ->and($data['sources'][0]['outputUlid'])->toBe($good->ulid);
});

it('answers with no sources at all rather than an error when nothing can be served', function () {
    $video = playVideo();
    playOutput($video, formats: []);

    $data = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data');

    // Still a 200 with the video's assets: the video exists and the caller is entitled to it,
    // there is simply nothing to play.
    expect($data['sources'])->toBe([])
        ->and($data['thumbnailUrl'])->not->toBeEmpty();
});

/** The token of a minted URL, as the edge hands it back when it asks for the manifest. */
function linkToken(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['__hdnea__'];
}

it('signs the full manifest and leaves the resolution cap to the options the link names', function () {
    $video = playVideo();
    $tall = playOutput($video, heights: [480, 720, 1080], formats: ['hls']);
    $short = playOutput($video, heights: [360, 480], formats: ['hls']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 720])
        ->assertOk()->json('data.sources');

    // No capped file name any more: the cap is applied when the manifest is requested, against
    // each output's own ladder, from what the mint filed under the token.
    expect($sources[0]['url'])->toContain("{$tall->ulid}.m3u8")
        ->and($sources[1]['url'])->toContain("{$short->ulid}.m3u8");

    $registry = app(ManifestOptionsRegistry::class);

    expect($registry->resolve(linkToken($sources[0]['url']))->resolution)->toBe(720)
        ->and($registry->resolve(linkToken($sources[1]['url']))->resolution)->toBe(720);
});

it('names the subtitle format in every manifest link and in no subtitle link', function () {
    $video = playVideo();
    playOutput($video);
    playSubtitle($video);

    $data = $this->postJson("/api/videos/{$video->ulid}/play", ['subtitlesFormat' => 'segments'])
        ->assertOk()->json('data');

    $registry = app(ManifestOptionsRegistry::class);

    foreach ($data['sources'] as $source) {
        expect($registry->resolve(linkToken($source['url']))->subtitlesFormat)->toBe(SubtitleFormat::SEGMENTS);
    }

    // The standalone file is one object behind its own signature; there is no manifest to build.
    expect($registry->resolve(linkToken($data['subtitles'][0]['url']))->isDefault())->toBeTrue();
});

it('never bills one viewer\'s bytes to another who minted in the same second', function () {
    // What is recorded against a token is looked up by its hash, so two identical tokens are one
    // entry: the later mint's tracking id answered for both viewers, and one `download` mint
    // turned the other's streaming into download bytes.
    $this->freezeTime();

    $video = playVideo();
    playOutput($video, formats: ['hls']);

    $first = $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'viewer-1'])->json('data.sources.0.url');
    $second = $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'viewer-2', 'download' => true])->json('data.sources.0.url');
    $anonymous = $this->postJson("/api/videos/{$video->ulid}/play")->json('data.sources.0.url');

    expect(linkToken($first))->not->toBe(linkToken($second))
        ->and(linkToken($anonymous))->not->toBe(linkToken($first));

    $hash = fn (string $url) => hash('sha256', linkToken($url));
    $found = app(TrackingRegistry::class)->lookupMany([$hash($first), $hash($second), $hash($anonymous)]);

    // The third mint named no viewer and is labelled by the caller's default, whatever that is —
    // the point is that it is neither of the other two's.
    expect($found['tracking'][$hash($first)])->toBe('viewer-1')
        ->and($found['tracking'][$hash($second)])->toBe('viewer-2')
        ->and($found['tracking'][$hash($anonymous)])->not->toBeIn(['viewer-1', 'viewer-2'])
        ->and($found['downloads'])->toBe([$hash($second) => true]);
});

it('never hands one viewer the manifest options of another who minted in the same second', function () {
    // A token is its expiry, its ACL and an HMAC of the two: two viewers minting the same video
    // within one second used to hold the very same token, so whatever the options were filed
    // under, the later mint answered for both.
    $this->freezeTime();

    $video = playVideo();
    playOutput($video, heights: [360, 1080], formats: ['hls']);

    $capped = $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 360])->json('data.sources.0.url');
    $plain = $this->postJson("/api/videos/{$video->ulid}/play")->json('data.sources.0.url');
    $segments = $this->postJson("/api/videos/{$video->ulid}/play", ['subtitlesFormat' => 'segments'])->json('data.sources.0.url');

    $registry = app(ManifestOptionsRegistry::class);

    expect($registry->resolve(linkToken($plain))->isDefault())->toBeTrue()
        ->and($registry->resolve(linkToken($capped))->resolution)->toBe(360)
        ->and($registry->resolve(linkToken($capped))->subtitlesFormat)->toBe(SubtitleFormat::VTT)
        ->and($registry->resolve(linkToken($segments))->resolution)->toBeNull()
        ->and($registry->resolve(linkToken($segments))->subtitlesFormat)->toBe(SubtitleFormat::SEGMENTS);
});

it('adds nothing to the token of a mint that asks nothing of its manifests', function (array $body) {
    $video = playVideo();
    playOutput($video);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", $body)->assertOk()->json('data.sources');
    $token = linkToken($sources[0]['url']);

    // The defaults need no key, and plain WebVTT subtitles are the default.
    expect($token)->not->toContain('~mo=')
        ->and(app(ManifestOptionsRegistry::class)->resolve($token)->subtitlesFormat)->toBe(SubtitleFormat::VTT);
})->with([
    'no body' => [[]],
    'the default spelled out' => [['subtitlesFormat' => 'vtt']],
]);

it('names the options in the token as an opaque key, never as their values', function () {
    $video = playVideo();
    playOutput($video, heights: [360, 1080], formats: ['hls']);

    $url = $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 360, 'subtitlesFormat' => 'segments'])
        ->json('data.sources.0.url');

    // The token's fields, by name: an expiry, an ACL, the opaque key, a nonce and the signature.
    // Nothing spells the options out — not as a field, and not in the query beside the token.
    preg_match_all('/(?:^|~)([a-z]+)=/', linkToken($url), $fields);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($fields[1])->toBe(['exp', 'acl', 'mo', 'n', 'hmac'])
        ->and(array_keys($query))->toBe(['__hdnea__'])
        ->and(linkToken($url))->toMatch('/~mo=[0-9a-f]{16}~n=[0-9a-f]{16}~hmac=/')
        ->and($url)->not->toContain('segments')
        ->and($url)->not->toContain('resolution');
});

it('names the options of a batch mint in every video\'s links', function () {
    $first = playVideo();
    $second = playVideo();
    playOutput($first, formats: ['dash']);
    playOutput($second, formats: ['dash']);

    $data = $this->postJson('/api/videos/play', [
        'videos' => [$first->ulid, $second->ulid],
        'subtitlesFormat' => 'segments',
    ])->assertOk()->json('data');

    $registry = app(ManifestOptionsRegistry::class);

    expect($registry->resolve(linkToken($data[0]['sources'][0]['url']))->subtitlesFormat)->toBe(SubtitleFormat::SEGMENTS)
        ->and($registry->resolve(linkToken($data[1]['sources'][0]['url']))->subtitlesFormat)->toBe(SubtitleFormat::SEGMENTS);
});

it('refuses a subtitle format it does not know', function () {
    $video = playVideo();
    playOutput($video);

    $this->postJson("/api/videos/{$video->ulid}/play", ['subtitlesFormat' => 'srt'])
        ->assertStatus(422)->assertJsonValidationErrors('subtitlesFormat');
});

it('refuses manifest options when links are not signed', function (array $body, string $field) {
    CdnSettings::fake(['token_secret' => '', 'token_window' => 3600]);

    $video = playVideo();
    playOutput($video, heights: [480, 720, 1080], formats: ['hls']);

    // The options ride in the token: with none, the manifests would come out with the defaults
    // whatever was asked, and the caller could not tell.
    $this->postJson("/api/videos/{$video->ulid}/play", $body)
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'a resolution cap' => [['resolution' => 720], 'resolution'],
    'the packaged subtitles' => [['subtitlesFormat' => 'segments'], 'subtitlesFormat'],
]);

it('still mints unsigned links that ask for the defaults', function () {
    CdnSettings::fake(['token_secret' => '', 'token_window' => 3600]);

    $video = playVideo();
    $output = playOutput($video, formats: ['hls']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", ['subtitlesFormat' => 'vtt'])->assertOk()->json('data.sources');

    expect($sources[0]['url'])->toEndWith("{$output->ulid}.m3u8");
});

it('attributes every link in the answer to the tracking id', function () {
    $video = playVideo();
    playOutput($video);
    playOutput($video, formats: ['dash']);

    $sources = $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'client-42'])
        ->assertOk()->json('data.sources');

    $registry = app(TrackingRegistry::class);
    $urls = collect($sources)->pluck('url');

    expect($urls)->toHaveCount(3);

    foreach ($urls as $url) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($registry->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');
    }
});

it('marks every link in the answer as an offline download only when asked to', function () {
    $video = playVideo();
    playOutput($video);
    playOutput($video, formats: ['dash']);

    $hashes = function (array $body) use ($video) {
        return collect($this->postJson("/api/videos/{$video->ulid}/play", $body)->assertOk()->json('data.sources'))
            ->map(function (array $source) {
                parse_str((string) parse_url($source['url'], PHP_URL_QUERY), $query);

                return hash('sha256', $query['__hdnea__']);
            })->all();
    };
    $marked = fn (array $hashes) => app(TrackingRegistry::class)->lookupMany($hashes)['downloads'];

    $offline = $hashes(['download' => true]);
    $this->travel(2)->seconds();
    $streamed = $hashes([]);
    $this->travel(2)->seconds();
    $explicit = $hashes(['download' => false]);

    expect($offline)->toHaveCount(3)
        ->and(array_keys($marked($offline)))->toEqualCanonicalizing(array_unique($offline))
        ->and($marked($streamed))->toBe([])
        ->and($marked($explicit))->toBe([]);
});

it('does not query per output', function () {
    $video = playVideo();
    foreach (range(1, 6) as $i) {
        playOutput($video, heights: [360 * $i]);
    }

    DB::enableQueryLog();
    $this->postJson("/api/videos/{$video->ulid}/play")->assertOk();
    $log = collect(DB::getQueryLog())->pluck('query');

    // The renditions come in one eager load and the video's own tracks in a second — subtitles
    // hang off the video, not off an output — and the fleet is resolved once for the request.
    expect($log->filter(fn ($q) => str_contains($q, '"streams"'))->count())->toBe(2)
        ->and($log->filter(fn ($q) => str_contains($q, '"nodes"'))->count())->toBe(1);
});

it('refuses the whole video while it is still processing', function () {
    $video = playVideo('running');
    playOutput($video);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertStatus(409);
});

it('answers 503 for the whole video when no node can serve it', function () {
    $video = playVideo();
    playOutput($video);

    Node::query()->update(['is_active' => false]);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertStatus(503);
});

it('does not hand another project a video of ours', function () {
    $video = playVideo();
    playOutput($video);

    $other = Project::factory()->for(User::factory()->create())->create();
    $this->withHeader('X-Project-Ulid', $other->ulid);

    $this->postJson("/api/videos/{$video->ulid}/play")->assertNotFound();
});

it('validates the body it takes', function () {
    $video = playVideo();
    playOutput($video);

    $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 12])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/play", ['ip' => 'nope'])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'no spaces'])->assertStatus(422);
    $this->postJson("/api/videos/{$video->ulid}/play", ['download' => 'maybe'])->assertStatus(422);
});

describe('many videos at once', function () {
    it('answers the playable videos in the order asked, and leaves the rest out', function () {
        $first = playVideo();
        playOutput($first);
        $second = playVideo();
        playOutput($second, formats: ['dash']);
        $processing = playVideo('processing');
        $foreign = Video::create([
            'user_id' => $this->user->id,
            'project_id' => Project::factory()->for($this->user)->create()->id,
            'name' => 'Theirs', 'duration' => 10, 'aspect_ratio' => '16:9', 'status' => 'completed',
        ]);
        playOutput($foreign);

        // Absent rather than reported: a video that is not this project's, not ready or not a
        // video at all is one the caller can tell from the list it sent.
        $data = $this->postJson('/api/videos/play', ['videos' => [
            $second->ulid, $processing->ulid, $foreign->ulid, (string) Str::ulid(), $first->ulid, $second->ulid,
        ]])->assertOk()->json('data');

        expect(array_column($data, 'videoUlid'))->toBe([$second->ulid, $first->ulid])
            ->and(count($data[0]['sources']))->toBe(1)
            ->and(count($data[1]['sources']))->toBe(2);
    });

    it('answers each video exactly as the single-video mint does', function () {
        $video = playVideo();
        playOutput($video, heights: [1080, 720]);

        $one = $this->postJson("/api/videos/{$video->ulid}/play", ['resolution' => 720])->assertOk()->json('data');
        $many = $this->postJson('/api/videos/play', ['videos' => [$video->ulid], 'resolution' => 720])->assertOk()->json('data.0');

        $shape = fn (array $answer) => [
            $answer['videoUlid'], $answer['thumbnailUrl'], $answer['storyboardUrl'],
            array_map(fn (array $s) => [parse_url($s['url'], PHP_URL_PATH), $s['format'], $s['outputUlid'], $s['videoCodec'], $s['audioCodec']], $answer['sources']),
        ];

        expect($shape($many))->toBe($shape($one));
    });

    it('attributes every link of every video to the tracking id, under one expiry', function () {
        $a = playVideo();
        playOutput($a);
        $b = playVideo();
        playOutput($b);

        $data = $this->postJson('/api/videos/play', ['videos' => [$a->ulid, $b->ulid], 'trackingId' => 'client-42', 'expiresIn' => 7200])
            ->assertOk()->json('data');

        $registry = app(TrackingRegistry::class);
        $urls = collect($data)->flatMap(fn (array $v) => array_column($v['sources'], 'url'));

        expect($urls)->toHaveCount(4)
            ->and(array_unique(array_column($data, 'expiresAt')))->toHaveCount(1);

        foreach ($urls as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            expect($registry->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');
        }
    });

    it('does not query per video', function () {
        $queries = function (int $count) {
            $ulids = [];
            foreach (range(1, $count) as $i) {
                $video = playVideo();
                playOutput($video, heights: [1080, 720], audioLanguages: ['en']);
                $ulids[] = $video->ulid;
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson('/api/videos/play', ['videos' => $ulids])->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // The first request of a test also resolves what a process resolves once; not per video.
        $queries(1);

        expect($queries(8))->toBe($queries(2));
    });

    it('answers 503 for the whole batch when no node can serve it', function () {
        $video = playVideo();
        playOutput($video);
        Node::query()->update(['is_active' => false]);

        $this->postJson('/api/videos/play', ['videos' => [$video->ulid]])->assertStatus(503);
    });

    it('validates the list it takes', function () {
        $this->postJson('/api/videos/play', [])->assertStatus(422)->assertJsonValidationErrors('videos');
        $this->postJson('/api/videos/play', ['videos' => []])->assertStatus(422)->assertJsonValidationErrors('videos');
        $this->postJson('/api/videos/play', ['videos' => ['short']])->assertStatus(422)->assertJsonValidationErrors('videos.0');
        $this->postJson('/api/videos/play', ['videos' => array_fill(0, PlayVideosData::MAX_VIDEOS + 1, (string) Str::ulid())])
            ->assertStatus(422)->assertJsonValidationErrors('videos');
        $this->postJson('/api/videos/play', ['videos' => [(string) Str::ulid()], 'expiresIn' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('expiresIn');
    });
});

it('hands out every subtitle as a standalone signed vtt', function () {
    $video = playVideo();
    playOutput($video);
    $english = playSubtitle($video, 'en');
    $spanish = playSubtitle($video, 'es', ['forced' => true, 'meta' => ['hearing_impaired' => true]]);

    $subtitles = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.subtitles');

    expect($subtitles)->toHaveCount(2)
        ->and(array_keys($subtitles[0]))
        ->toBe(['url', 'streamUlid', 'language', 'name', 'forced', 'hearingImpaired'])
        ->and(array_column($subtitles, 'streamUlid'))->toBe([$english->ulid, $spanish->ulid])
        ->and(array_column($subtitles, 'language'))->toBe(['en', 'es'])
        ->and($subtitles[0]['forced'])->toBeFalse()
        ->and($subtitles[0]['hearingImpaired'])->toBeFalse()
        ->and($subtitles[1]['forced'])->toBeTrue()
        ->and($subtitles[1]['hearingImpaired'])->toBeTrue();

    // The plain file of the download zone, not the packager segment next to the manifest, and
    // signed: a `<track>` cannot add a header, so the token has to be in the URL.
    expect($subtitles[0]['url'])
        ->toContain("edge.example.com/{$video->ulid}/download/subtitle/{$english->ulid}.vtt")
        ->toContain('hmac=');

    // One object each — a token that opened a sibling would be a directory token.
    expect($subtitles[0]['url'])->not->toContain('*')
        ->and($subtitles[0]['url'])->not->toBe($subtitles[1]['url']);
});

it('answers an empty subtitle list for a video that has none', function () {
    $video = playVideo();
    playOutput($video, audioLanguages: ['en']);

    $this->postJson("/api/videos/{$video->ulid}/play")
        ->assertOk()
        ->assertJsonPath('data.subtitles', []);
});

it('leaves out a subtitle whose file is not in the download zone', function () {
    $video = playVideo();
    playOutput($video);
    $ready = playSubtitle($video, 'en', ['status' => 'ready']);
    playSubtitle($video, 'es', ['status' => 'processing', 'file_size' => null]);
    playSubtitle($video, 'fr', ['status' => 'failed', 'file_size' => null]);

    $subtitles = $this->postJson("/api/videos/{$video->ulid}/play")->assertOk()->json('data.subtitles');

    expect(array_column($subtitles, 'streamUlid'))->toBe([$ready->ulid]);
});

it('carries each video\'s own subtitles in a batch mint', function () {
    $first = playVideo();
    playOutput($first);
    $subtitle = playSubtitle($first);
    $second = playVideo();
    playOutput($second);

    $data = $this->postJson('/api/videos/play', ['videos' => [$first->ulid, $second->ulid]])
        ->assertOk()->json('data');

    expect(array_column($data[0]['subtitles'], 'streamUlid'))->toBe([$subtitle->ulid])
        ->and($data[1]['subtitles'])->toBe([]);
});

it('attributes the subtitle links to the tracking id and honours the asked lifetime', function () {
    $video = playVideo();
    playOutput($video);
    playSubtitle($video);

    $data = $this->postJson("/api/videos/{$video->ulid}/play", ['trackingId' => 'client-42', 'expiresIn' => 7200])
        ->assertOk()->json('data');

    parse_str((string) parse_url($data['subtitles'][0]['url'], PHP_URL_QUERY), $query);

    expect(app(TrackingRegistry::class)->resolve(hash('sha256', $query['__hdnea__'])))->toBe('client-42');

    // The same expiry as the manifests it accompanies: a track that died first would vanish from
    // a session that is still playing.
    preg_match('/exp=(\d+)/', $query['__hdnea__'], $expiry);
    expect((int) $expiry[1])->toBe(Carbon\Carbon::parse($data['expiresAt'])->timestamp);
});
