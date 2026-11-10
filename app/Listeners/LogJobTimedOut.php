<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A timed-out job is killed by the worker's SIGALRM handler, which never calls report(): without
 * this line the only trace of it is a redelivery an hour later. Video 17621 timed out inside its
 * fan-out and left nothing to say so.
 */
class LogJobTimedOut
{
    public function handle(JobTimedOut $event): void
    {
        // It runs inside the SIGALRM handler, just before the worker kills itself: anything thrown
        // here (an unreadable payload, a log channel that is down) would replace a plain timeout
        // with a crash whose trace points at this listener instead of the job.
        try {
            Log::warning('Queue job timed out', [
                'class' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'uuid' => $event->job->uuid(),
                'attempts' => $event->job->attempts(),
                'timeout' => $event->timeout,
            ]);
        } catch (Throwable) {
            // Nothing safer to do from inside a dying worker.
        }
    }
}
