<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Settings\CdnSettings;
use Illuminate\Support\Facades\Cache;

/**
 * What a minted link's token means: the tracking id the mint attributed it to.
 *
 * The URL never carries the id: a query is not inherited by the segments, and a path segment is
 * one the edge's nginx would have to strip again. The token IS the carrier: every request a link
 * produces logs it, so the mint records `hash(token) → id` and the log ingest resolves the hash
 * back. Best-effort by nature — a mapping lost to a cache restart costs the label, never the
 * bytes.
 */
class TrackingRegistry
{
    /**
     * Seconds a mapping outlives the token it describes: a segment fetched right before expiry
     * reaches the ingest later, once the edge's log shipper has batched it and the queue has
     * drained it.
     */
    private const GRACE = 1800;

    public function __construct(private CdnSettings $settings) {}

    public static function cacheKey(string $tokenHash): string
    {
        return "link-tracking:{$tokenHash}";
    }

    public static function downloadKey(string $tokenHash): string
    {
        return "link-download:{$tokenHash}";
    }

    /**
     * Attribute a freshly minted link. A link with no token, or a mint that names no viewer,
     * records nothing: there is nothing to resolve, and an empty mapping would only shadow a
     * later one.
     */
    public function record(SignedLink $link, ?string $trackingId, ?int $lifetime = null): void
    {
        $this->recordMany([$link], $trackingId, lifetime: $lifetime);
    }

    /**
     * The same for a batch mint, in one round trip rather than one per track — the write side of
     * {@see resolveMany}. A batch is one viewer fetching one video's pieces, so the whole batch
     * shares an id.
     *
     * `$download` marks the links as an offline download, so the ingest books their bytes as
     * `download_bytes` rather than `streaming_bytes`: the segments are the same files either way.
     * `$lifetime` is how long the links were signed for, when not the token window: a mapping
     * that died before its link would leave the link's last hours unattributed.
     *
     * @param  list<SignedLink>  $links
     */
    public function recordMany(array $links, ?string $trackingId, bool $download = false, ?int $lifetime = null): void
    {
        $mapping = [];

        foreach ($links as $link) {
            if ($link->tokenHash === null) {
                continue;
            }
            if ($trackingId !== null && $trackingId !== '') {
                $mapping[self::cacheKey($link->tokenHash)] = $trackingId;
            }
            if ($download) {
                $mapping[self::downloadKey($link->tokenHash)] = true;
            }
        }

        if ($mapping !== []) {
            Cache::putMany($mapping, ($lifetime ?? $this->tokenWindow()) + self::GRACE);
        }
    }

    /** The tracking id a logged token was minted for, or null when no mapping (still) exists. */
    public function resolve(string $tokenHash): ?string
    {
        return $this->resolveMany([$tokenHash])[$tokenHash];
    }

    /**
     * The same, for a batch: one round trip for every hash of an ingest batch rather than one
     * per row. Every requested hash is present in the result, null when unmapped.
     *
     * @param  list<string>  $tokenHashes
     * @return array<string, string|null>
     */
    public function resolveMany(array $tokenHashes): array
    {
        return $this->lookupMany($tokenHashes)['tracking'];
    }

    /**
     * The tracking ids and the offline-download marks of a batch, in one round trip.
     *
     * @param  list<string>  $tokenHashes
     * @return array{tracking: array<string, string|null>, downloads: array<string, true>}
     */
    public function lookupMany(array $tokenHashes): array
    {
        $tokenHashes = array_values(array_unique($tokenHashes));

        if ($tokenHashes === []) {
            return ['tracking' => [], 'downloads' => []];
        }

        $found = Cache::many([
            ...array_map(self::cacheKey(...), $tokenHashes),
            ...array_map(self::downloadKey(...), $tokenHashes),
        ]);
        $tracking = [];
        $downloads = [];

        foreach ($tokenHashes as $hash) {
            $trackingId = $found[self::cacheKey($hash)] ?? null;
            $tracking[$hash] = is_string($trackingId) && $trackingId !== '' ? $trackingId : null;

            if (($found[self::downloadKey($hash)] ?? null) === true) {
                $downloads[$hash] = true;
            }
        }

        return ['tracking' => $tracking, 'downloads' => $downloads];
    }

    /** How long a link stays valid when the mint does not ask for a lifetime of its own. */
    public function tokenWindow(): int
    {
        return $this->settings->token_window;
    }
}
