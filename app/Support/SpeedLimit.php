<?php

namespace App\Support;

use App\Services\Cdn\EdgeLinkSigner;
use Illuminate\Validation\ValidationException;

/**
 * The per-link speed limit a playback or download mint may carry, in kilobits per second.
 *
 * Kilobits because that is the unit a rendition's bitrate is quoted in, so an integrator capping
 * playback can compare the two directly — a 3000 kbps limit is what keeps the ladder below the
 * 1080p rung. Downloads use the same unit rather than a second one: divide by 8 for kB/s.
 *
 * The limit lives INSIDE the signature, never next to it: a `limit` field of the edge's token
 * ({@see EdgeLinkSigner::sign}). Anything outside the HMAC is the viewer's to edit, and a cap
 * the viewer can delete from the URL is not one.
 */
class SpeedLimit
{
    /**
     * Below this nothing plays: the lowest rung of any ladder this engine packages is already
     * above it, and a download at a few kbps is a link that never finishes before it expires.
     */
    public const MIN_KBPS = 64;

    /** 10 Gbps — an edge port. Anything above is no limit at all, spelled as a number. */
    public const MAX_KBPS = 10_000_000;

    /** @return list<string> */
    public static function rules(): array
    {
        return ['nullable', 'integer', 'min:'.self::MIN_KBPS, 'max:'.self::MAX_KBPS];
    }

    /**
     * What the edge's `limit_rate` takes. 1000 bits to the kilobit, as bitrates count them, not
     * the 1024 of nginx's `k` suffix — which is why the token carries plain bytes and no suffix.
     */
    public static function toBytesPerSecond(int $kbps): int
    {
        return intdiv($kbps * 1000, 8);
    }

    /**
     * Refuse a limit the edge cannot enforce, before anything is minted.
     *
     * A 422 rather than a link without the cap: the caller asked for a limit because something
     * depends on it — a plan tier, a bandwidth budget — and a link that silently ignores it is
     * the one answer they cannot detect.
     */
    public static function ensureSupported(EdgeLinkSigner $cdn, ?int $kbps): void
    {
        if ($kbps !== null && ! $cdn->supportsSpeedLimit()) {
            throw ValidationException::withMessages([
                'speedLimitKbps' => 'The active CDN cannot enforce a speed limit on its links.',
            ]);
        }
    }
}
