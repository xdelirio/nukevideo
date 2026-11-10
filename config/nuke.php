<?php

return [
    'webhook' => [
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'internal' => [
        'url' => env('INTERNAL_API_URL', config('app.url')).'/api/internal',
        'secret' => env('INTERNAL_API_SECRET'),
    ],

    // Where node images live. Unset in production, which leaves the Docker Hub namespace the
    // released images are published under. Point it at a registry host (`10.0.0.240:5000`) and that
    // host becomes part of the image name — which is how docker knows where to pull from, and what
    // lets a development build reach a test node without going anywhere public.
    'registry' => env('DOCKER_REGISTRY'),

    // This node's id, exported to workers by NodeService. Unset on the API host, which is how
    // node-only work (the memory sampler) knows to stay off it. Read via config so it survives
    // config:cache.
    'node_id' => env('NODE_ID'),

    'video' => [
        // Per-chunk worker budget (seconds); mirrors the Horizon video supervisor timeout and is
        // exported to worker nodes by NodeService. Read via config so it survives config:cache.
        'worker_timeout' => (int) env('VIDEO_WORKER_TIMEOUT', 600),

        // oom_score_adj every chunk ffmpeg raises itself to (probes take half), so that when memory
        // runs out the kernel kills one encode, which is retried, instead of a Horizon worker or
        // the chunk store ({@see \App\Support\OomScore}). 0 switches it off.
        'chunk_oom_score_adj' => (int) env('CHUNK_OOM_SCORE_ADJ', 1000),

        // There is deliberately no `concurrent` here any more. How many videos may be in flight is
        // derived from the active nodes of each hardware family
        // ({@see \App\Console\Commands\DispatchPendingVideosCommand}), because a fixed number
        // drifts silently the moment the fleet changes.
    ],
];
