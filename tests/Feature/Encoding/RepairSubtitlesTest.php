<?php

use App\Data\Video\ManifestOptions;
use App\Enums\SubtitleFormat;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use App\Services\Manifest\SubtitleFormatTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * End-to-end over the real shaka binary: a video whose manifest lost every subtitle to one malformed
 * cue gets them back from the `.vtt` files still on S3, with no source and no mirror in play.
 */
function publishedWithoutSubtitles(): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'name' => 'Clip',
        'duration' => 20,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);

    $cues = [
        // The defect: a blank line inside the cue, which made shaka fail the whole run.
        'en' => "WEBVTT\n\n00:01.000 --> 00:05.000\n<b>Episode 2:\n\nFor Myself</b>\n\n00:06.000 --> 00:09.000\nbye\n",
        'es' => "WEBVTT\n\n00:01.000 --> 00:05.000\nhola\n\n00:06.000 --> 00:09.000\nadios\n",
    ];

    foreach ($cues as $language => $body) {
        $stream = Stream::create([
            'video_id' => $video->id,
            'type' => 'subtitle',
            'language' => $language,
            'name' => strtoupper($language),
            'forced' => false,
            'meta' => [],
            'path' => "{$video->ulid}/subtitle/".strtoupper((string) Str::ulid()).'.vtt',
        ]);

        Storage::disk('s3')->put($stream->storedPath($video), $body);
    }

    Storage::disk('s3')->put("{$video->playPrefix()}/OUTPUT.mpd", <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static" mediaPresentationDuration="PT20S" minBufferTime="PT2S" profiles="urn:mpeg:dash:profile:isoff-live:2011">
          <Period id="0">
            <AdaptationSet id="0" contentType="video" segmentAlignment="true">
              <Representation id="0" bandwidth="100000" codecs="avc1.640028" mimeType="video/mp4" width="640" height="360">
                <SegmentTemplate timescale="1000" initialization="VIDEO/init.mp4" media="VIDEO/$Number$.m4s" startNumber="1" duration="4000"/>
              </Representation>
            </AdaptationSet>
          </Period>
        </MPD>
        XML);

    return $video;
}

/** This suite drives the real binary; CI runners don't carry it, only the app image does. */
function packagerInstalled(): bool
{
    $bin = (string) config('packager.bin');

    return is_executable($bin) || trim((string) shell_exec('command -v '.escapeshellarg($bin).' 2>/dev/null')) !== '';
}

beforeEach(function () {
    if (! packagerInstalled()) {
        $this->markTestSkipped('shaka-packager is not installed here.');
    }

    Storage::fake('s3');
});

it('grafts the subtitles back into a manifest that lost them', function () {
    $video = publishedWithoutSubtitles();

    expect(Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd"))->not->toContain('contentType="text"');

    $this->artisan('videos:repair-subtitles', ['video' => [$video->ulid]])->assertSuccessful();

    $mpd = (string) Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd");

    // Both tracks are back — including the malformed one, which is the point of the repair.
    expect(substr_count($mpd, 'contentType="text"'))->toBe(2)
        ->and($mpd)->toContain('lang="en"')
        ->and($mpd)->toContain('lang="es"');

    foreach ($video->streams()->where('type', 'subtitle')->get() as $stream) {
        expect(Storage::disk('s3')->exists("{$stream->segmentsPath($video)}/init.mp4"))->toBeTrue()
            ->and(Storage::disk('s3')->exists("{$stream->segmentsPath($video)}/1.m4s"))->toBeTrue();
    }
});

it('publishes each repaired track as a plain sidecar the manifest can be pointed at', function () {
    $video = publishedWithoutSubtitles();

    $this->artisan('videos:repair-subtitles', ['video' => [$video->ulid]])->assertSuccessful();

    foreach ($video->streams()->where('type', 'subtitle')->get() as $stream) {
        $sidecar = (string) Storage::disk('s3')->get("{$stream->segmentsPath($video)}/sub.vtt");

        // The whole track as one file a `<track>` or a DASH text Representation loads as it is.
        expect($sidecar)->toStartWith('WEBVTT')->toContain('-->');
    }

    // The real packager's text sets, rewritten for a link that asked for plain WebVTT.
    $mpd = (new SubtitleFormatTransformer)->transform(
        (string) Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd"),
        'dash',
        new ManifestOptions(subtitlesFormat: SubtitleFormat::VTT),
    );

    expect(substr_count($mpd, '/sub.vtt</BaseURL>'))->toBe(2)
        ->and(substr_count($mpd, 'mimeType="text/vtt"'))->toBe(2)
        ->and($mpd)->not->toContain('wvtt')
        ->and(substr_count($mpd, 'contentType="text"'))->toBe(2)
        ->and($mpd)->toContain('VIDEO/init.mp4');
});

it('leaves a manifest that already carries subtitles alone', function () {
    $video = publishedWithoutSubtitles();

    $this->artisan('videos:repair-subtitles', ['video' => [$video->ulid]])->assertSuccessful();
    $repaired = Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd");

    $this->artisan('videos:repair-subtitles', ['video' => [$video->ulid]])->assertSuccessful();

    expect(Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd"))->toBe($repaired);
});

it('changes nothing on a dry run', function () {
    $video = publishedWithoutSubtitles();
    $before = Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd");

    $this->artisan('videos:repair-subtitles', ['video' => [$video->ulid], '--dry-run' => true])->assertSuccessful();

    expect(Storage::disk('s3')->get("{$video->playPrefix()}/OUTPUT.mpd"))->toBe($before);
});
