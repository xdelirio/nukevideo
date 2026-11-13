<?php

/**
 * The endpoint the edge asks for a master manifest. It sits on the path of every playback start,
 * and it is reachable from an internet-facing container, so the two things pinned here are what
 * it answers when it knows nothing about a link — the manifest as packaged, never an error — and
 * what it can be made to read: one video's play zone, and nothing else in the bucket.
 */

use App\Data\Video\ManifestOptions;
use App\Enums\SubtitleFormat;
use App\Services\Cdn\ManifestOptionsRegistry;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const MANIFEST_VIDEO = '01M6ABMRKRYYEF1SHGYAZSMWHV';
const MANIFEST_OUTPUT = '01M6ABN3XYJW8S8MY7Q02PE82Z';
const MANIFEST_SUB = '01M6ABN3Y5AAAAAAAAAAAAAAEN';

function manifestMpd(): string
{
    return dashFixture(['{video}' => 'VIDEO', '{audio}' => 'AUDIO', '{sub}' => MANIFEST_SUB]);
}

function twoRungMaster(): string
{
    return "#EXTM3U\n"
        ."#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=640x360\nLOW/index.m3u8\n"
        ."#EXT-X-STREAM-INF:BANDWIDTH=2,RESOLUTION=1920x1080\nHIGH/index.m3u8\n";
}

/** Mint-side: a token naming `$options`, as the signer writes it. */
function tokenWith(ManifestOptions $options): string
{
    $key = app(ManifestOptionsRegistry::class)->keyFor($options);

    return 'exp=9999999999~acl=/'.MANIFEST_VIDEO.'/play/*'.($key === null ? '' : "~mo={$key}").'~hmac='.bin2hex(random_bytes(8));
}

function askManifest(string $file, ?string $token = null, string $video = MANIFEST_VIDEO)
{
    // The test client keeps its default headers between requests, so the token of an earlier
    // call has to be dropped explicitly for a request that carries none.
    $client = test()->withToken('internal-secret');
    $client = $token === null ? $client->withoutHeader('X-Link-Token') : $client->withHeader('X-Link-Token', $token);

    return $client->get("/api/internal/manifests/{$video}/{$file}");
}

beforeEach(function () {
    Storage::fake('s3');
    CdnSettings::fake(['token_secret' => bin2hex('secret'), 'token_window' => 3600]);
    config(['nuke.internal.secret' => 'internal-secret']);

    Storage::disk('s3')->put(MANIFEST_VIDEO.'/play/'.MANIFEST_OUTPUT.'.mpd', manifestMpd());
    Storage::disk('s3')->put(MANIFEST_VIDEO.'/play/'.MANIFEST_OUTPUT.'.m3u8', twoRungMaster());
});

it('refuses anyone who is not the edge', function () {
    $this->get('/api/internal/manifests/'.MANIFEST_VIDEO.'/'.MANIFEST_OUTPUT.'.mpd')->assertForbidden();

    $this->withToken('wrong')->get('/api/internal/manifests/'.MANIFEST_VIDEO.'/'.MANIFEST_OUTPUT.'.mpd')->assertForbidden();
});

it('answers with the defaults for a link that asked for nothing', function (?string $token) {
    // Plain WebVTT is what a link gets without asking.
    expect(askManifest(MANIFEST_OUTPUT.'.mpd', $token)->assertOk()->getContent())
        ->toContain('<BaseURL>/'.MANIFEST_VIDEO.'/play/'.MANIFEST_SUB.'/sub.vtt</BaseURL>')
        ->not->toContain('wvtt');
})->with([
    'no token header' => [null],
    'an empty token' => [''],
    'a token nobody minted' => ['exp=1~acl=/x/*~hmac=unknown'],
]);

it('types each format exactly, for the edge to rewrite it', function () {
    expect(askManifest(MANIFEST_OUTPUT.'.mpd')->headers->get('Content-Type'))->toBe('application/dash+xml')
        ->and(askManifest(MANIFEST_OUTPUT.'.m3u8')->headers->get('Content-Type'))->toBe('application/vnd.apple.mpegurl')
        ->and(askManifest(MANIFEST_OUTPUT.'.mpd')->headers->get('Cache-Control'))->toContain('no-store')
        ->and(askManifest(MANIFEST_OUTPUT.'.mpd')->headers->has('Content-Encoding'))->toBeFalse();
});

