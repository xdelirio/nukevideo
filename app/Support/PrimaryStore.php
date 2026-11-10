<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * A short-lived, fleet-wide note that the primary store is turning requests away.
 *
 * Nothing probes the store: a job that ran out of transfer attempts leaves the note, and the
 * dispatcher stops claiming videos while it stands. Without it the queue kept feeding a store that
 * was down — on 2026-11-10 six videos were claimed and failed within 53 minutes, each on its source
 * download. The note expires by itself, so a recovered store needs nobody to clear it; the next
 * failure simply renews it.
 */
class PrimaryStore
{
    private const KEY = 'nuke:primary-store:unavailable';

    /** Long enough to outlast the bursts seen in production (up to ~4 minutes), short enough that
     *  a recovered store is back in use within one coffee. */
    private const HOLD_SECONDS = 600;

    public static function markUnavailable(string $reason): void
    {
        Cache::put(self::KEY, mb_substr($reason, 0, 300), self::HOLD_SECONDS);
    }

    public static function unavailable(): bool
    {
        return Cache::has(self::KEY);
    }

    public static function reason(): ?string
    {
        return Cache::get(self::KEY);
    }
}
