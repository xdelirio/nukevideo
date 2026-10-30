<?php

/**
 * Adding an audio or subtitle track to a video that is already published: encode it, package it
 * on its own, and graft it into the live manifests without disturbing anything else. ffmpeg and
 * the packager are faked: each fake writes the files the real binary would, so the job's own file
 * handling, grafting and publishing all run for real against the fake S3 disk.
 */

use App\Enums\StreamStatus;
use App\Jobs\AddTrackJob;
use App\Jobs\DispatchWebhookJob;
use App\Models\Output;
use App\Models\Project;
use App\Models\Stream;
use App\Models\User;
use App\Services\TrackIngestService;
use App\Support\TrackProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('local');
    Queue::fake();

    $this->project = Project::factory()->for(User::factory())->create(['settings' => ['webhookUrl' => 'https://example.test/hook']]);
    $this->video = projectVideo($this->project);
    $this->video->template->update(['query' => ['outputs' => [[
        'variants' => [['width' => 1920, 'height' => 800]],
        'audio' => ['audio_codec' => 'aac', 'channels' => [
            ['channels' => '2', 'audio_bitrate' => '128k'],
            ['channels' => '6', 'audio_bitrate' => '384k'],
        ]],
    ]]]]);

    $this->output = Output::create(['video_id' => $this->video->id, 'template_index' => 0, 'status' => 'completed']);
    $this->output->recordFormats(['hls', 'dash']);

    $make = fn (string $type, array $attributes) => $this->video->streams()->create([
        'path' => "{$this->video->ulid}/{$type}/".Str::random(8).'.mp4',
        'type' => $type,
        'meta' => [],
        ...$attributes,
    ]);

    $this->videoStream = $make('video', ['width' => 1920, 'height' => 800, 'input_params' => ['video_codec' => 'libx264']]);
    $this->audio = $make('audio', ['name' => 'Espanol', 'language' => 'es-419', 'channels' => 2, 'input_params' => ['audio_codec' => 'aac', 'audio_bitrate' => '128k', 'channels' => '2']]);
    $this->subtitle = $make('subtitle', ['name' => 'English', 'language' => 'en']);
    $this->output->streams()->attach([$this->videoStream->id, $this->audio->id]);

    $ulids = ['{video}' => $this->videoStream->ulid, '{audio}' => $this->audio->ulid, '{sub}' => $this->subtitle->ulid];
    $this->hlsPath = "{$this->video->playPrefix()}/{$this->output->ulid}.m3u8";
    $this->dashPath = "{$this->video->playPrefix()}/{$this->output->ulid}.mpd";
    Storage::disk('s3')->put($this->hlsPath, str_replace('CODECS="av01.0.08M.08"', 'CODECS="av01.0.08M.08,mp4a.40.2"', hlsFixture($ulids)));
    Storage::disk('s3')->put($this->dashPath, dashFixture($ulids));

    $this->probe = ['channels' => 2, 'codec' => 'pcm_s16le', 'profile' => null, 'bit_rate' => null, 'duration' => 10.0];
    app()->instance(TrackProbe::class, new class($this) extends TrackProbe
    {
        public function __construct(private $test) {}

        public function probe(string $source, string $type): ?array
        {
            return $this->test->probe;
        }
    });

    $this->commands = new ArrayObject;
    $this->packagerFails = false;
    fakeTrackBinaries($this);

    /** A placeholder exactly as {@see TrackIngestService} leaves it. */
    $this->placeholder = function (string $type = 'audio', array $meta = []) {
        $key = 'tmp-videos/'.Str::ulid().($type === 'audio' ? '.wav' : '.srt');
        Storage::disk('s3')->put($key, 'upload');

        return $this->video->streams()->create([
            'path' => $key,
            'type' => $type,
            'status' => StreamStatus::PROCESSING,
            'name' => $type === 'audio' ? 'English dub' : 'Francais',
            'language' => $type === 'audio' ? 'en' : 'fr',
            'meta' => ['track_upload' => $key, 'track_name' => $type === 'audio' ? 'English dub' : 'Francais', ...$meta],
        ]);
    };

    $this->run = fn (Stream $stream) => (new AddTrackJob($stream->id, $this->video->id))->handle();
});

