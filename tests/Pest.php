<?php

use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\Video;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** 1080p h264 8-bit 6 Mbps — hardware-decodable, so GPU renditions take the vpp_qsv path. */
const MATRIX_SOURCE = [
    'index' => 0,
    'source_codec' => 'h264',
    'source_pix_fmt' => 'yuv420p',
    'source_width' => 1920,
    'source_height' => 1080,
    'source_bit_rate' => 6_000_000,
    'source_fps' => 23.976,
];

/**
 * A persisted video belonging to a project, with the columns the schema insists on.
 *
 * Only User and Project have factories, so every test that needs a video was building one by hand.
 * There were three copies, already drifted on the template payload and on which argument came
 * second — and because Pest helpers are global, the third one was a fatal rather than a warning.
 */
function projectVideo(Project $project, string $name = 'clip', string $status = 'completed'): Video
{
    $template = Template::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'name' => $name.'-template',
        'query' => [],
    ]);

    return Video::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => $name,
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => $status,
    ]);
}

/** A video/audio stream with no database behind it, for the argument builders. */
function matrixStream(array $inputParams, string $type = 'video', array $meta = MATRIX_SOURCE, ?int $width = null, ?int $height = null): Stream
{
    $stream = (new Stream)->forceFill([
        'type' => $type,
        'width' => $width ?? $inputParams['width'] ?? 1280,
        'height' => $height ?? $inputParams['height'] ?? 720,
        'input_params' => $inputParams,
        'meta' => $meta,
    ]);
    $stream->id = 1;

    return $stream;
}

/** A cheap 1.26 Mbps 1080p h264 source: the staging case where a re-encode outgrew its source. */
const LIGHT_SOURCE = [
    'index' => 0,
    'source_codec' => 'h264',
    'source_pix_fmt' => 'yuv420p',
    'source_width' => 1920,
    'source_height' => 1080,
    'source_bit_rate' => 1_263_599,
];

/** The quality knob each codec steers with, as its template renders it. */
const QUALITY_KNOBS = [
    'libx264' => ['crf' => 23, 'flag' => '-crf 23'],
    'libx265' => ['crf' => 26, 'flag' => '-crf 26'],
    'libsvtav1' => ['svtav1_crf' => 30, 'flag' => '-crf 30'],
    'h264_qsv' => ['qsv_global_quality' => 23, 'flag' => '-global_quality 23'],
    'hevc_qsv' => ['qsv_global_quality' => 24, 'flag' => '-global_quality 24'],
    'av1_qsv' => ['qsv_global_quality' => 22, 'flag' => '-global_quality 22'],
    'h264_nvenc' => ['nvenc_cq' => 25, 'flag' => '-cq 25'],
    'av1_nvenc' => ['nvenc_cq' => 30, 'flag' => '-cq 30'],
];

/** A template in quality mode for `$codec`, plus whatever `$extra` the case is about. */
function qualityTemplate(string $codec, array $extra = []): array
{
    return ['video_codec' => $codec, 'gop_size' => 60, ...collect(QUALITY_KNOBS[$codec])->except('flag')->all(), ...$extra];
}

/** Every `-flag` in an argument string, in order. */
function matrixFlags(string $args): array
{
    preg_match_all('/(?:^|\s)(-[A-Za-z][\w:.-]*)/', $args, $matches);

    return $matches[1];
}

/**
 * Flags the codec may legitimately emit: the templates of the params `available_for` it, plus
 * the structural and forced-ABR ones the builder injects itself.
 */
function matrixAllowedFlags(string $codec, string $type): array
{
    $fromConfig = collect(config('ffmpeg.parameters'))
        ->filter(fn ($config) => ($config['type'] ?? null) === $type
            && in_array($codec, $config['available_for'] ?? [], true))
        ->pluck('template')
        ->filter()
        ->map(fn (string $template) => explode(' ', $template)[0])
        ->all();

    $forced = match (true) {
        $type === 'audio' => ['-c:a', '-map', '-vn'],
        $codec === 'libx264' => ['-sc_threshold', '-x264-params', '-threads'],
        $codec === 'libx265' => ['-x265-params'],
        $codec === 'libsvtav1' => ['-svtav1-params'],
        str_ends_with($codec, '_qsv') => ['-adaptive_i', '-mbbrc', '-extbrc', '-look_ahead_depth', '-b:v'],
        str_ends_with($codec, '_nvenc') => ['-no-scenecut', '-forced-idr', '-b:v'],
        default => [],
    };

    return array_merge($fromConfig, $forced, $type === 'audio' ? [] : ['-c:v', '-vf', '-map', '-an']);
}

/**
 * Manifest fixtures are trimmed copies of real shaka-packager v3.7.2 output — the attribute set,
 * spelling and nesting are what the packager actually emits, since that is exactly what the editor
 * has to match. `{audio}`/`{sub}`/`{video}` stand in for the stream ULIDs the segment paths are
 * keyed by ({@see Stream::segmentsPath}).
 */
