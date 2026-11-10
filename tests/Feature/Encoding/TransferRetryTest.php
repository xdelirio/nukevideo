<?php

use App\Exceptions\StoreTransferException;
use App\Jobs\Concerns\SyncsViaS5cmd;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * The primary store rejects requests in bursts (2026-11-08..10: `SignatureDoesNotMatch` on a few
 * segment PUTs per sync, `unexpected EOF` on source downloads). One rejected object must cost a
 * second run of the same command, not the job — and never a video that is already encoded.
 */
function transferHarness(): object
{
    return new class
    {
        use SyncsViaS5cmd;

        public function sync(Video $video, int $timeout = 1680): void
        {
            $this->s5cmdSync('s3', '/tmp/gather/', 's3://bucket/play/', $video, $timeout);
        }

        public function copy(Video $video, int $timeout = 1800): void
        {
            $this->s5cmdCopy('s3', 's3://bucket/tmp-videos/a.mkv', '/tmp/original.mkv', $video, $timeout);
        }
    };
}

function transferVideo(): Video
{
    $user = User::factory()->create();

    return projectVideo(Project::factory()->create(['user_id' => $user->id]), 'transfer', 'uploading');
}

it('runs a rejected sync again instead of failing the job', function () {
    $runs = 0;

    Process::fake(function () use (&$runs) {
        return ++$runs < 3
            ? Process::result(errorOutput: 'ERROR "cp a.m4s": SignatureDoesNotMatch status code: 403', exitCode: 1)
            : Process::result();
    });

    transferHarness()->sync(transferVideo());

    expect($runs)->toBe(3);
    Sleep::assertSleptTimes(7); // 5 s, then 30 s in 5 s slices
});

it('gives up once every attempt was rejected, with the store\'s own words', function () {
    Process::fake(fn () => Process::result(errorOutput: 'SignatureDoesNotMatch', exitCode: 1));

    expect(fn () => transferHarness()->sync(transferVideo()))
        ->toThrow(RuntimeException::class, 'SignatureDoesNotMatch');

    Process::assertRanTimes(fn ($process) => in_array('sync', $process->command, true), 4);
});

it('does not retry when the budget left could not hold another attempt', function () {
    Process::fake(fn () => Process::result(errorOutput: 'unexpected EOF', exitCode: 1));

    expect(fn () => transferHarness()->sync(transferVideo(), timeout: 60))
        ->toThrow(RuntimeException::class, 'unexpected EOF');

    Process::assertRanTimes(fn ($process) => in_array('sync', $process->command, true), 1);
});

it('downloads a source again after the store cut it short', function () {
    $runs = 0;

    Process::fake(function () use (&$runs) {
        return ++$runs === 1
            ? Process::result(errorOutput: 'unexpected EOF', exitCode: 1)
            : Process::result();
    });

    transferHarness()->copy(transferVideo());

    expect($runs)->toBe(2);
});

it('names the disk that failed, so a job can tell the provider from the mirror', function () {
    Process::fake(fn () => Process::result(errorOutput: 'SignatureDoesNotMatch', exitCode: 1));

    try {
        transferHarness()->sync(transferVideo());
    } catch (StoreTransferException $e) {
        expect($e->disk)->toBe('s3');

        return;
    }

    $this->fail('The transfer did not throw');
});
