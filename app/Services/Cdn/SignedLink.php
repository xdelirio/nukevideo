<?php

declare(strict_types=1);

namespace App\Services\Cdn;

/**
 * A minted playback or download link, together with the token inside it.
 *
 * The token is the one thing every request a link produces carries into the CDN's access log —
 * the manifest, each segment, a resumed download — and it is opaque, so it is also the only
 * thing that can tie those log lines back to the mint. Its SHA-256 is what the mint records
 * ({@see TrackingRegistry}) and what the log ingest looks up: hashing keeps a valid bearer token
 * out of the cache. Neither the token nor the hash leaves the mint — the API answers with the
 * URL alone; attribution is a server-side side effect of minting it.
 *
 * `token` is null for an unsigned link (no signing secret configured): with no
 * token in the URL there is nothing for the log to carry, so such a link cannot be attributed.
 */
class SignedLink
{
    public readonly ?string $tokenHash;

    public function __construct(
        public readonly string $url,
        public readonly ?string $token,
    ) {
        $this->tokenHash = $token === null || $token === '' ? null : hash('sha256', $token);
    }
}
