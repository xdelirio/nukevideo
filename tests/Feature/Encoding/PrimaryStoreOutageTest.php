<?php

/**
 * 2026-11-08..10: the primary store rejected uploads and cut downloads short for a day and a half.
 * Thirteen videos failed, seven of them with every chunk encoded, and the dispatcher went on
 * claiming new ones the whole time. An outage must cost a wait, not the video.
 */

use App\Exceptions\StoreTransferException;
use App\Jobs\PackageVideoJob;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\User;
use App\Support\PrimaryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function waitForPrimaryStore(PackageVideoJob $job, StoreTransferException $e): bool
{
    return (fn () => $this->waitForPrimaryStore($e))->call($job);
}

it('releases the package job when the primary store is what failed', function () {
    $job = new PackageVideoJob(1);
    $job->setJob($queued = new FakeJob);

    $waited = waitForPrimaryStore($job, new StoreTransferException('s3', 'SignatureDoesNotMatch'));

    expect($waited)->toBeTrue()
        ->and($queued->isReleased())->toBeTrue()
        ->and(PrimaryStore::unavailable())->toBeTrue()
        ->and(PrimaryStore::reason())->toBe('SignatureDoesNotMatch');
});

it('lets a broken mirror fail the job as before', function () {
    $job = new PackageVideoJob(1);
    $job->setJob($queued = new FakeJob);

    $waited = waitForPrimaryStore($job, new StoreTransferException('chunks', 'connection refused'));

    expect($waited)->toBeFalse()
        ->and($queued->isReleased())->toBeFalse()
        ->and(PrimaryStore::unavailable())->toBeFalse();
});

it('stops waiting on its last attempt, so an outage that never ends still settles the video', function () {
    $job = new PackageVideoJob(1);
    $queued = new class extends FakeJob
    {
        public function attempts()
        {
            return 5;
        }
    };
    $job->setJob($queued);

    expect(waitForPrimaryStore($job, new StoreTransferException('s3', 'still down')))->toBeFalse()
        ->and($queued->isReleased())->toBeFalse();
});

it('claims nothing while the primary store is marked unavailable', function () {
    Queue::fake();
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    $video = projectVideo(Project::factory()->create(['user_id' => User::factory()->create()->id]), 'waiting', 'pending');
    $video->streams()->create(['path' => "{$video->ulid}/source/original.mp4", 'type' => 'original', 'name' => 'Original', 'meta' => []]);

    PrimaryStore::markUnavailable('SignatureDoesNotMatch');
    $this->artisan('videos:dispatch');

    expect($video->fresh()->status)->toBe('pending');
    Queue::assertNotPushed(PrepareVideoJob::class);
});
