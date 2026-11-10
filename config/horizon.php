<?php

use App\Support\Cpu;
use App\Support\Gpu;

// Role-gated provisioning. Horizon merges `defaults` into EVERY environment via
// array_replace_recursive, so a supervisor listed in `defaults` is provisioned even
// when it's absent from `environments`. To truly keep a role's supervisor off a node
// it must be excluded from BOTH. So the whole set (defaults + environments) is picked
// by NODE_TYPE: worker nodes run the video/orchestration/packaging supervisors; the main
// server runs only the default-queue supervisor and must never pull `video-processing` (it
// isn't a worker — no ffmpeg, no local scratch, and not wired to the chunk store).
// One hardware transcode supervisor per node: CPU nodes pull `video-processing`, GPU nodes
// (NODE_ACCEL set) pull ONLY their `video-processing-{accel}` queue — never CPU transcode.
// Orchestration (the light per-video prep/thumbnail/storyboard/sidecar/cleanup jobs) gets its
// own queue that EVERY worker drains regardless of hardware, so it's never stranded when the
// fleet has no CPU node. Packaging likewise gets its own supervisor so the light finalization
// step never starves behind the CPU-bound transcode backlog. Opt-out per node with
// DISABLE_PACKAGING (default: every worker packages) — the safe failure mode is "packages on
// too many nodes", not "packages on none".
$isWorker = env('NODE_TYPE') === 'worker';
$runsPackaging = $isWorker && ! filter_var(env('DISABLE_PACKAGING', false), FILTER_VALIDATE_BOOL);

// Pools are pinned (minProcesses = maxProcesses). `balance => none` does not mean a fixed pool:
// Horizon still scales a lone pool between 1 and its maximum by the length of its ready queue, one
// process every few seconds, and starts it at half. Between fan-outs the pools decayed and then
// climbed back a process at a time — 30,000 graceful worker stops in nine days on one node, with
// 21 of its 32 encoders busy on average. An idle PHP worker costs a few tens of MB; an idle core
// costs the backlog.
$videoWorker = [
    'connection' => 'redis',
    'queue' => ['video-processing'],
    'balance' => 'none',
    'minProcesses' => Cpu::videoWorkerProcesses(),
    'maxProcesses' => Cpu::videoWorkerProcesses(),
    // Horizon adds one process per cooldown; at the default a 40-process pool took two minutes
    // to fill after every start.
    'balanceMaxShift' => 8,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 1024,
    'tries' => 2,
    'timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),
    'nice' => 0,
];

// Renditions above 1080p ({@see \App\Models\Stream::isHeavyEncode}), on their own small pool: one
// 2160p encode holds what four 1080p ones do, so sharing the common pool's slots let a node start
// as many of them as it had slots and run out of memory. Heavy-only on purpose — the pool reserves
// nothing while it idles, and each job waits for free memory before it starts ffmpeg.
$heavyWorker = [
    'connection' => 'redis',
    'queue' => ['video-processing-heavy'],
    'balance' => 'none',
    'minProcesses' => Cpu::heavyWorkerProcesses(),
    'maxProcesses' => Cpu::heavyWorkerProcesses(),
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 1024,
    'tries' => 2,
    'timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),
    'nice' => 0,
];

// Dedicated orchestration supervisor: the light per-video jobs that aren't chunk transcode
// (source download/probe/segment, thumbnail, storyboard, sidecar, cleanup). Runs on every
// worker so it always has a consumer, independent of which hardware queues exist in the fleet.
$orchestrationWorker = [
    'connection' => 'redis',
    'queue' => ['orchestration'],
    'balance' => 'none',
    'minProcesses' => 3,
    'maxProcesses' => 3,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 1024,
    'tries' => 1, // jobs define their own $tries (PrepareVideoJob = 5)
    'timeout' => 1800, // covers PrepareVideoJob::$timeout; must stay <= REDIS_QUEUE_RETRY_AFTER (1850)
    'nice' => 0,
];

