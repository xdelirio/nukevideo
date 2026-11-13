<?php

use App\Jobs\PruneScratchJob;
use App\Jobs\RefreshGeoIpJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('videos:dispatch')->everyFiveSeconds()->withoutOverlapping(1);
Schedule::command('videos:reap')->everyMinute()->withoutOverlapping(10);
Schedule::command('videos:prune')->everyThirtyMinutes();

// No-op until a proxy node is deployed.
Schedule::command('nodes:probe')->everyMinute()->withoutOverlapping(1);

// job_batches rows otherwise grow forever. Keep a week so PrepareVideoJob's redelivery guard
// (batch looked up by name) comfortably outlives any retry window.
Schedule::command('queue:prune-batches --hours=168 --unfinished=168 --cancelled=168')->daily();

// Reclaim local scratch + the shared chunk store left by failed/deleted/orphaned videos
// (the success path cleans up immediately; this is the backstop). Runs on a worker, via the queue
// EVERY worker drains: `video-processing` is the CPU transcode queue, which an all-GPU fleet has
// no consumer for — the job piled up there unnoticed instead of ever running.
Schedule::job(new PruneScratchJob, 'orchestration')->everyThirtyMinutes();

// No-op unless a MaxMind key is set. Queued rather than run here: the country database has to
// land on the disk of the container that ingests bandwidth, which is not the scheduler's. Weekly
// keeps it current; a container that starts without one asks for it from the ingest itself.
Schedule::job(new RefreshGeoIpJob, RefreshGeoIpJob::QUEUE)->weekly();
