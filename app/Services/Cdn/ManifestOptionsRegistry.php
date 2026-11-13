<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Data\Video\ManifestOptions;
use App\Support\LinkExpiry;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What a minted link asked of its manifests.
 *
 * The options themselves never travel: the link's token carries an opaque key for them
 * ({@see TOKEN_FIELD}, signed in by {@see EdgeLinkSigner}), and this keeps `key → options`. The
 * edge hands the token back when it asks for a manifest, and the key is read out of it.
 *
 * Keyed by the OPTIONS, not by the link. Every link that asks for the same thing shares one
 * entry, so the store holds a handful of them however many links are minted — and, more to the
 * point, two links can never be told apart wrongly: a token names its options itself, so nothing
 * here depends on tokens being unique, which they are not ({@see EdgeLinkSigner::sign}).
 *
 * Default options are not stored and carry no key, so a token without one resolves to the
 * defaults — which is what makes them the behaviour of every link that asked for nothing. A lost
 * entry — a cache restart — resolves to them as well: it costs the personalization, never the
 * playback. That is also why nothing here may be an entitlement yet: an option that must fail
 * closed needs a store that does not forget, or a refusal when the entry is missing.
 */
class ManifestOptionsRegistry
{
    /** The token field carrying the key. Unknown to the edge's validator, which signs it anyway. */
    public const TOKEN_FIELD = 'mo';

    /**
     * Seconds an entry is kept, renewed by every mint that uses it. The longest a link may live,
     * and a day on top: an entry is shared by links of every lifetime, so it has to outlast the
     * longest of them whichever one wrote it last.
     */
    private const TTL = LinkExpiry::MAX_SECONDS + 86400;

    public static function cacheKey(string $key): string
    {
        return "manifest-options:{$key}";
    }

    /**
     * Remember a mint's options and answer with the key its links must carry — null for the
     * defaults, which need neither.
     */
    public function keyFor(ManifestOptions $options): ?string
    {
        if ($options->isDefault()) {
            return null;
        }

        $key = $options->cacheKey();

        Cache::put(self::cacheKey($key), $options->toArray(), self::TTL);

        return $key;
    }

    /**
     * The options a token was minted with — the raw token, exactly as it sits in the URL.
     *
     * Anything that names no stored options answers with the defaults, and so does an entry this
     * code can no longer read (written by a version whose options have since changed shape): the
     * viewer holds a valid link either way, and a default manifest plays.
     */
    public function resolve(?string $token): ManifestOptions
    {
        if ($token === null || ! preg_match('/(?:^|~)'.self::TOKEN_FIELD.'=([0-9a-f]{16})(?:~|$)/', $token, $matches)) {
            return new ManifestOptions;
        }

        $stored = Cache::get(self::cacheKey($matches[1]));

        if (! is_array($stored)) {
            return new ManifestOptions;
        }

        try {
            return ManifestOptions::from($stored);
        } catch (Throwable) {
            return new ManifestOptions;
        }
    }
}