// Dedicated packaging supervisor: light, I/O-bound (shaka remux + aws s3 sync). A small
// guaranteed pool means a finished video finalizes without waiting behind heavy transcode jobs.
$packagingWorker = [
    'connection' => 'redis',
    'queue' => ['packaging'],
    'balance' => 'none',
    'minProcesses' => 2,
    'maxProcesses' => 2,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 512,
    'tries' => 1,
    'timeout' => 1800, // matches PackageVideoJob::$timeout; must stay <= REDIS_QUEUE_RETRY_AFTER (1850)
    'nice' => 0,
];

$supervisor1 = [
    'connection' => 'redis',
    'queue' => ['default'],
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'maxProcesses' => 1,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 128,
    'tries' => 1,
    'timeout' => 60,
    'nice' => 0,
];

// Deploys, starts and stops (RunNodeOperationJob). Its own supervisor because `default` kills at
// 60s, and a draining deploy runs for minutes. Several processes so a fleet's workers deploy in
// parallel; proxies are chained and only ever take one.
$nodeOpsSupervisor = [
    'connection' => 'node-ops',
    'queue' => ['node-ops'],
    // Idle almost always: one process, scaled up only while a fleet deploy is queued.
    'balance' => 'auto',
    'autoScalingStrategy' => 'size',
    'minProcesses' => 1,
    'maxProcesses' => 10,
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 128,
    'tries' => 1,
    'timeout' => 1500, // RunNodeOperationJob::$timeout; must stay < the node-ops retry_after (1600)
    'nice' => 0,
];

// GPU nodes (NODE_ACCEL=intel|nvidia) run a supervisor for their hardware queue in place of the
// CPU transcode one (orchestration and packaging still run beside it). Concurrency is GPU encode
// sessions, not cores ({@see \App\Support\Gpu}); override per node with GPU_WORKER_PROCESSES.
$accel = env('NODE_ACCEL');

$gpuWorker = [
    'connection' => 'redis',
    'queue' => ["video-processing-{$accel}"],
    'balance' => 'none',
    'maxProcesses' => Gpu::videoWorkerProcesses(),
    'maxTime' => 0,
    'maxJobs' => 0,
    'memory' => 1024,
    'tries' => 2,
    'timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),
    'nice' => 0,
];

$isGpu = $isWorker && in_array($accel, ['intel', 'nvidia'], true);

// Exactly one hardware transcode supervisor per node: GPU nodes pull only their accel queue,
// CPU nodes pull `video-processing`. GPU nodes must NOT also run `video-worker` or they'd steal
// CPU transcode chunks.
$workerDefaults = $isGpu
    ? ['gpu-worker' => $gpuWorker]
    : ['video-worker' => $videoWorker, 'heavy-worker' => $heavyWorker];
$workerEnv = $isGpu
    ? ['gpu-worker' => []]
    : ['video-worker' => [], 'heavy-worker' => []];

$workerDefaults['orchestration-worker'] = $orchestrationWorker;
$workerEnv['orchestration-worker'] = [];

if ($runsPackaging) {
    $workerDefaults['packaging-worker'] = $packagingWorker;
    $workerEnv['packaging-worker'] = [];
}

$defaults = $isWorker
    ? $workerDefaults
    : ['supervisor-1' => $supervisor1, 'node-ops' => $nodeOpsSupervisor];

$environments = $isWorker
    ? [
        'production' => $workerEnv,
        'staging' => $workerEnv,
        'local' => $workerEnv,
    ]
    : [
        'production' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'node-ops' => [],
        ],
        'staging' => [
            'supervisor-1' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'node-ops' => [],
        ],
        'local' => [
            'supervisor-1' => [
                'maxProcesses' => 3,
            ],
            'node-ops' => [],
        ],
    ];

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env('HORIZON_PREFIX', 'nukevideo_horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => $defaults,

    'environments' => $environments,

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