/** ffmpeg writes its last quoted path; the packager writes the segments and its throwaway manifest. */
function fakeTrackBinaries($test): void
{
    Process::fake(function ($process) use ($test) {
        $command = $process->command;
        $test->commands[] = $command;

        if (is_string($command) && str_starts_with($command, 'ffmpeg')) {
            preg_match_all('/"([^"]+)"/', $command, $m);
            $out = end($m[1]);
            @mkdir(dirname($out), 0755, true);
            file_put_contents($out, str_contains($command, 'webvtt') ? "WEBVTT\n\n00:00.000 --> 00:01.000\nBonjour\n" : 'encoded-audio');

            return Process::result('ok');
        }

        if ($test->packagerFails) {
            return Process::result(output: '', errorOutput: 'PACKAGER_FAILURE', exitCode: 1);
        }

        $descriptor = collect($command)->first(fn ($arg) => str_starts_with((string) $arg, 'in='));
        preg_match('#segment_template=(.+)/\$Number\$\.(m4s|vtt)#', $descriptor, $seg);
        $ulid = basename($seg[1]);
        @mkdir($seg[1], 0755, true);
        file_put_contents("{$seg[1]}/1.{$seg[2]}", 'segment');
        file_put_contents("{$seg[1]}/init.mp4", 'init');

        $hls = collect($command)->search('--hls_master_playlist_output');
        $dash = collect($command)->search('--mpd_output');
        $text = str_contains($descriptor, 'stream=text');

        if ($hls !== false) {
            file_put_contents($command[$hls + 1], $text
                ? "#EXTM3U\n#EXT-X-MEDIA:TYPE=SUBTITLES,URI=\"{$ulid}/index.m3u8\",GROUP-ID=\"subs\",LANGUAGE=\"fr\",NAME=\"Francais\",DEFAULT=NO,AUTOSELECT=YES"
                : "#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,URI=\"{$ulid}/index.m3u8\",GROUP-ID=\"audio\",LANGUAGE=\"en\",NAME=\"English dub\",DEFAULT=YES,AUTOSELECT=YES,CHANNELS=\"2\"\n#EXT-X-STREAM-INF:BANDWIDTH=134000,CODECS=\"mp4a.40.2\",AUDIO=\"audio\"\n{$ulid}/index.m3u8");
        }

        if ($dash !== false) {
            $type = $text ? 'text' : 'audio';
            file_put_contents($command[$dash + 1], <<<XML
                <?xml version="1.0" encoding="UTF-8"?>
                <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" type="static">
                  <Period id="0">
                    <AdaptationSet id="0" contentType="{$type}">
                      <Representation id="0" bandwidth="134000">
                        <SegmentTemplate initialization="{$ulid}/init.mp4" media="{$ulid}/\$Number\$.m4s" startNumber="1"/>
                      </Representation>
                    </AdaptationSet>
                  </Period>
                </MPD>
                XML);
        }

        return Process::result('ok');
    });
}

it('grafts a new audio into every manifest of its outputs and marks it ready', function () {
    $stream = ($this->placeholder)();

    ($this->run)($stream);

    $stream->refresh();
    $hls = Storage::disk('s3')->get($this->hlsPath);

    expect($stream->status)->toBe(StreamStatus::READY)
        ->and($stream->outputs->pluck('id')->all())->toBe([$this->output->id])
        ->and($stream->input_params)->toMatchArray(['audio_codec' => 'aac', 'audio_bitrate' => '128k', 'channels' => '2'])
        ->and($stream->path)->toStartWith("{$this->video->ulid}/audio/")
        ->and($stream->package_size)->toBeGreaterThan(0)
        ->and($hls)->toContain("URI=\"{$stream->ulid}/index.m3u8\"")
        ->and(Storage::disk('s3')->get($this->dashPath))->toContain("{$stream->ulid}/init.mp4")
        ->and(Storage::disk('s3')->exists("{$stream->segmentsPath($this->video)}/1.m4s"))->toBeTrue()
        // The upload is consumed; the template keeps no processed files by default.
        ->and(Storage::disk('s3')->exists($stream->meta['track_upload']))->toBeFalse();
});

