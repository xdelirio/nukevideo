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

    /**
     * Every call here degrades to "no note": the note is advice, and a cache that cannot be reached
     * must cost neither the job that wanted to leave it nor the dispatch tick that wanted to read it.
     */
    public static function markUnavailable(string $reason): void
    {
        rescue(fn () => Cache::put(self::KEY, mb_substr($reason, 0, 300), self::HOLD_SECONDS), report: false);
    }

    public static function unavailable(): bool
    {
        return (bool) rescue(fn () => Cache::has(self::KEY), false, report: false);
    }

    public static function reason(): ?string
    {
        return rescue(fn () => Cache::get(self::KEY), null, report: false);
    }
}
