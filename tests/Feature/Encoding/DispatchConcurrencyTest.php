<?php

/**
 * One video already fans out across every worker — that is what chunking buys. So the cap on how
 * many videos run at once is not about parallelism, it is about how deep the shared FIFO queue
 * gets: dispatch several together and the shortest waits out the longest one's entire fan-out,
 * with nothing beating its heartbeat, until `videos:reap` mistakes the wait for a dead worker.
 */

use App\Enums\VideoStatus;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $this->makeVideo = function (string $status = 'pending') use ($user, $project) {
        $video = Video::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'template_id' => $this->template->id,
            'name' => 'Clip',
            'duration' => 60,
            'aspect_ratio' => '16:9',
            'status' => $status,
        ]);

        $video->streams()->create([
            'path' => "{$video->ulid}/source/original.mp4",
            'type' => 'original',
            'name' => 'Original',
            'meta' => [],
        ]);

        return $video;
    };
});

it('dispatches two videos per node of the family, plus one', function () {
    // One CPU node in the fixture, so three: one encoding, one preparing behind it, one spare.
    collect(range(1, 5))->each(fn () => ($this->makeVideo)());

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertPushed(PrepareVideoJob::class, 3);
    expect(Video::where('status', VideoStatus::RUNNING->value)->count())->toBe(3)
        ->and(Video::where('status', VideoStatus::PENDING->value)->count())->toBe(2);
});

it('counts what is already in flight against the same cap', function () {
    ($this->makeVideo)('running');
    ($this->makeVideo)('running');
    ($this->makeVideo)('uploading');
    ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('grows with the fleet instead of waiting for someone to edit a config', function () {
    // Adding hardware should widen the pipeline on the next tick. A fixed number drifts silently:
    // nodes sit idle until somebody remembers to raise it and redeploy.
    collect(range(1, 3))->each(fn (int $i) => Node::create([
        'name' => "cpu-{$i}", 'ip_address' => "10.0.1.{$i}", 'type' => 'worker',
    ]));

    collect(range(1, 12))->each(fn () => ($this->makeVideo)());

    $this->artisan('videos:dispatch')->assertSuccessful();

    // Four CPU nodes now, so nine.
    Queue::assertPushed(PrepareVideoJob::class, 9);
});

it('counts only the nodes of that video\'s own hardware', function () {
    // GPU nodes never drain the CPU queue, so they add nothing to what CPU work can be in flight.
    collect(range(1, 5))->each(fn (int $i) => Node::create([
        'name' => "gpu-{$i}", 'ip_address' => "10.0.2.{$i}", 'type' => 'worker', 'accel' => 'intel',
    ]));

    collect(range(1, 6))->each(fn () => ($this->makeVideo)());

    $this->artisan('videos:dispatch')->assertSuccessful();

    // Still one CPU node, so still three.
    Queue::assertPushed(PrepareVideoJob::class, 3);
});

it('dispatches nothing at all while no worker node is active', function () {
    Node::query()->update(['is_active' => false]);

    ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('takes the oldest waiting video first', function () {
    ($this->makeVideo)('running');   // fill the family's slots but one
    ($this->makeVideo)('running');

    // Arrival is insertion order: the dispatcher walks by id, and nothing ever re-inserts a video.
    $older = ($this->makeVideo)();
    $newer = ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect($older->fresh()->status)->toBe('running')
        ->and($newer->fresh()->status)->toBe('pending');
});

it('does not let a busy GPU family hold back the CPU queue', function () {
    // A fleet with both kinds of hardware, and the queues are independent: a GPU node never drains
    // the CPU queue. Counted fleet-wide, one GPU video in flight left every CPU node idle with work
    // waiting behind it.
    Node::create(['name' => 'gpu-01', 'ip_address' => '10.0.0.21', 'type' => 'worker', 'accel' => 'intel']);

    $gpuTemplate = Template::create([
        'name' => 'QSV',
        'query' => ['outputs' => [['video_codec' => 'av1_qsv', 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $this->template->user_id,
        'project_id' => $this->template->project_id,
    ]);

    ($this->makeVideo)('running')->update(['template_id' => $gpuTemplate->id]);
    $cpuVideo = ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertExitCode(0);

    Queue::assertPushed(PrepareVideoJob::class, fn ($job) => $job->videoId === $cpuVideo->id);
    expect($cpuVideo->refresh()->status)->toBe('running');
});

it('still holds a video back when its own family is full', function () {
    // One CPU node means three slots; all taken.
    ($this->makeVideo)('running');
    ($this->makeVideo)('running');
    ($this->makeVideo)('running');
    $waiting = ($this->makeVideo)();

    // Same family as the one already in flight, so the cap applies exactly as before.
    $this->artisan('videos:dispatch')->assertExitCode(0);

    expect($waiting->refresh()->status)->toBe('pending');
});
