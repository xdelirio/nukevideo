<?php

declare(strict_types=1);

namespace App\Services\Cdn;

use App\Exceptions\NoCdnNodeAvailableException;
use App\Models\Node;
use App\Models\Video;
use App\Settings\CdnSettings;
use App\Support\SpeedLimit;

/**
 * Builds the links our edges serve: the URL points at a per-video proxy node (edge nginx) and carries an Akamai
 * `__hdnea__` token. The edge validates it and re-signs the segment URLs in the manifest body.
 *
 * The URL names no viewer. Attribution rides on the token: the edge logs the `__hdnea__` value
 * of every request a link produces, and the mint records what that token means
 * ({@see TrackingRegistry}). Relative segment URLs resolve under the manifest's directory, so
 * they inherit the query the edge re-signs into the manifest body.
 */
class EdgeLinkSigner
{
    public function __construct(
        private CdnSettings $settings,
        private ProxyRing $ring,
    ) {}

    /**
     * Build the fully-qualified, signed manifest URL.
     *
     * Returned with its token rather than as a bare string: the token is what every segment
     * request carries into the access log, so it is what the mint attributes to a tracking id
     * ({@see TrackingRegistry}). The URL itself never names the viewer.
     *
     * @param  string  $path  host-relative manifest path ({videoUlid}/{file})
     * @param  int|null  $speedLimitKbps  signed into the token, so it reaches every segment the
     *                                    manifest lists; only when {@see signs}
     * @param  int|null  $expiresIn  seconds the link stays valid; null for the token window
     * @param  string|null  $manifestKey  names the options the link's manifests are built with
     *                                    ({@see ManifestOptionsRegistry}); null for the defaults
     */
    public function manifestUrl(Video $video, string $path, string $ip, ?int $speedLimitKbps = null, ?int $expiresIn = null, ?string $manifestKey = null): SignedLink
    {
        $node = $this->ring->for($video->ulid);

        if (! $node) {
            throw new NoCdnNodeAvailableException;
        }

        $url = Node::proxyScheme()."{$node->hostname}/".ltrim($path, '/');

        return $this->sign($url, $ip, speedLimitKbps: $speedLimitKbps, expiresIn: $expiresIn, manifestKey: $manifestKey);
    }

    /**
     * Build the fully-qualified, UNSIGNED URL of a thumbnail or storyboard.
     *
     * Deliberately unsigned: these are shown in listings, so a URL that changes on every render
     * would defeat the browser cache. The edge serves the `assets/` zone without token validation
     * ({@see vod/nginx/nginx.conf.template}), and nothing else in the bucket is reachable that way.
     *
     * Takes the ULID rather than the model because that is all the routing needs, and the callers
     * that build listing payloads do not always have the video loaded.
     *
     * @param  string  $key  host-relative asset key ({videoUlid}/assets/{file})
     */
    public function assetUrl(string $videoUlid, string $key): string
    {
        $node = $this->ring->for($videoUlid);

        if (! $node) {
            throw new NoCdnNodeAvailableException;
        }

        return Node::proxyScheme().$node->hostname.'/'.ltrim($key, '/');
    }

    /**
     * Build the fully-qualified, signed URL of ONE downloadable track.
     *
     * Signed for this object and nothing else: the ACL carries no trailing `*`, and the edge
     * compares an unwildcarded ACL as an exact URI match rather than a byte prefix. Verified
     * against the running edge — the same token on a sibling rendition returns 403, which is the
     * whole point, since the renditions of one video are neighbours in `download/video/`.
     *
     * Not IP-bound. Downloads are resumed, retried and handed to download managers, which roam
     * between addresses; playback tokens can afford the binding because a session is short.
     *
     * @param  string  $key  host-relative object key ({videoUlid}/download/{type}/{file})
     * @param  int|null  $expiresIn  seconds the link stays valid; null for the token window
     */
    public function downloadUrl(string $videoUlid, string $key, ?int $speedLimitKbps = null, ?int $expiresIn = null): SignedLink
    {
        $node = $this->ring->for($videoUlid);

        if (! $node) {
            throw new NoCdnNodeAvailableException;
        }

        $path = '/'.ltrim($key, '/');

        return $this->sign(
            Node::proxyScheme().$node->hostname.$path,
            ip: null,
            acl: $path,
            speedLimitKbps: $speedLimitKbps,
            expiresIn: $expiresIn,
        );
    }

