<?php

namespace App\Services\Concerns;

use App\Models\Video;
use App\Support\NodeSampler;

/**
 * Liveness heartbeat throttled to at most one write per interval, since ffmpeg progress fires
 * many times a second and persisting every tick would hammer the DB and Redis.
 */
trait EmitsHeartbeat
{
    private const HEARTBEAT_INTERVAL_SECONDS = 15;

    private ?float $lastHeartbeatAt = null;

    private function heartbeat(Video $video): void
    {
        $now = microtime(true);

        if ($this->lastHeartbeatAt !== null
            && ($now - $this->lastHeartbeatAt) < self::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $this->lastHeartbeatAt = $now;
        $video->heartbeat();

        // A node beating heartbeats is a node at work: the moment its memory is worth knowing.
        // Self-throttled per node, and it never throws.
        NodeSampler::sample();
    }
}
