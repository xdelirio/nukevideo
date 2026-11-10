<?php

use App\Jobs\PrepareVideoJob;
use App\Jobs\ProcessChunkJob;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * When memory does run out, the kernel kills whatever scores highest — by size, which made it a
 * coin toss between an ffmpeg, a Horizon worker and RustFS (the 9950x nearly lost storage_2 and
 * worker_2 that way). Each encode now volunteers: chunks at the top score, probes half-way, so the
 * victim is always one ffmpeg, and a killed one costs a retry rather than a counted failure.
 */
beforeEach(function () {
    Storage::fake('chunks');
    Storage::disk('chunks')->buildTemporaryUrlsUsing(fn (string $path) => "http://mirror.test/{$path}");
});

function oomVideo(string $codec = 'libsvtav1', float $duration = 60): Video
{
    $video = projectVideo(Project::factory()->create(), status: 'running');
    $video->update(['duration' => $duration]);
    $video->streams()->create([
        'path' => "{$video->ulid}/video/".Str::ulid().'.mp4',
        'type' => 'video',
        'width' => 1280,
        'height' => 720,
        'input_params' => ['video_codec' => $codec, 'svtav1_crf' => 30, 'svtav1_preset' => 8, 'gop_size' => 48],
        'meta' => [],
    ]);

    return $video;
}

function chunkJobFor(Video $video): ProcessChunkJob
{
    $stream = $video->streams()->where('type', 'video')->first();

    return new ProcessChunkJob($stream->id, 'mirror/source.mp4', 0, 0.0, 15.0);
}

/** Runs the chunk's encode alone: what reaches ffmpeg, not the upload around it. */
function encodeChunk(ProcessChunkJob $job, Video $video): bool
{
    $stream = $video->streams()->where('type', 'video')->first();

    return (fn () => $this->encode($stream, 'http://mirror.test/source.mp4', '/scratch/chunk.part', $video, collect(), 15.0))->call($job);
}

/** The oom_score_adj a command raises itself to, or null when it runs bare. */
function oomScoreOf(PendingProcess $process): ?int
{
    return is_string($process->command) && preg_match("/^sh -c 'echo (\\d+) >\\/proc\\/self\\/oom_score_adj/", $process->command, $match)
        ? (int) $match[1]
        : null;
}

it('starts chunk ffmpeg with an oom score of 1000', function () {
    Process::fake();
    $video = oomVideo();

    encodeChunk(chunkJobFor($video), $video);

    // The command's own words follow the script, parsed by the shell exactly as before.
    Process::assertRan(fn (PendingProcess $process) => oomScoreOf($process) === 1000
        && str_contains($process->command, 'exec "$0" "$@"\' ffmpeg -hide_banner'));
});

it('runs chunk ffmpeg unwrapped when CHUNK_OOM_SCORE_ADJ is 0', function () {
    config(['nuke.video.chunk_oom_score_adj' => 0]);
    Process::fake();
    $video = oomVideo();

    encodeChunk(chunkJobFor($video), $video);

    Process::assertRan(fn (PendingProcess $process) => str_starts_with($process->command, 'ffmpeg '));
    Process::assertDidntRun(fn (PendingProcess $process) => oomScoreOf($process) !== null);
});

it('starts probe ffmpeg with an oom score of 500', function () {
    Process::fake();
    $video = oomVideo();
    $job = new PrepareVideoJob($video->id, 'o.mp4');

    try {
        (fn () => $this->resolveRenditions($video, '/scratch/source.mp4'))->call($job);
    } catch (RuntimeException) {
        // The faked preflight writes nothing and is refused afterwards; only its command matters here.
    }

    Process::assertRan(fn (PendingProcess $process) => oomScoreOf($process) === 500);
});

it('releases a chunk whose ffmpeg was SIGKILLed instead of counting an exception', function () {
    Process::fake(['*' => Process::result(exitCode: 137)]);
    $video = oomVideo();
    $job = chunkJobFor($video)->withFakeQueueInteractions();

    Log::spy();

    $job->handle();

    $job->assertReleased(30);
    Log::shouldHaveReceived('warning')->with('Chunk ffmpeg killed by SIGKILL (likely OOM)', Mockery::on(fn (array $context) => $context['chunk'] === 0
        && $context['attempt'] === 1));
});

it('fails a chunk OOM-killed on its last attempt', function () {
    Process::fake(['*' => Process::result(exitCode: 137)]);
    $video = oomVideo();
    $job = chunkJobFor($video)->withFakeQueueInteractions();
    $job->job->attempts = 5;

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Chunk OOM-killed 5 times');
    $job->assertNotReleased();
});