function dashFixture(array $ulids): string
{
    return strtr(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <MPD xmlns="urn:mpeg:dash:schema:mpd:2011" profiles="urn:mpeg:dash:profile:isoff-live:2011" minBufferTime="PT2S" type="static" mediaPresentationDuration="PT10S">
          <Period id="0">
            <AdaptationSet id="0" contentType="video" maxWidth="1920" maxHeight="800" segmentAlignment="true">
              <Representation id="0" bandwidth="7679580" codecs="av01.0.08M.10.0.110.01.01.01.0" mimeType="video/mp4" width="1920" height="800">
                <SegmentTemplate timescale="24000" initialization="{video}/init.mp4" media="{video}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="240240"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
            <AdaptationSet id="1" contentType="audio" lang="es-419" segmentAlignment="true">
              <Label>Espanol</Label>
              <Representation id="1" bandwidth="146973" codecs="opus" mimeType="audio/mp4" audioSamplingRate="48000">
                <AudioChannelConfiguration schemeIdUri="urn:mpeg:dash:23003:3:audio_channel_configuration:2011" value="2"/>
                <SegmentTemplate timescale="48000" initialization="{audio}/init.mp4" media="{audio}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="480000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
            <AdaptationSet id="2" contentType="text" lang="en" segmentAlignment="true">
              <Role schemeIdUri="urn:mpeg:dash:role:2011" value="subtitle"/>
              <Label>English</Label>
              <Representation id="2" bandwidth="82" codecs="wvtt" mimeType="application/mp4">
                <SegmentTemplate timescale="1000" initialization="{sub}/init.mp4" media="{sub}/$Number$.m4s" startNumber="1">
                  <SegmentTimeline><S t="0" d="7490000"/></SegmentTimeline>
                </SegmentTemplate>
              </Representation>
            </AdaptationSet>
          </Period>
        </MPD>
        XML, $ulids);
}

function hlsFixture(array $ulids): string
{
    return strtr(<<<'M3U8'
        #EXTM3U
        ## Generated with https://github.com/shaka-project/shaka-packager version v3.7.2-64a6a6c-release

        #EXT-X-INDEPENDENT-SEGMENTS

        #EXT-X-MEDIA:TYPE=AUDIO,URI="{audio}/index.m3u8",GROUP-ID="audio",LANGUAGE="es-419",NAME="Espanol",DEFAULT=NO,AUTOSELECT=YES,CHANNELS="2"
        #EXT-X-MEDIA:TYPE=SUBTITLES,URI="{sub}/index.m3u8",GROUP-ID="subs",LANGUAGE="en",NAME="English",DEFAULT=NO,AUTOSELECT=YES

        #EXT-X-STREAM-INF:BANDWIDTH=3050279,AVERAGE-BANDWIDTH=3050279,CODECS="av01.0.08M.08",RESOLUTION=1920x800,FRAME-RATE=24.000,AUDIO="audio",SUBTITLES="subs",CLOSED-CAPTIONS=NONE
        {video}/index.m3u8
        M3U8, $ulids);
}

/**
 * Play an `s5cmd sync <local dir>/ s3://bucket/<prefix>/` against the faked `s3` disk, honouring
 * the `--include`/`--exclude` globs the typed sync passes ({@see SyncsViaS5cmd}).
 * Returns false for anything that is not such a sync, so a Process::fake closure can fall through.
 *
 * @param  string|list<string>  $command
 */
function fakeS5cmdSync(string|array $command): bool
{
    if (! is_array($command) || ($command[0] ?? null) !== 's5cmd' || ! in_array('sync', $command, true)) {
        return false;
    }

    $source = rtrim($command[count($command) - 2], '/');
    $dest = preg_replace('#^s3://[^/]+/#', '', rtrim(end($command), '/'));
    $include = [];
    $exclude = [];

    foreach ($command as $i => $arg) {
        if ($arg === '--include') {
            $include[] = $command[$i + 1];
        } elseif ($arg === '--exclude') {
            $exclude[] = $command[$i + 1];
        }
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $name = $file->getFilename();
        $wanted = ($include === [] || collect($include)->contains(fn ($glob) => fnmatch($glob, $name)))
            && ! collect($exclude)->contains(fn ($glob) => fnmatch($glob, $name));

        if ($wanted) {
            Storage::disk('s3')->put($dest.'/'.substr($file->getPathname(), strlen($source) + 1), file_get_contents($file->getPathname()));
        }
    }

    return true;
}

/**
 * A queue job for `$job->setJob()` that carries a payload, so code reading `retryUntil`,
 * `createdAt` or the attempt count off the real queue job can be exercised without Redis. The
 * framework's FakeJob answers an empty body, which reads as an old payload with none of them.
 *
 * @param  array<string, mixed>  $payload
 */
function fakeQueueJob(array $payload = [], int $attempts = 1): FakeJob
{
    $job = new class($payload) extends FakeJob
    {
        public function __construct(private array $body) {}

        public function getRawBody()
        {
            return json_encode($this->body);
        }
    };

    $job->attempts = $attempts;

    return $job;
}
