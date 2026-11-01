<?php

use App\Models\Node;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Cdn\SelfHostedProvider;
use App\Settings\CdnSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These tests exist because the storage layout IS the authorization boundary. The edge validates an
 * Akamai token by stripping the trailing `*` off the ACL and comparing the rest against the request
 * URI as a raw byte prefix — no globbing, no awareness of `/` — so whatever directory a manifest
 * sits in is exactly how far every playback link reaches.
 *
 * That is what let a playback token reach the full-quality masters, and it is the one property that
 * silently comes back if a manifest ever moves out of the play zone.
 */
function fakeSelfHostedSettings(): void
{
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => [
            'self_hosted' => [
                'token_secret' => bin2hex('secret'),
                'token_window' => 3600,
            ],
            'bunny' => [],
        ],
    ]);
}

function bindIpSettings(): void
{
    CdnSettings::fake([
        'provider' => 'self_hosted',
        'providers' => [
            'self_hosted' => ['token_secret' => bin2hex('secret'), 'token_window' => 3600, 'bind_ip' => true],
            'bunny' => [],
        ],
    ]);
}

/** The ACL the edge would enforce, with the trailing `*` stripped: everything it authorizes starts with this. */
function signedAclPrefix(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('__hdnea__');

    preg_match('/acl=([^~]+)/', $query['__hdnea__'], $matches);

    expect($matches)->not->toBeEmpty();

    return rtrim($matches[1], '*');
}

function signedVideo(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);

    return [$video, Output::create(['video_id' => $video->id, 'status' => 'completed'])];
}

function aclOf(Video $video, string $path): string
{
    return signedAclPrefix(
        app(SelfHostedProvider::class)->manifestUrl($video, $path, '1.2.3.4', true)->url
    );
}

beforeEach(function () {
    fakeSelfHostedSettings();

    Node::create([
        'name' => 'edge',
        'user' => 'root',
        'ip_address' => '10.0.0.1',
        'hostname' => 'edge.example.com',
        'type' => 'proxy',
        'is_active' => true,
    ]);
});

it('scopes a playback token to the play zone alone', function () {
    [$video, $output] = signedVideo();

    expect(aclOf($video, $output->manifestPath('dash')))->toBe("/{$video->ulid}/play/");
});

it('never authorizes the masters, the original or the images with a playback token', function () {
    [$video, $output] = signedVideo();

    $acl = aclOf($video, $output->manifestPath('dash'));

    // The edge compares the ACL as a byte prefix, so "not authorized" is literally "does not start with".
    $forbidden = [
        "/{$video->downloadPrefix()}/video/MASTER.mp4",
        "/{$video->downloadPrefix()}/audio/TRACK.mp4",
        "/{$video->downloadPrefix()}/subtitle/TRACK.vtt",
        "/{$video->originalPrefix()}/ORIGINAL.mkv",
        "/{$video->assetsPrefix()}/thumbnail.jpg",
    ];

    foreach ($forbidden as $key) {
        expect(str_starts_with($key, $acl))->toBeFalse("playback token must not reach {$key}");
    }
});

it('still reaches the segment dirs, which is what the trailing wildcard is for', function () {
    [$video, $output] = signedVideo();

    $stream = Stream::create([
        'video_id' => $video->id,
        'type' => 'video',
        'path' => "{$video->ulid}/video/RENDITION.mp4",
        'meta' => [],
    ]);

    $acl = aclOf($video, $output->manifestPath('dash'));

    expect(str_starts_with("/{$stream->segmentsPath($video)}/seg-1.m4s", $acl))->toBeTrue();
});

it('pins why the manifest may never sit at the video root again', function () {
    [$video, $output] = signedVideo();

    // The old layout: manifest directly under `{ulid}/`. Signing one from there widens the ACL to
    // the whole video prefix, which is exactly the leak the zones close — so if a refactor ever
    // moves a manifest back up, this is what fails.
    $acl = aclOf($video, "{$video->ulid}/".$output->manifestFile('dash'));

    expect($acl)->toBe("/{$video->ulid}/")
        ->and(str_starts_with("/{$video->downloadPrefix()}/video/MASTER.mp4", $acl))->toBeTrue()
        ->and(str_starts_with("/{$video->originalPrefix()}/ORIGINAL.mkv", $acl))->toBeTrue();
});

it('refuses to sign a bucket-wide ACL', function () {
    [$video] = signedVideo();

    app(SelfHostedProvider::class)->manifestUrl($video, 'manifest.mpd', '1.2.3.4', true);
})->throws(InvalidArgumentException::class);

describe('the token handed back with a link', function () {
    it('is the __hdnea__ value byte for byte, and hashes to the tracking key', function () {
        [$video, $output] = signedVideo();

        // The ingest will hash the `__hdnea__` value it reads off the edge log; the mint recorded
        // its mapping under the hash of THIS token, so the two must be the same bytes — the whole
        // `exp=…~acl=…~hmac=…` string, not the hmac alone.
        $link = app(SelfHostedProvider::class)->manifestUrl($video, $output->manifestPath('dash'), '1.2.3.4', false);

        parse_str((string) parse_url($link->url, PHP_URL_QUERY), $query);

        expect($link->token)->toBe($query['__hdnea__'])
            ->and($link->tokenHash)->toBe(hash('sha256', $query['__hdnea__']))
            ->and(parse_url($link->url, PHP_URL_PATH))->toStartWith("/{$video->ulid}/play/");
    });

    it('is null when the edge signs nothing', function () {
        CdnSettings::fake([
            'provider' => 'self_hosted',
            'providers' => ['self_hosted' => ['token_secret' => '', 'token_window' => 3600], 'bunny' => []],
        ]);
        [$video, $output] = signedVideo();

        $link = app(SelfHostedProvider::class)->manifestUrl($video, $output->manifestPath('dash'), '1.2.3.4', false);

        expect($link->token)->toBeNull()
            ->and($link->tokenHash)->toBeNull()
            ->and($link->url)->not->toContain('__hdnea__');
    });
});

describe('the address signed into a playback link', function () {
    it('is left out unless the operator binds links to it, since the edge enforces any it finds', function () {
        [$video, $output] = signedVideo();

        $link = app(SelfHostedProvider::class)->manifestUrl($video, $output->manifestPath('dash'), '203.0.113.7', false);

        expect($link->token)->not->toContain('ip=');
    });

    it('is written the way the edge writes the viewer, since the edge compares the two as strings', function (string $given, string $signed) {
        bindIpSettings();
        [$video, $output] = signedVideo();

        $link = app(SelfHostedProvider::class)->manifestUrl($video, $output->manifestPath('dash'), $given, false);

        // nginx renders $remote_addr compressed and lowercase, and its IPv4 listener never sees
        // a mapped address: with binding on, anything else was a 403 for the very viewer it named.
        expect($link->token)->toContain("~ip={$signed}~hmac=");
    })->with([
        'IPv4 as given' => ['203.0.113.7', '203.0.113.7'],
        'IPv6 expanded and uppercase' => ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'],
        'IPv4-mapped IPv6' => ['::ffff:203.0.113.7', '203.0.113.7'],
    ]);

    it('is left out of a download even when binding is on', function () {
        bindIpSettings();
        [$video] = signedVideo();

        $link = app(SelfHostedProvider::class)->downloadUrl($video->ulid, "{$video->ulid}/download/video/720.mp4", false);

        expect($link->token)->not->toContain('~ip=');
    });
});
