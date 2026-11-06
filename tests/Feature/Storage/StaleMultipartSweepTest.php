<?php

/**
 * {@see PruneScratchJob} aborts a multipart upload only once it can no longer be continued, which
 * is when its metadata expires ({@see UppyS3Service::META_TTL_DAYS}). An upload older than a day is
 * often still alive — paused overnight, or its client waiting hours on its own source — and
 * aborting it at 24 h left the client resuming an upload S3 no longer had.
 */

use App\Jobs\PruneScratchJob;
use App\Services\UppyS3Service;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('aborts only the multipart uploads past the metadata window', function () {
    Storage::fake('local');
    Storage::fake('chunks');

    $aborted = [];
    $s3 = new MockHandler;
    config(['filesystems.disks.s3.handler' => $s3]);
    Storage::forgetDisk('s3');

    $s3->append(
        new Result(['Contents' => []]), // the finished uploads under the folder: none
        new Result(['Uploads' => [
            ['Key' => 'tmp-videos/paused.mkv', 'UploadId' => 'paused', 'Initiated' => now()->subDays(2)],
            ['Key' => 'tmp-videos/dead.mkv', 'UploadId' => 'dead', 'Initiated' => now()->subDays(UppyS3Service::META_TTL_DAYS + 1)],
        ]]),
        function (CommandInterface $command) use (&$aborted) {
            $aborted[] = $command['UploadId'];

            return new Result([]);
        },
    );

    app(PruneScratchJob::class)->handle(app(UppyS3Service::class));

    expect($aborted)->toBe(['dead'])
        ->and($s3->count())->toBe(0);
});