it('creates one stream per rung for a 5.1 dub', function () {
    $this->probe['channels'] = 6;
    $stream = ($this->placeholder)();

    ($this->run)($stream);

    $added = Stream::where('video_id', $this->video->id)->where('type', 'audio')->whereKeyNot($this->audio->id)->orderBy('id')->get();

    expect($added)->toHaveCount(2)
        ->and($added->pluck('name')->all())->toBe(['English dub (Stereo)', 'English dub (5.1)'])
        ->and($added->pluck('meta.track_upload')->unique()->all())->toBe([$stream->meta['track_upload']])
        ->and($added->every(fn ($s) => $s->status === StreamStatus::READY))->toBeTrue();

    $hls = Storage::disk('s3')->get($this->hlsPath);
    expect(substr_count($hls, 'TYPE=AUDIO'))->toBe(3);
});

it('pads and trims audio to the video duration', function () {
    ($this->run)(($this->placeholder)());

    $ffmpeg = collect($this->commands)->first(fn ($c) => is_string($c) && str_starts_with($c, 'ffmpeg'));

    expect($ffmpeg)->toContain('apad=whole_dur=10.000')
        ->and($ffmpeg)->toContain('-t 10.000')
        ->and($ffmpeg)->toContain('-map 0:a:0');
});

it('rejects audio that is more than 10% off', function () {
    $this->probe['duration'] = 12.5;
    $before = [Storage::disk('s3')->get($this->hlsPath), Storage::disk('s3')->get($this->dashPath)];
    $stream = ($this->placeholder)();

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'check it is the right file');
    $job->failed(new RuntimeException('Audio is 12.5s long but the video is 10s; check it is the right file.'));

    expect($stream->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and($stream->fresh()->error_log)->toContain('right file')
        ->and([Storage::disk('s3')->get($this->hlsPath), Storage::disk('s3')->get($this->dashPath)])->toBe($before);
});

it('grafts a subtitle next to existing subtitles', function () {
    $stream = ($this->placeholder)('subtitle');

    ($this->run)($stream);

    $stream->refresh();
    $hls = Storage::disk('s3')->get($this->hlsPath);

    expect($stream->status)->toBe(StreamStatus::READY)
        ->and(substr_count($hls, 'TYPE=SUBTITLES'))->toBe(2)
        ->and($hls)->toContain("URI=\"{$stream->ulid}/index.m3u8\"")
        ->and(Storage::disk('s3')->get($this->dashPath))->toContain("{$stream->ulid}/init.mp4")
        // The standalone VTT is always kept: it is what a subtitle repair reads back.
        ->and(Storage::disk('s3')->exists($stream->storedPath($this->video)))->toBeTrue()
        ->and($stream->path)->toEndWith('.vtt');
});

it('leaves manifests untouched when the packager fails', function () {
    $this->packagerFails = true;
    $before = [Storage::disk('s3')->get($this->hlsPath), Storage::disk('s3')->get($this->dashPath)];
    $stream = ($this->placeholder)();

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('packager failed'));

    expect($stream->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and([Storage::disk('s3')->get($this->hlsPath), Storage::disk('s3')->get($this->dashPath)])->toBe($before);
});

it('stops when the video is no longer completed at publish time', function () {
    $before = Storage::disk('s3')->get($this->hlsPath);
    $stream = ($this->placeholder)();
    $this->video->update(['status' => 'running']);

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('The video stopped being completed.'));

    expect($stream->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and(Storage::disk('s3')->get($this->hlsPath))->toBe($before)
        ->and(Storage::disk('s3')->allFiles($stream->segmentsPath($this->video)))->toBe([]);
});

