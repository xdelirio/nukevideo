<?php

use App\Jobs\PackageVideoJob;
use App\Jobs\PrepareVideoJob;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Video;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Video 17621: the preparation's 1800s timeout fired inside `Bus::batch()->dispatch()`, after the
 * framework had committed the batch row (total_jobs 0) and before any job was pushed. The
 * redelivery saw a row, took the fan-out for done and skipped; the reaper failed the video and
 * `videos:prune` deleted it with its source a week later. A batch row only proves a fan-out once
 * it holds jobs, and only every expected queue's batch together proves the video encoded.
 */
beforeEach(fn () => Storage::fake('chunks'));

/** @param  list<string>  $codecs */
function recoveringVideo(array $codecs = ['libsvtav1']): Video
{
    $video = projectVideo(Project::factory()->create(), status: 'running');

    foreach ($codecs as $codec) {
        $video->streams()->create([
            'path' => "{$video->ulid}/video/".Str::ulid().'.mp4',
            'type' => 'video',
            'width' => 1920,
            'height' => 1080,
            'input_params' => $codec === 'libsvtav1'
                ? ['video_codec' => 'libsvtav1', 'svtav1_crf' => 26, 'svtav1_preset' => 6, 'gop_size' => 48]
                : ['video_codec' => $codec, 'qsv_global_quality' => 22, 'gop_size' => 48],
            'meta' => [],
        ]);
    }

    return $video;
}

function encodeBatchRow(Video $video, string $queue, int $totalJobs, bool $finished = false, bool $cancelled = false): void
{
    DB::table('job_batches')->insert([
        'id' => (string) Str::ulid(),
        'name' => "encode video {$video->id} {$queue}",
        'total_jobs' => $totalJobs,
        'pending_jobs' => $finished ? 0 : $totalJobs,
        'failed_jobs' => 0,
        'failed_job_ids' => '[]',
        'options' => serialize([]),
        'created_at' => now()->timestamp,
        'cancelled_at' => $cancelled ? now()->timestamp : null,
        'finished_at' => $finished ? now()->timestamp : null,
    ]);
}

function prepFor(Video $video): PrepareVideoJob
{
    return new PrepareVideoJob($video->id, 'uploads/original.mp4');
}

function fanOutComplete(Video $video): bool
{
    return (fn () => $this->fanOutComplete($video))->call(prepFor($video));
}

function fanOutWindows(Video $video, array $windows = [[0.0, 15.0], [15.0, 30.0]]): bool
{
    return (fn () => $this->fanOut($video, $windows, 'mirror/source.mp4'))->call(prepFor($video));
}

/** The `then()` gate the fan-out registered on the batch of one queue. */
function gateOf(Video $video, string $queue): Closure
{
    $gate = null;

    Bus::assertBatched(function (PendingBatch $batch) use ($video, $queue, &$gate) {
        if ($batch->name === "encode video {$video->id} {$queue}") {
            $gate = $batch->thenCallbacks()[0]->getClosure();
        }

        return true;
    });

    return $gate;
}

it('fans out again when the only batch row is empty (video 17621)', function () {
    $video = recoveringVideo();
    encodeBatchRow($video, 'video-processing', 0);

    expect(fanOutComplete($video))->toBeFalse();

    Bus::fake();
    fanOutWindows($video);

    Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === "encode video {$video->id} video-processing"
        && $batch->jobs->count() === 2);
    expect(DB::table('job_batches')->where('total_jobs', 0)->count())->toBe(0);
});

it('skips a redelivery once every queue has a batch with jobs', function () {
    $video = recoveringVideo();
    encodeBatchRow($video, 'video-processing', 40);

    expect(fanOutComplete($video))->toBeTrue();
});

it('is not complete while a second hardware queue has no batch', function () {
    $video = recoveringVideo(['libsvtav1', 'av1_qsv']);
    encodeBatchRow($video, 'video-processing', 40);

    expect(fanOutComplete($video))->toBeFalse();
});

it('fans out only the queue whose batch is missing', function () {
    $video = recoveringVideo(['libsvtav1', 'av1_qsv']);
    $cpu = $video->streams()->get()->first(fn (Stream $s) => $s->encodeQueue() === 'video-processing');
    encodeBatchRow($video, 'video-processing', 40);
    $video->update(['chunk_count' => 7]);
    $cpuPlan = "{$video->chunksDir()}/{$cpu->ulid}.plan";
    Storage::disk('chunks')->put($cpuPlan, 'the-first-attempt-plan');

    Bus::fake();
    fanOutWindows($video);

    Bus::assertBatchCount(1);
    Bus::assertBatched(fn (PendingBatch $batch) => $batch->name === "encode video {$video->id} video-processing-intel"
        && $batch->jobs->count() === 2);
    // The CPU rendition belongs to the batch already out: its plan and the window count stay.
    expect(Storage::disk('chunks')->get($cpuPlan))->toBe('the-first-attempt-plan')
        ->and($video->fresh()->chunk_count)->toBe(7);
});

it('does not package while an expected queue has no finished batch', function () {
    $video = recoveringVideo(['libsvtav1', 'av1_qsv']);

    Bus::fake();
    fanOutWindows($video);
    $gate = gateOf($video, 'video-processing-intel');

    // The intel batch finished; the CPU one never got its row (the prep died between the two).
    encodeBatchRow($video, 'video-processing-intel', 2, finished: true);
    $gate();

    expect($video->fresh()->status)->toBe('running');
    Bus::assertNotDispatched(PackageVideoJob::class);

    encodeBatchRow($video, 'video-processing', 2, finished: true);
    $gate();

    expect($video->fresh()->status)->toBe('uploading');
    Bus::assertDispatched(PackageVideoJob::class);
});

it('does not package when an expected queue only has a cancelled batch', function () {
    $video = recoveringVideo(['libsvtav1', 'av1_qsv']);

    Bus::fake();
    fanOutWindows($video);
    $gate = gateOf($video, 'video-processing');

    encodeBatchRow($video, 'video-processing', 2, finished: true);
    encodeBatchRow($video, 'video-processing-intel', 2, finished: true, cancelled: true);
    $gate();

    expect($video->fresh()->status)->toBe('running');
    Bus::assertNotDispatched(PackageVideoJob::class);
});