    /**
     * Whether links are signed at all. Everything that rides on a link's token depends on it: the
     * speed limit is a field of the token, and so is the key naming the options a manifest is
     * built from ({@see ManifestOptionsRegistry}). With no secret there is no token to put either
     * in.
     *
     * Asked before minting rather than discovered while signing, so a refusal is one 422 for the
     * whole request instead of an exception halfway through a batch.
     */
    public function signs(): bool
    {
        return $this->settings->token_secret !== '';
    }

    /**
     * The token handed back is the whole `__hdnea__` value (`exp=…~acl=…~hmac=…`), exactly as it
     * sits in the URL and in the edge's log — that is what the ingest will hash to look the
     * link up, so the two sides must agree on the bytes.
     *
     * A speed limit rides as a `limit` field, in bytes per second. The edge's validator ignores
     * fields it does not know but signs everything before `~hmac=`, so the field is tamper-proof
     * without the module knowing about it, and the edge reads it back out of the token with a
     * `map` ({@see vod/nginx/nginx.conf.template}). An edge that predates the map still validates
     * the token and simply serves it unlimited.
     */
    private function sign(string $url, ?string $ip, ?string $acl = null, ?int $speedLimitKbps = null, ?int $expiresIn = null, ?string $manifestKey = null): SignedLink
    {
        if ($this->settings->token_secret === '') {
            if ($speedLimitKbps !== null) {
                throw new \LogicException('An unsigned link cannot carry a speed limit.');
            }

            return new SignedLink($url, null);
        }

        $exp = now()->timestamp + ($expiresIn ?? $this->settings->token_window);
        $acl ??= $this->aclFor($url);

        // The address goes in only when the operator binds links to it: the edge's validator
        // refuses any token whose `ip` is not the requesting address, and has no switch of its own
        // — the field's presence IS the switch. So the setting takes effect on the next mint, with
        // no redeploy, and a link minted while it was on stays bound until it expires even after
        // it is turned off. A download never carries it: one file a download manager may resume
        // from anywhere.
        $authString = $ip === null || ! $this->settings->bind_ip
            ? "exp={$exp}~acl={$acl}"
            : "exp={$exp}~acl={$acl}~ip=".self::canonicalIp($ip);

        if ($speedLimitKbps !== null) {
            $authString .= '~limit='.SpeedLimit::toBytesPerSecond($speedLimitKbps);
        }

        // Which manifest options the link was minted with, as an opaque key — a field the edge's
        // validator ignores and signs, like `limit`. It has to be IN the token, not merely filed
        // under it: a token is otherwise its expiry and its ACL, so two viewers minting the same
        // video within one second hold the same one, and whatever was filed under it answered
        // for both. With the key inside, equal tokens can only mean equal options.
        if ($manifestKey !== null) {
            $authString .= '~'.ManifestOptionsRegistry::TOKEN_FIELD."={$manifestKey}";
        }
        $hmac = hash_hmac('sha256', $authString, pack('H*', $this->settings->token_secret));
        $token = "{$authString}~hmac={$hmac}";

        $separator = str_contains($url, '?') ? '&' : '?';

        return new SignedLink("{$url}{$separator}{$this->settings->token_name}={$token}", $token);
    }

    /**
     * The address in the form nginx writes the client's in (`addr_text`, after `real_ip`), because
     * the validator compares the two byte for byte: IPv6 compressed and lowercase
     * (`2001:DB8:0:0::1` is `2001:db8::1`), and an IPv4-mapped address as the plain IPv4 the edge
     * sees — `::ffff:203.0.113.7` is what a dual-stack Node backend reports for an IPv4 client.
     * Anything that does not parse is signed as given: the request layer validates `ip`, and the
     * caller's own address always parses.
     */
    private static function canonicalIp(string $ip): string
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return $ip;
        }

        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        return (string) inet_ntop($binary);
    }

    /**
     * The ACL is the manifest's directory, which is why the zone a manifest lives in decides how far
     * a playback link reaches. The edge compares it as a raw byte prefix, so the trailing `*` spans
     * sub-directories — that is what covers the segment dirs.
     *
     * A path with no `/` would make `strrpos` return false, `$dir` empty and the ACL `/*`: a token
     * for the entire bucket. Unreachable today, but it fails towards "authorize everything", so it
     * throws instead of signing.
     */
    private function aclFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH); // /{videoUlid}/play/manifest.mpd
        $separator = strrpos($path, '/');

        if ($separator === false || $separator === 0) {
            throw new \InvalidArgumentException("Refusing to sign a bucket-wide ACL for [{$path}].");
        }

        return substr($path, 0, $separator).'/*';
    }
}
