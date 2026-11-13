# Streaming & VOD

NukeVideo serves **pre-packaged, static CMAF**. Encoded renditions are packaged once by [shaka-packager](https://github.com/shaka-project/shaka-packager) into shared segments that are stored on S3 and served as-is. There is no on-the-fly repackaging at request time.

## One Package, Both Protocols

Each output's renditions are packaged once into CMAF fragments. Because HLS and DASH can reference the same fragmented-MP4 segments, that one package serves **both** protocols:

| Protocol | Manifest | Use Case |
|----------|----------|----------|
| HLS | `.m3u8` | Apple devices, most browsers, widest compatibility |
| DASH | `.mpd` | Android, smart TVs, DRM-ready |

Subtitles are packaged separately for each protocol — fragmented-MP4 WebVTT for DASH, plain WebVTT for HLS, whose players cannot read the fragmented form — and grafted into the matching manifests. Multiple audio and subtitle tracks (with per-language labels) are supported.

## How Delivery Works

The packaged segments and manifests live on S3. A delivery layer sits in front of the bucket to enforce access control and cache segments close to viewers:

```
Client → Delivery (proxy node) → S3 (CMAF) → Client
```

1. The client requests a manifest with a signed token.
2. The delivery layer validates the token.
3. The manifest is served, then the client fetches the CMAF segments it references.
4. Segments are cached at the edge. The master manifest is built by the API for the link it was requested with (see [Per-link manifests](#per-link-manifests)); an update to a video's tracks is picked up within half a minute.

The delivery layer is a set of self-hosted **proxy nodes**. See [CDN & Delivery](/guide/cdn) for how they work and how to set them up.

## Requesting a Playback URL

The API mints signed manifest URLs for a video in one call — every output, and every format each
one serves:

```
POST /api/videos/{ulid}/play
```

The URLs point at a proxy node host and carry the access token. The answer is a flat list of `sources`: one entry per output and
format, each carrying its `format`, its `videoCodec` and its `audioCodec`, so you can rule one out
before loading it. A manifest that cannot be served is absent. Full shape in
[Playback URLs](/api/videos#playback-urls).

**Request body** (all optional):

The body is camelCase, like every write in this API. A snake_case key is not bound to anything
and is silently ignored — `tracking_id` does not fail the request, it just leaves the playback
unattributed.

| Field | Type | Notes |
|-------|------|-------|
| `resolution` | integer | Caps every output's ladder at this height. Nothing else in the body selects what comes back — every format each output serves is in the answer. |
| `ip` | string | The viewer's address, when the link is minted from your backend; defaults to the caller's. Enforced only with [IP binding](/guide/cdn#ip-binding) turned on: the link then plays from that address alone, so a backend that omits it binds the link to itself. |
| `speedLimitKbps` | integer | Caps the transfer rate of every request the link produces, in kilobits per second. See [Speed limits](#speed-limits). |
| `trackingId` | string | Your own tracking id for this viewer — a customer, a campaign. It never appears in the link: the mint records the link's token against your id server-side, so every request the link produces — the manifest, each segment — is attributed to it in the [bandwidth analytics](/api/analytics#bandwidth-by-tracking-id) when the CDN log is ingested. Up to 64 characters of `A-Z a-z 0-9 _ -`. The mapping is best-effort (it lives server-side, for the token's lifetime plus a margin). When omitted on a session or personal-token request the traffic is attributed to the ULID of the authenticated user — the admin panel's own playback stays attributed that way. A project key that omits it leaves the traffic unattributed: naming the viewer is the integrator's job. |

## Token-Based Access Control

Playback URLs are signed with a time-limited token so segments can't be fetched without authorization.
One token covers a whole playback session — the manifest and every segment the player derives from
it — and it is minted by the API with the provider's **token window** (one hour by default), or with
the `expiresIn` the request asked for, up to a week. The segments do not get a window of their own:
they expire with the link, so a session that outlives it has to request a fresh link.

Proxy nodes validate Akamai-style tokens (HMAC) scoped to the manifest's directory, rewrite the manifest so its segment URLs carry that same token, and read the segments from S3 using AWS authentication.

The token is what ties a session's traffic back to the link that was minted, which is
how a `trackingId` is attributed without ever appearing in the URL.

## Speed limits

Every link mint — [playback](/api/videos#playback-urls), [a track](/api/streams#download-a-track)
and [a video's tracks](/api/videos#download-tracks) — takes an optional `speedLimitKbps`: a cap on
the transfer rate, in kilobits per second (`64` to `10000000`). Kilobits because that is the unit a
rendition's bitrate is quoted in, so `3000` keeps a player below a 1080p rung that needs more; for
a download, divide by 8 for kB/s.

The limit is part of the signature, not a parameter next to it, so a viewer who edits or removes
it gets a 403 rather than a faster link. Because the segments carry the manifest's token, one
capped playback link caps the whole session.

It rides as a `limit` field of the token, in bytes per second, which the edge reads back once the
HMAC has passed and applies to the response.

It is enforced **per request**. A player fetching two segments at once, or a download manager
opening four ranged connections, gets the limit on each of them — it caps a connection, not a
viewer.

A limit needs a signed link: with no token secret there is
nothing to sign it into, and the mint answers `422` rather than a link without the cap. A proxy
node still running an older edge validates the token and serves it unlimited: redeploy the node
to enforce it.

## Per-link manifests

A master manifest (`{output}.mpd`, `{output}.m3u8`) is not served as the file the packager wrote.
The edge validates the link's token and then asks the API for the manifest, and the API builds it
from what that link asked for when it was minted:

| Option | Effect |
|--------|--------|
| `resolution` | Video renditions above the cap are left out. |
| `subtitlesFormat` | DASH text tracks point at plain `.vtt` files by default; `segments` keeps the packaged fragmented-MP4 WebVTT. |

The options are never in the URL. The link's token carries an opaque key for them, signed like
the rest of the token, and the edge hands the token back when it asks — so a viewer cannot change
them, and two viewers of the same video can be given different manifests from the same path. A link
minted without options gets the defaults: the full ladder, and subtitles as plain `.vtt`.

Only the master manifest takes this path. HLS media playlists and every segment are the same
bytes for every viewer, and keep going from the edge's cache or straight from the bucket.

**If the API cannot be reached**, the edge serves the packaged manifest from the bucket instead:
the viewer plays, without the personalization — the full ladder, and DASH subtitles in their
packaged form. That is the right trade while the options are
preferences — what a device can play, how a player wants its subtitles. It also means an option
here **hides** a rendition, it does not lock it: the link's token still authorizes the whole
playback directory.

## Caching

The delivery layer caches CMAF **segments** locally (or at the CDN edge) so repeated requests don't hit S3 every time. **Master manifests** are built by the API, which keeps each built variant for 30 seconds, shared by every viewer who asked for the same thing — so a playback start costs one request to the API and no trip to S3 — and the edge writes each viewer's token into the result on the way out. HLS media playlists are cached at the edge for 30 seconds, as before. Manifests and subtitle cues are served gzip-compressed. A self-hosted proxy node caches into its own disk pool, sized automatically — see [Nodes: Cache disks](/guide/nodes#cache-disks).

## Bandwidth Monitoring

On self-hosted proxy nodes a Vector.dev pipeline parses the access logs and ships bandwidth data to the API (`POST /api/internal/bandwidth`, secured by a shared internal secret), which sums them into per-video, per-IP daily usage rows for analytics.
