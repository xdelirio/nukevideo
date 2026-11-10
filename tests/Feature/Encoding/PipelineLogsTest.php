<?php

use App\Jobs\PackageVideoJob;
use App\Jobs\PrepareVideoJob;
use App\Models\Project;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Phase 0 of the worker-capacity design: what a stuck or slow video leaves behind. Video 17621
 * timed out inside its fan-out and left no line saying so; every gate after this one needs these
 * numbers as a baseline.
 */
describe('timed-out jobs', function () {
    it('logs a timed-out job with its class, queue, uuid and attempts', function () {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(PrepareVideoJob::class);
        $job->shouldReceive('getQueue')->andReturn('orchestration');
        $job->shouldReceive('uuid')->andReturn('u-1');
        $job->shouldReceive('attempts')->andReturn(2);

        Log::spy();

        event(new JobTimedOut('redis', $job, 1800));

        Log::shouldHaveReceived('warning')->once()->with('Queue job timed out', [
            'class' => PrepareVideoJob::class,
            'queue' => 'orchestration',
            'uuid' => 'u-1',
            'attempts' => 2,
            'timeout' => 1800,
        ]);
    });

    it('never throws from the timeout listener', function () {
        // It runs inside the SIGALRM handler, right before the worker kills itself: an exception
        // there would replace the timeout with a confusing crash.
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andThrow(new RuntimeException('payload unreadable'));

        // Caught by hand: Pest's negated toThrow() passes whenever the class differs, and reads
        // an interface name such as Throwable as an exception MESSAGE.
        $thrown = null;

        try {
            event(new JobTimedOut('redis', $job, 1800));
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeNull();
    });
});

describe('source fetch', function () {
    beforeEach(function () {
        Storage::fake('s3');
        Storage::fake('chunks');
        Process::fake();
    });

    function fetchSource(PrepareVideoJob $job, $video): void
    {
        $mirrorPath = $video->sourceMirrorPath('mp4');
        $localPath = Storage::disk('local')->path($mirrorPath);

        (fn () => $this->ensureLocalSource($video, $mirrorPath, $localPath))->call($job);
    }

    it('logs a first fetch from primary S3 with its bytes and seconds', function () {
        $video = projectVideo(Project::factory()->create(), status: 'running');
        Storage::disk('s3')->put('uploads/original.mp4', str_repeat('x', 1234));

        Log::spy();

        fetchSource(new PrepareVideoJob($video->id, 'uploads/original.mp4'), $video);

        Log::shouldHaveReceived('info')->with('Source fetch started', [
            'video' => $video->id,
            'from' => 's3',
            'bytes' => 1234,
        ]);
        Log::shouldHaveReceived('info')->with('Source fetched', Mockery::on(fn (array $context) => $context['from'] === 's3'
            && $context['bytes'] === 1234
            && is_float($context['s'])
            && $context['attempt'] === 1));
    });

    it('logs a redelivery that reads the LAN mirror instead', function () {
        $video = projectVideo(Project::factory()->create(), status: 'running');
        Storage::disk('chunks')->put($video->sourceMirrorPath('mp4'), str_repeat('x', 777));

        Log::spy();

        fetchSource(new PrepareVideoJob($video->id, 'uploads/original.mp4'), $video);

        Log::shouldHaveReceived('info')->with('Source fetch started', [
            'video' => $video->id,
            'from' => 'mirror',
            'bytes' => 777,
        ]);
    });
});

it('logs the planned preparation with its step timings', function () {
    $video = projectVideo(Project::factory()->create(), status: 'running');
    $job = new PrepareVideoJob($video->id, 'uploads/original.mp4');

    Log::spy();

    (fn () => $this->logPlanned($video, 10, 40, ['probe_s' => 12.5, 'plan_s' => 3.0, 'fanout_s' => 1.2], 42))->call($job);

    Log::shouldHaveReceived('info')->with('Preparation planned', [
        'video' => $video->id,
        'chunks' => 10,
        'jobs' => 40,
        'attempt' => 1,
        'claim_wait_s' => 42,
        'probe_s' => 12.5,
        'plan_s' => 3.0,
        'fanout_s' => 1.2,
    ]);
});

it('measures how long the preparation waited in the queue', function () {
    Carbon\Carbon::setTestNow('2026-11-06 12:00:00');
    $job = new PrepareVideoJob(1, 'uploads/original.mp4');
    $job->setJob(fakeQueueJob(['createdAt' => now()->subSeconds(95)->getTimestamp()]));

    expect((fn () => $this->claimWaitSeconds())->call($job))->toBe(95);
});

it('has no queue wait to report outside a queue', function () {
    expect((fn () => $this->claimWaitSeconds())->call(new PrepareVideoJob(1, 'uploads/original.mp4')))->toBeNull();
});

it('logs a package sync with its bytes, seconds and concurrent syncs', function () {
    Process::fake();
    $video = projectVideo(Project::factory()->create(), status: 'uploading');

    $gather = Storage::disk('local')->path("tests/{$video->ulid}/gather");
    File::ensureDirectoryExists("{$gather}/stream");
    file_put_contents("{$gather}/stream/seg_1.m4s", str_repeat('x', 1000));
    file_put_contents("{$gather}/master.mpd", str_repeat('x', 2000));

    Log::spy();

    try {
        (fn () => $this->syncToPrimary($video, $gather, null))->call(new PackageVideoJob($video->id));
    } finally {
        File::deleteDirectory(dirname($gather));
    }

    Log::shouldHaveReceived('info')->with('Package synced', Mockery::on(fn (array $context) => $context['video'] === $video->id
        && $context['bytes'] === 3000
        && is_float($context['s'])
        && $context['concurrent'] === 1));
});
