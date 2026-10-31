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
use App\Services\TrackPublisher;
use App\Support\TrackProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
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

    $this->run = fn (Stream $stream) => app()->call([new AddTrackJob($stream->id, $this->video->id), 'handle']);
});

/** ffmpeg writes its last quoted path; the packager writes the segments and its throwaway manifest. */
function fakeTrackBinaries($test): void
{
    Process::fake(function ($process) use ($test) {
        $command = $process->command;
        $test->commands[] = $command;

        if (is_string($command) && str_starts_with($command, 'ffmpeg')) {
            // Every output of the (possibly multi-output) run: `-f <format> "<path>"`.
            preg_match_all('/-f (\S+) "([^"]+)"/', $command, $m, PREG_SET_ORDER);
            foreach ($m as [, $format, $out]) {
                @mkdir(dirname($out), 0755, true);
                file_put_contents($out, $format === 'webvtt' ? ($test->vtt ?? "WEBVTT\n\n00:00.000 --> 00:01.000\nBonjour\n") : 'encoded-audio');
            }

            return Process::result('ok');
        }

        if (fakeS5cmdSync($command)) {
            return Process::result('ok');
        }

        if ($test->packagerFails) {
            return Process::result(output: '', errorOutput: 'PACKAGER_FAILURE', exitCode: 1);
        }

        // Lets a test act at the moment the packager runs, between the encode and the publish.
        if (isset($test->onPackage)) {
            ($test->onPackage)();
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
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'check it is the right file');
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
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('packager failed'));

    expect($stream->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and([Storage::disk('s3')->get($this->hlsPath), Storage::disk('s3')->get($this->dashPath)])->toBe($before);
});

it('stops when the video is no longer completed at publish time', function () {
    $before = Storage::disk('s3')->get($this->hlsPath);
    $stream = ($this->placeholder)();
    $this->video->update(['status' => 'running']);

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
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

it('refuses a ladder name another audio track of the video already uses', function () {
    // A source ladder named exactly as the dub's stereo rung would be.
    $this->audio->update(['name' => 'English dub (Stereo)']);
    $this->probe['channels'] = 6;
    $stream = ($this->placeholder)();

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'already named "English dub (Stereo)"');
    $job->failed(new RuntimeException('Another audio track of this video is already named "English dub (Stereo)".'));

    expect($stream->fresh()->status)->toBe(StreamStatus::FAILED);
});

it('fails every rung of a ladder together, keeping the rows and dropping the upload', function () {
    $this->probe['channels'] = 6;
    $this->packagerFails = true;
    $stream = ($this->placeholder)();
    $before = Storage::disk('s3')->get($this->hlsPath);

    $job = new AddTrackJob($stream->id, $this->video->id);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('packager failed'));

    $rungs = $this->video->streamsOfTrackUpload($stream->meta['track_upload']);

    expect($rungs)->toHaveCount(2)
        ->and($rungs->every(fn ($s) => $s->status === StreamStatus::FAILED && $s->error_log === 'packager failed'))->toBeTrue()
        ->and(Storage::disk('s3')->exists($stream->meta['track_upload']))->toBeFalse()
        ->and(Storage::disk('s3')->get($this->hlsPath))->toBe($before);

    Queue::assertPushed(DispatchWebhookJob::class, fn ($job) => $job->payload['event'] === 'video.track_failed'
        && count($job->payload['tracks']) === 2);
});

it('refuses to add a track to a video with no known duration', function () {
    $this->video->update(['duration' => 0]);
    $stream = ($this->placeholder)();

    expect(fn () => ($this->run)($stream))->toThrow(RuntimeException::class, 'no known duration');
});

it('refuses a subtitle file with no cues', function () {
    $this->vtt = "WEBVTT\n\n";
    $stream = ($this->placeholder)('subtitle');

    expect(fn () => ($this->run)($stream))->toThrow(RuntimeException::class, 'no cues');
});

it('stops quietly when the track is deleted while it is processed', function () {
    $stream = ($this->placeholder)();
    $before = Storage::disk('s3')->get($this->hlsPath);
    $this->onPackage = fn () => DB::table('streams')->where('id', $stream->id)->delete();

    ($this->run)($stream);

    expect(Storage::disk('s3')->get($this->hlsPath))->toBe($before)
        ->and(Storage::disk('s3')->allFiles($stream->segmentsPath($this->video)))->toBe([]);
    Queue::assertNotPushed(DispatchWebhookJob::class, fn ($job) => str_starts_with($job->payload['event'], 'video.track_'));
});

it('counts the whole track in the variants when the group peak is unknown (VBR)', function () {
    $this->audio->update(['input_params' => ['audio_codec' => 'aac', 'audio_vbr' => '4', 'channels' => '2']]);

    ($this->run)(($this->placeholder)());

    // The fake throwaway master declares 134000; the fixture variant 3050279.
    expect(Storage::disk('s3')->get($this->hlsPath))->toContain('BANDWIDTH='.(3050279 + 134000).',');
});

it('leaves no local scratch behind', function () {
    ($this->run)(($this->placeholder)());

    expect(Storage::disk('local')->allFiles('add-track'))->toBe([]);
});

it('never publishes what a killed attempt left in the scratch dir', function () {
    $stream = ($this->placeholder)();
    $stale = Storage::disk('local')->path(AddTrackJob::SCRATCH_DIR."/{$stream->ulid}/package/01STALERUNG0000000000000000");
    @mkdir($stale, 0755, true);
    file_put_contents("{$stale}/1.m4s", 'stale');
    file_put_contents(dirname($stale).'/_audio.mpd', 'stale throwaway');

    ($this->run)($stream);

    expect(Storage::disk('s3')->allFiles("{$this->video->playPrefix()}/01STALERUNG0000000000000000"))->toBe([])
        ->and(Storage::disk('s3')->exists("{$this->video->playPrefix()}/_audio.mpd"))->toBeFalse()
        ->and($stream->fresh()->status)->toBe(StreamStatus::READY);
});

it('fails a track whose job times out mid-publish, outside the transaction it dies in', function () {
    $stream = ($this->placeholder)();

    // The test's own transaction is level 1; the publish opened one more and is dying in it.
    DB::beginTransaction();
    (new ReflectionProperty(TrackPublisher::class, 'openTransactionLevel'))->setValue(null, DB::transactionLevel());
    $stream->update(['name' => 'written inside the dying publish']);

    (new AddTrackJob($stream->id, $this->video->id))->failed(new RuntimeException('timed out'));

    expect(DB::transactionLevel())->toBe(1)
        ->and($stream->fresh()->status)->toBe(StreamStatus::FAILED)
        ->and($stream->fresh()->name)->toBe('English dub');
});