it('builds the manifest the link asked for', function () {
    $token = tokenWith(new ManifestOptions(resolution: 360, subtitlesFormat: SubtitleFormat::SEGMENTS));

    // The packaged subtitles, because this link asked for them: the default would be the file.
    expect(askManifest(MANIFEST_OUTPUT.'.mpd', $token)->assertOk()->getContent())
        ->toContain('codecs="wvtt"')
        ->not->toContain('sub.vtt');

    expect(askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->assertOk()->getContent())
        ->toContain('LOW/index.m3u8')
        ->not->toContain('HIGH/index.m3u8');

    // Another viewer's link, minted without options, is not handed this one's manifest.
    expect(askManifest(MANIFEST_OUTPUT.'.m3u8')->getContent())->toContain('HIGH/index.m3u8');
});

it('still serves a capped manifest a link minted before this named', function () {
    Storage::disk('s3')->put(MANIFEST_VIDEO.'/play/'.MANIFEST_OUTPUT.'.720.m3u8', twoRungMaster());

    askManifest(MANIFEST_OUTPUT.'.720.m3u8')->assertOk();
});

it('answers 404 for a manifest the bucket does not have', function () {
    askManifest('01M6ABN3XYJW8S8MY7Q02PEZZZ.mpd')->assertNotFound();
});

it('cannot be pointed outside a video play zone', function (string $path) {
    Storage::disk('s3')->put(MANIFEST_VIDEO.'/download/secret.mpd', 'secret');
    Storage::disk('s3')->put('other.mpd', 'secret');

    $response = $this->withToken('internal-secret')->get("/api/internal/manifests/{$path}");

    expect($response->status())->toBe(404)
        ->and($response->getContent())->not->toContain('secret');
})->with([
    MANIFEST_VIDEO.'/..%2Fdownload%2Fsecret.mpd',
    MANIFEST_VIDEO.'/%2E%2E%2F%2E%2E%2Fother.mpd',
    MANIFEST_VIDEO.'/'.MANIFEST_SUB.'%2Findex.m3u8',
    MANIFEST_VIDEO.'/thumbnail.jpg',
    'short/'.MANIFEST_OUTPUT.'.mpd',
]);

it('answers from the bucket as it is right now, leaving the caching to the edge', function () {
    $token = tokenWith(new ManifestOptions(resolution: 360));

    expect(askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->getContent())->not->toContain('TINY');

    // A rung is added under the cap. The very next request shows it: a copy kept on this side
    // too would add its own half minute to the one the edge already keeps.
    Storage::disk('s3')->put(
        MANIFEST_VIDEO.'/play/'.MANIFEST_OUTPUT.'.m3u8',
        twoRungMaster()."#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=426x240\nTINY/index.m3u8\n",
    );

    expect(askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->getContent())->toContain('TINY/index.m3u8');

    Storage::disk('s3')->delete(MANIFEST_VIDEO.'/play/'.MANIFEST_OUTPUT.'.m3u8');
    askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->assertNotFound();
});

it('gives each set of options its own manifest, in whatever order they are asked', function () {
    $capped = tokenWith(new ManifestOptions(resolution: 360));
    $sameCap = tokenWith(new ManifestOptions(resolution: 360));
    $full = tokenWith(new ManifestOptions);

    // One viewer's cap is never served to a viewer who did not ask for it, and the other way
    // round; two who asked for the same thing get the same bytes, which is what lets the edge
    // keep one copy for both.
    $bodies = [];
    foreach ([$capped, $full, $sameCap, $full, $capped] as $token) {
        $body = askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->assertOk()->getContent();
        $bodies[] = $body;

        expect(str_contains($body, 'HIGH/index.m3u8'))->toBe($token === $full);
    }

    expect($bodies[0])->toBe($bodies[2])->toBe($bodies[4]);
});

it('carries nothing of the viewer in what it builds', function () {
    $token = tokenWith(new ManifestOptions(resolution: 360));

    $body = askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->getContent().askManifest(MANIFEST_OUTPUT.'.mpd', $token)->getContent();

    // The token is the edge's to write in, per viewer, after this: a token in a shared copy would
    // be one viewer's link handed to the next.
    expect($body)->not->toContain('hmac=')->not->toContain('__hdnea__')->not->toContain('exp=');
});

it('touches no database to answer', function () {
    $token = tokenWith(new ManifestOptions(resolution: 360));

    DB::enableQueryLog();
    askManifest(MANIFEST_OUTPUT.'.m3u8', $token)->assertOk();

    expect(DB::getQueryLog())->toBe([]);
});
