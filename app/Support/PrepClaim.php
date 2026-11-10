<?php

namespace App\Support;

use App\Jobs\PrepareVideoJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "A preparation is owed to this video", kept in the shared cache from the moment the dispatcher
 * claims a video until its {@see PrepareVideoJob} settles it.
 *
 * Nothing else can say so. A prep waiting in the queue behind other preps beats no heartbeat, and
 * neither does one whose worker died until the queue redelivers it ~31 minutes later, so the reaper
 * read both as abandoned and failed healthy videos (two in one Redis-flap hour on 11-03), which
 * `videos:prune` then deletes with their sources. The key outlives exactly what is still coming:
 * the job refreshes it while it works, keeps it across a crash, a timeout or a release (a
 * redelivery is still owed), and drops it only once it has fanned out, handed the video back,
 * or failed it.
 *
 * Its value is a token the job carries, so a job from a superseded run (the video was failed and
 * retried while it sat in the queue) can tell it no longer owns the video and step aside.
 */
class PrepClaim
{
    /** Six hours: well past a redelivery (1850s) plus the longest wait behind other preps. */
    public const TTL = 21600;

    private const KEY = 'prep-pending:%d';

    public static function issue(int $videoId, int $ttl = self::TTL): string
    {
        $token = (string) Str::ulid();

        Cache::put(self::key($videoId), $token, $ttl);

        return $token;
    }

    /** Extend the claim while it is still this token's; best effort, never fails the job. */
    public static function refresh(int $videoId, ?string $token, int $ttl = self::TTL): void
    {
        if ($token === null) {
            return;
        }

        try {
            if (Cache::get(self::key($videoId)) === $token) {
                Cache::put(self::key($videoId), $token, $ttl);
            }
        } catch (Throwable $e) {
            Log::warning('Preparation claim not refreshed', ['video' => $videoId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Whether a job carrying this token may still act for the video. A null token is an old payload
     * from before claims existed, and a missing key cannot tell anyone apart: both run as before.
     */
    public static function holds(int $videoId, ?string $token): bool
    {
        if ($token === null) {
            return true;
        }

        try {
            $current = Cache::get(self::key($videoId));
        } catch (Throwable $e) {
            // A key nobody can read tells runs apart no better than a missing one; a flap here must
            // not become a counted failure of the preparation asking.
            Log::warning('Preparation claim unreadable; assuming it holds', ['video' => $videoId, 'error' => $e->getMessage()]);

            return true;
        }

        return $current === null || $current === $token;
    }

    /** Read by the reaper. Lets a cache error through: the reaper crashing is safe, a reap is not. */
    public static function pending(int $videoId): bool
    {
        return Cache::has(self::key($videoId));
    }

    /** Drop the claim, only if it is still this token's: never another run's. */
    public static function forget(int $videoId, ?string $token): void
    {
        if ($token === null) {
            return;
        }

        try {
            if (Cache::get(self::key($videoId)) === $token) {
                Cache::forget(self::key($videoId));
            }
        } catch (Throwable $e) {
            // It expires on its own; meanwhile the reaper waits a little longer than it needs to.
            Log::warning('Preparation claim not dropped', ['video' => $videoId, 'error' => $e->getMessage()]);
        }
    }

    private static function key(int $videoId): string
    {
        return sprintf(self::KEY, $videoId);
    }
}
