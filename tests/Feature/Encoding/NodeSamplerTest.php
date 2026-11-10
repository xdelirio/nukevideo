<?php

use App\Models\Node;
use App\Models\Project;
use App\Services\Concerns\EmitsHeartbeat;
use App\Support\NodeSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Phase 0e: what each node's memory did, minute by minute, so the sizing changes that follow can be
 * gated on measured headroom instead of a benchmark run on a drained machine.
 */

/** A fake filesystem root holding the four files the sampler reads. */
function nodeRoot(int $anon = 2147483648, int $oomKills = 3, string $load = '5.50 4.00 3.00 2/900 1234', int $availableKb = 30000000, int $totalKb = 65536000): string
{
    $root = sys_get_temp_dir().'/node-'.Str::random(8);

    File::ensureDirectoryExists("{$root}/sys/fs/cgroup");
    File::ensureDirectoryExists("{$root}/proc");
    file_put_contents("{$root}/sys/fs/cgroup/memory.stat", "file 1000\nanon {$anon}\nkernel 500\n");
    file_put_contents("{$root}/sys/fs/cgroup/memory.events", "low 0\nhigh 0\nmax 2\noom 3\noom_kill {$oomKills}\n");
    file_put_contents("{$root}/proc/loadavg", $load."\n");
    file_put_contents("{$root}/proc/meminfo", "MemTotal:       {$totalKb} kB\nMemFree:         1000 kB\nMemAvailable:   {$availableKb} kB\n");

    return $root;
}

beforeEach(function () {
    config(['nuke.node_id' => 7]);
    Carbon::setTestNow('2026-11-06 12:00:10');
});

it('records anon, oom kills, load and available memory under the node and minute', function () {
    NodeSampler::sample(nodeRoot());

    expect(Cache::get('fleet-sample:7:202611061200'))->toMatchArray([
        'anon_max' => 2147483648,
        'oom_kill' => 3,
        'load1_max' => 5.5,
        'mem_available_min' => 30000000 * 1024,
        'mem_total' => 65536000 * 1024,
    ]);
});

it('samples at most once per 30 seconds per node', function () {
    NodeSampler::sample(nodeRoot(anon: 1000));

    Carbon::setTestNow('2026-11-06 12:00:20');
    NodeSampler::sample(nodeRoot(anon: 9000));

    expect(Cache::get('fleet-sample:7:202611061200')['anon_max'])->toBe(1000);
});

it('keeps the maximum anon and the minimum available memory within a minute', function () {
    NodeSampler::sample(nodeRoot(anon: 9000, availableKb: 500));

    Carbon::setTestNow('2026-11-06 12:00:41');
    NodeSampler::sample(nodeRoot(anon: 1000, availableKb: 800, oomKills: 4));

    expect(Cache::get('fleet-sample:7:202611061200'))->toMatchArray([
        'anon_max' => 9000,
        'mem_available_min' => 500 * 1024,
        // A counter: the latest reading is the largest.
        'oom_kill' => 4,
    ]);
});

it('does nothing on a host without a node id', function () {
    config(['nuke.node_id' => null]);

    NodeSampler::sample(nodeRoot());

    expect(Cache::get('fleet-sample:7:202611061200'))->toBeNull();
});

it('never throws when the cgroup files are missing', function () {
    $empty = sys_get_temp_dir().'/node-empty-'.Str::random(8);
    File::ensureDirectoryExists($empty);

    NodeSampler::sample($empty);

    expect(Cache::get('fleet-sample:7:202611061200'))->not->toHaveKey('anon_max');
});

it('never throws when the cache fails', function () {
    Cache::shouldReceive('add')->andThrow(new RuntimeException('redis went away'));

    // A worker's heartbeat runs this; a cache flap must cost a sample, never the job.
    $thrown = null;

    try {
        NodeSampler::sample(nodeRoot());
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();
});

it('samples the node when a job beats the heartbeat', function () {
    $video = projectVideo(Project::factory()->create(), status: 'running');
    $beater = new class
    {
        use EmitsHeartbeat;

        public function beat($video): void
        {
            $this->heartbeat($video);
        }
    };

    $beater->beat($video);

    // The real /proc of the test container: at least the load average is there to read.
    expect(Cache::get('fleet-sample:7:202611061200'))->toHaveKey('load1_max');
});

it('records queue lengths each dispatch tick', function () {
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect(Cache::get('fleet-sample:queues:202611061200')['lengths'] ?? null)
        ->toMatchArray(['orchestration' => 0, 'packaging' => 0, 'video-processing' => 0]);
});

it('reports max and p95 anon, oom kills and idle minutes per node', function () {
    Node::create(['name' => 'epyc', 'ip_address' => '10.0.0.13', 'type' => 'worker']);
    $node = Node::where('name', 'epyc')->first();
    $total = 100 * 1024 ** 3;

    // 20 minutes: anon climbs 46% → 90% of RAM in steps of 2.2 points (rounded), oom kills 3 → 5,
    // and the first 5 minutes sit idle (load under 10% of 32 cores).
    foreach (range(0, 19) as $i) {
        $minute = Carbon::parse('2026-11-06 11:41')->addMinutes($i)->format('YmdHi');
        Cache::put("fleet-sample:{$node->id}:{$minute}", [
            'anon_max' => (int) round($total * (0.46 + 0.44 * $i / 19)),
            'mem_available_min' => 8 * 1024 ** 3,
            'mem_total' => $total,
            'oom_kill' => $i < 10 ? 3 : 5,
            'load1_max' => $i < 5 ? 1.0 : 30.0,
            'cores' => 32,
        ], now()->addDay());
    }

    // Read whole: expectsOutputToContain() lets each written line satisfy one expectation only,
    // and a node's report is one line.
    expect(Artisan::call('fleet:samples', ['--hours' => 1]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('epyc: 20 samples')
        ->toContain('anon max 90%')
        ->toContain('oom kills 2')
        ->toContain('idle 5 min');
});