it('only touches DASH for an opus track on an HLS+DASH output', function () {
    $this->video->template->update(['query' => ['outputs' => [[
        'variants' => [['width' => 1920, 'height' => 800]],
        'audio' => ['audio_codec' => 'libopus', 'channels' => [['channels' => '2', 'audio_bitrate' => '96k']]],
    ]]]]);
    // A silent master: no audio to keep the codec from, so the template's Opus applies.
    $this->output->streams()->detach($this->audio->id);
    $before = Storage::disk('s3')->get($this->hlsPath);
    $stream = ($this->placeholder)();

    ($this->run)($stream);

    expect($stream->fresh()->status)->toBe(StreamStatus::READY)
        ->and(Storage::disk('s3')->get($this->hlsPath))->toBe($before)
        ->and(Storage::disk('s3')->get($this->dashPath))->toContain("{$stream->ulid}/init.mp4")
        ->and(collect($this->commands)->contains(fn ($c) => is_array($c) && in_array('--hls_master_playlist_output', $c, true)))->toBeFalse();
});

it('sends video.track_ready with the track ulids', function () {
    $stream = ($this->placeholder)();

    ($this->run)($stream);

    Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => ($p = $job->payload)['event'] === 'video.track_ready'
        && $p['tracks'] === [$stream->ulid]);
});

it('sends video.track_failed', function () {
    $stream = ($this->placeholder)();

    (new AddTrackJob($stream->id, $this->video->id))->failed(new RuntimeException('nope'));

    Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => ($p = $job->payload)['event'] === 'video.track_failed'
        && $p['tracks'] === [$stream->ulid]);
});

it('lets an added track be relabeled and deleted through the existing endpoints', function () {
    $stream = ($this->placeholder)();
    ($this->run)($stream);

    Sanctum::actingAs($this->project->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $this->putJson("/api/streams/{$stream->ulid}", ['name' => 'Doublage', 'language' => 'fr', 'forced' => false])->assertOk();
    expect(Storage::disk('s3')->get($this->hlsPath))->toContain('NAME="Doublage"');

    $this->deleteJson("/api/streams/{$stream->ulid}")->assertOk();
    expect(Storage::disk('s3')->get($this->hlsPath))->not->toContain($stream->ulid);
});

it('declares WithoutOverlapping per video', function () {
    $middleware = (new AddTrackJob(1, 7))->middleware();

    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('add-track:7');
});

it('does not duplicate rungs or suffixes when a 5.1 dub is retried', function () {
    $this->probe['channels'] = 6;
    $stream = ($this->placeholder)();

    // First attempt dies after the rungs exist, as a worker restart would leave them.
    $this->packagerFails = true;
    expect(fn () => ($this->run)($stream))->toThrow(RuntimeException::class);

    $this->packagerFails = false;
    ($this->run)($stream->fresh());

    $added = Stream::where('video_id', $this->video->id)->where('type', 'audio')->whereKeyNot($this->audio->id)->orderBy('id')->get();

    expect($added->pluck('name')->sort()->values()->all())->toBe(['English dub (5.1)', 'English dub (Stereo)'])
        ->and($added->every(fn ($s) => $s->status === StreamStatus::READY))->toBeTrue();
});

it('leaves a published track alone when failed() fires after it went ready', function () {
    $stream = ($this->placeholder)();
    ($this->run)($stream);
    $hls = Storage::disk('s3')->get($this->hlsPath);

    (new AddTrackJob($stream->id, $this->video->id))->failed(new RuntimeException('timeout after publish'));

    expect($stream->fresh()->status)->toBe(StreamStatus::READY)
        ->and($stream->fresh()->outputs)->toHaveCount(1)
        ->and(Storage::disk('s3')->exists("{$stream->segmentsPath($this->video)}/1.m4s"))->toBeTrue()
        ->and(Storage::disk('s3')->get($this->hlsPath))->toBe($hls);
});

it('accepts audio whose duration the probe could not read', function () {
    $this->probe['duration'] = null;
    $stream = ($this->placeholder)();

    ($this->run)($stream);

    expect($stream->fresh()->status)->toBe(StreamStatus::READY);
});
