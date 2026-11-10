<?php

/**
 * Priority only reorders PENDING videos: `videos:dispatch` offers them high → normal → low, oldest
 * first within each level, under the same per-family cap as before. It reaches the video from the
 * upload metadata, and through the update endpoint while the video is still waiting.
 */

use App\DTOs\UploadMeta;
use App\Enums\VideoStatus;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\OnVideoUploadedService;
use App\Services\UppyS3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    $this->template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    // Created in arrival order: the dispatcher walks each level by id.
    $this->makeVideo = function (string $name, string $priority, ?Template $template = null) {
        $video = Video::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'template_id' => ($template ?? $this->template)->id,
            'name' => $name,
            'duration' => 60,
            'aspect_ratio' => '16:9',
            'status' => 'pending',
            'priority' => $priority,
        ]);

        $video->streams()->create([
            'path' => "{$video->ulid}/source/original.mp4",
            'type' => 'original',
            'name' => 'Original',
            'meta' => [],
        ]);

        return $video;
    };

    $this->running = fn () => Video::where('status', VideoStatus::RUNNING->value)->pluck('name')->sort()->values()->all();
});

it('dispatches high before normal before low, oldest first within each level', function () {
    // One CPU node: three slots per tick.
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    ($this->makeVideo)('old-low', 'low');
    ($this->makeVideo)('old-normal', 'normal');
    ($this->makeVideo)('newer-normal', 'normal');
    ($this->makeVideo)('new-high', 'high');

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect(($this->running)())->toBe(['new-high', 'newer-normal', 'old-normal']);

    // Free the slots: a low only runs once nothing above it is waiting.
    Video::where('status', VideoStatus::RUNNING->value)->update(['status' => VideoStatus::COMPLETED->value]);
    Queue::fake();

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect(($this->running)())->toBe(['old-low']);
    Queue::assertPushed(PrepareVideoJob::class, 1);
});

it('does not let a high-priority video waiting on missing hardware hold back the CPU queue', function () {
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    $gpuTemplate = Template::create([
        'name' => 'GPU',
        'query' => ['outputs' => [['video_codec' => 'h264_nvenc', 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    ($this->makeVideo)('gpu-high', 'high', $gpuTemplate);
    ($this->makeVideo)('cpu-low', 'low');

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect(($this->running)())->toBe(['cpu-low'])
        ->and(Video::where('name', 'gpu-high')->value('status'))->toBe(VideoStatus::PENDING->value);
});

it('stops walking the backlog once every family is full', function () {
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    collect(range(1, 30))->each(fn (int $i) => ($this->makeVideo)("clip-{$i}", 'normal'));

    DB::enableQueryLog();
    $this->artisan('videos:dispatch')->assertSuccessful();

    // Once every slot is taken the loop returns, so the low level is never even queried.
    $queriedLow = collect(DB::getQueryLog())->contains(fn (array $query) => in_array('low', $query['bindings'], true));

    expect(($this->running)())->toBe(['clip-1', 'clip-2', 'clip-3'])
        ->and($queriedLow)->toBeFalse();
});

describe('on upload', function () {
    beforeEach(function () {
        $this->ingest = function (UploadMeta $meta): Video {
            $key = 'tmp-videos/'.Str::ulid().'.mkv';
            app(UppyS3Service::class)->storeUploadMeta($key, $meta);
            app(OnVideoUploadedService::class)->handle($key, 1024);

            return Video::where('name', $meta->filename)->sole();
        };

        $this->meta = fn (array $extra = []) => new UploadMeta(...[
            'user' => $this->user->ulid,
            'project' => $this->project->ulid,
            'template' => $this->template->ulid,
            'filename' => 'clip-'.Str::random(6).'.mkv',
            ...$extra,
        ]);
    });

    it('takes the priority from the upload metadata', function () {
        $video = ($this->ingest)(($this->meta)(['priority' => 'high']));

        expect($video->priority)->toBe('high');
    });

    it('defaults to normal when the upload names none', function () {
        $video = ($this->ingest)(($this->meta)());

        expect($video->priority)->toBe('normal');
    });

    it('defaults to normal for metadata cached before the field existed', function () {
        // What a deploy finds in the cache for an upload already in progress: the serialized
        // object has no `priority` at all, so it unserializes with the property uninitialized.
        $serialized = serialize(($this->meta)());
        $legacy = preg_replace('/s:8:"priority";N;/', '', $serialized);
        $legacy = preg_replace('/^O:(\d+):"App\\\\DTOs\\\\UploadMeta":7:/', 'O:$1:"App\\DTOs\\UploadMeta":6:', $legacy);

        $meta = unserialize($legacy);

        expect((new ReflectionProperty(UploadMeta::class, 'priority'))->isInitialized($meta))->toBeFalse();

        $video = ($this->ingest)($meta);

        expect($video->priority)->toBe('normal');
    });

    it('rejects a priority outside the enum', function () {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/s3/multipart', [
            'filename' => 'clip.mkv',
            'metadata' => [
                'project' => $this->project->ulid,
                'template' => $this->template->ulid,
                'priority' => 'urgent',
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.priority');
    });
});

it('changes the priority through the update endpoint', function () {
    $video = ($this->makeVideo)('clip', 'normal');

    Sanctum::actingAs($this->user);

    $this->withHeader('X-Project-Ulid', $this->project->ulid)
        ->patchJson("/api/videos/{$video->ulid}", ['name' => 'clip', 'priority' => 'low'])
        ->assertOk()
        ->assertJsonPath('data.priority', 'low');

    expect($video->fresh()->priority)->toBe('low');
});
