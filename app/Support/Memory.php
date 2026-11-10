<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Sleep;

/**
 * What the node has free right now, as the kernel counts it — the one number that already includes
 * everything a formula cannot see: the chunk store on the same host, page cache under pressure,
 * an orphaned ffmpeg, someone else's container.
 */
class Memory
{
    private const POLL_SECONDS = 5;

    /** `MemAvailable` in GB, or null where /proc/meminfo cannot be read (not Linux, or a test). */
    public static function availableGb(): ?float
    {
        if (preg_match('/MemAvailable:\s+(\d+) kB/', (string) @file_get_contents('/proc/meminfo'), $match)) {
            return ((float) $match[1]) / 1024 ** 2;
        }

        return null;
    }

    /**
     * Hold until `$gb` is available, for at most `$maxSeconds`. True once there is room, false when
     * the wait ran out — the caller goes ahead either way, since a start that is late beats one
     * that never comes, and an encode killed for memory is released and tried again. An unreadable
     * /proc reads as room: the old behaviour.
     *
     * @param  Closure(): void  $tick  called while waiting, to keep a heartbeat alive
     */
    public static function waitFor(float $gb, int $maxSeconds, Closure $tick): bool
    {
        for ($waited = 0; ; $waited += self::POLL_SECONDS) {
            $available = self::availableGb();

            if ($available === null || $available >= $gb) {
                return true;
            }

            if ($waited >= $maxSeconds) {
                return false;
            }

            $tick();
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }
}
