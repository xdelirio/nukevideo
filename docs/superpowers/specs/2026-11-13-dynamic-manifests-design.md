# Dynamic manifests, starting with standalone-VTT subtitles in DASH

Status: approved 2026-11-13, with the resolution cap added to the first phase

## Intent

Today a manifest is a static file: the packager writes it to S3 and every viewer of a video gets
the same bytes, with only the token rewritten in by the edge. The owner wants manifests that are
built **when they are requested**, so one video can be delivered differently per viewer: different
subtitle delivery now, quality limits, track selection and ads later.

The first use is subtitles. In DASH they are packaged as fragmented-MP4 WebVTT (`wvtt`), which some
players cannot read. A caller that asks for `subtitlesFormat=vtt` when minting playback links gets
a DASH manifest whose text tracks point at plain `.vtt` files instead.

The mechanism matters as much as the feature: it must be the base the later personalizations plug
into, without another change to the edge or to the request path.

## Goals

- Every **master** manifest request (`{output}.mpd`, `{output}.m3u8`, capped variants included) is
  answered by the API, not by S3. Decided by the owner: no opt-in flag, all traffic takes this path.
- Per-link options are chosen at mint time and are **not visible in the URL**. They are stored
  server-side, keyed by the link's token, for as long as the link is valid.
- `subtitlesFormat=vtt` on `POST videos/{video}/play` and `POST videos/play` makes the DASH
  manifests of that answer carry subtitles as sidecar WebVTT files.
- `resolution` on the same endpoints stops selecting a pre-generated capped file. It is stored with
  the link's options and applied on request: renditions above the cap are dropped, the ones at or
  below it stay. Same rule as today ({@see Output::resolveCap}), so answers do not change.
- Adding a new personalization later is one new class plus one new option field. No edge change,
  no new endpoint.

## Non-goals

- Audio-track filtering, ads. Later phases, on this base.
- Deleting the pre-generated capped manifests (`{ulid}.720.mpd`) or the code that writes and
  maintains them. Nothing mints them any more after this change, but links minted before it still
  name them for up to their lifetime, and removing them touches the packager and every manifest
  edit path. A cleanup of its own.
- Changing HLS subtitles. HLS already delivers raw WebVTT, and HLS has no way to reference a bare
  `.vtt` without a media playlist. `subtitlesFormat=vtt` is a no-op for HLS manifests.
- Routing HLS **media** playlists (`{streamUlid}/index.m3u8`) or segments through the API. They
  keep going edge → S3, cached as today.
- Enforcing anything. Hiding a track from a manifest does not block its segments; that belongs to
  the phase that introduces restrictions.

## Anti-goals

- A viewer must never be able to change their options by editing the URL.
- The edge must never serve one viewer's personalized manifest to another viewer.
- The 30-second staleness bound that track add/remove/relabel rely on today must not get worse.

## How it works today (what this changes)

- `PackageVideoJob` writes manifests to `{videoUlid}/play/` on primary S3.
- `VodLinkService` mints one signed link per output and format. The token's ACL is
  `/{videoUlid}/play/*`. `TrackingRegistry` already records `hash(token) → trackingId` in the cache.
- The edge (`vod/nginx/nginx.conf.template`, `location /`) validates the token, fetches from S3
  through the loopback hop, caches manifests 30 s keyed on `$uri`, and `secure_token` appends the
  viewer's token to every URL in the body — including every `<BaseURL>` of an MPD (verified in the
  module source at the pinned commit: `BaseURL` is in `mpd_nodes`).
- Subtitles in `play/{streamUlid}/`: DASH has `init.mp4` + one `.m4s`; HLS has `index.m3u8` + one
  raw `.vtt` segment. The clean standalone `.vtt` lives in the **download** zone
  (`Stream::storedPath`), which a playback token does not authorize.

## Approaches

**Where the manifest is transformed**

1. *Edge asks the API for master manifests* — **chosen.** The API reuses the PHP manifest code and
   tests that exist (`ManifestEditor`), and a service that decides per request is what ads will
   need anyway. Cost: the API is in the path of every playback start (one request per master
   manifest, the same order as the mint that preceded it).
2. *Transform inside nginx (njs/Lua).* No API dependency, but a second manifest parser in another
   language, a new nginx module in the image, and no path to ads. Rejected.
3. *Pre-generate variants at package time* (as the capped manifests are). No request-time work, but
   the variants multiply with every option, and per-viewer content is impossible. Rejected.

**How options reach the transformer**

1. *Signed fields in the token* (like `limit`). Stateless, but the owner does not want options in
   the URL, and the token grows with every feature. Rejected by the owner.
2. *Server-side, keyed by the token hash* — **chosen.** Same pattern as `TrackingRegistry`.
3. *An opt-in marker in the token (`~dyn=1`) so only personalized links reach the API.* Proposed
   and rejected by the owner: one path for everything is simpler to reason about and operate.

**Where the sidecar `.vtt` lives**

1. *A copy in `play/{streamUlid}/`* — **chosen.** It sits inside the playback token's ACL, the URL
   is relative, `secure_token` signs it like any segment, tracking attributes it, and deleting the
   stream's segment directory deletes it too.
2. *Point at the download-zone file.* The playback token does not authorize that zone, and
   `secure_token` would append it to the URL regardless. Rejected.
3. *Reuse the HLS raw `.vtt` segment.* Exists only for videos packaged with HLS, and carries an
   `X-TIMESTAMP-MAP` header meant for HLS. Rejected.

## Design

### Request flow

```
player ── GET /{video}/play/{output}.mpd?__hdnea__=T ──▶ edge
edge: validate token (unchanged) ─▶ GET {INTERNAL_API_URL}/manifests/{video}/{file}
                                     Authorization: Bearer {INTERNAL_API_SECRET}
                                     X-Link-Token: T
API:  options  = ManifestOptionsRegistry::resolve(sha256(T))     (absent ⇒ defaults)
      source   = manifest bytes from primary S3                  (cached 30 s per path)
      body     = each applicable ManifestTransformer, in order
      respond with the manifest's exact Content-Type, uncompressed
edge: secure_token rewrites URLs with T (unchanged) ─▶ gzip ─▶ player
```

### Edge (`vod/nginx/nginx.conf.template`, `vod/entrypoint.sh`)

- A new location for master manifests only:
  `location ~ "^/[0-9A-Za-z]{26}/play/[^/]+\.(mpd|m3u8)$"`. It repeats the token validation,
  `secure_token`, CORS, `limit_rate` and OPTIONS handling of `location /` (nginx does not inherit
  them across sibling locations), and proxies to the API instead of `edge_origin`.
- **Not cached at the edge.** The response depends on the token, and a `$uri` key would hand one
  viewer's manifest to another. Caching is the API's job (below).
- `Accept-Encoding` is cleared towards the API: `secure_token` cannot rewrite a compressed body.
- Two new variables for the edge container, `INTERNAL_API_URL` and `INTERNAL_API_SECRET`. Both are
  already delivered to proxy nodes for Vector; the deploy passes them to the edge as well.
- If the API answers 5xx or times out, the edge falls back to the static manifest from S3
  (`error_page 502 503 504 = @static_manifest`). A viewer gets an un-personalized manifest rather
  than no playback. **This must be revisited when restrictions arrive** — a restriction has to fail
  closed. *(My call.)*
- An edge started without `INTERNAL_API_URL` serves manifests statically, as today, so a node
  that has not been redeployed keeps working. *(My call.)*

### API

- **Route**: `GET internal/manifests/{video}/{file}` beside `internal/bandwidth`, behind
  `VerifyInternalSecret`. No Sanctum, no project resolution: the edge has already validated the
  token, whose ACL names the video. `{file}` is constrained to `[0-9A-Za-z.]+\.(mpd|m3u8)`, so the
  path cannot leave `play/`.
- **`App\Data\Video\ManifestOptions`** (Spatie `Data`): what a link asked for. Two properties today:
  `resolution` (nullable int) and `subtitlesFormat` (`App\Enums\SubtitleFormat`: `segments` — the default — or `vtt`). Has
  `isDefault()` and a stable `cacheKey()` (hash of its values).
- **`App\Services\Cdn\ManifestOptionsRegistry`**: `recordMany(list<SignedLink>, ManifestOptions,
  ?int $lifetime)` and `resolve(string $tokenHash): ManifestOptions`. Cache key
  `link-manifest:{hash}`, TTL = the link's lifetime plus a short grace. Default options are not
  stored; an absent key resolves to defaults. Modelled on `TrackingRegistry`.
- **`App\Services\Manifest\ManifestTransformer`** (interface):
  `applies(ManifestOptions $options, string $format): bool` and
  `transform(string $body, string $format, Video $video, ManifestOptions $options): string`.
- **`App\Services\Manifest\DynamicManifestService`**: loads the source manifest (404 when S3 has
  none), short-circuits when no transformer applies, otherwise runs them in a fixed order and
  caches the result for 30 s under `{path}:{options->cacheKey()}`. The source bytes are cached 30 s
  per path too, so the staleness after a track edit stays what it is today.
- **`App\Http\Controllers\ManifestController`**: thin — resolve options from the `X-Link-Token`
  header, call the service, respond with `application/dash+xml` or
  `application/vnd.apple.mpegurl`.
- **Mint**: `PlayVideoData` and `PlayVideosData` gain `subtitlesFormat` (camelCase input mapper on
  the property, rule in `rules()`). `VodLinkService` builds a `ManifestOptions` and records it for
  the manifest links it signed, next to the existing `TrackingRegistry::recordMany` call. An
  unsigned deployment (`token_secret` empty) has no token to key on: asking for a non-default
  option there is a 422, the same way a speed limit is refused.

### Subtitles as sidecar VTT

- **`SubtitleFormatTransformer`** applies when `subtitlesFormat = vtt` and the format is `dash`.
  For each text `AdaptationSet` it finds the stream ULID from `SegmentTemplate@media` (the way
  `ManifestEditor` does), and — only if that stream has a published sidecar — rewrites the set in
  place: `mimeType="text/vtt"`, no `codecs`, no `SegmentTemplate`, and a
  `<BaseURL>{streamUlid}/{Stream::SIDECAR_VTT}</BaseURL>` in its `Representation`. Language, label,
  roles and ids are untouched. A track without a sidecar keeps its `wvtt` form, so a video that
  has not been backfilled still shows every subtitle.
- **The sidecar file**: `play/{streamUlid}/sub.vtt` (`Stream::SIDECAR_VTT`), a byte copy of the
  download-zone `.vtt`. Written wherever a subtitle's packaged segments are written:
  `PackageVideoJob` (initial run), the add-track path (`TrackPublisher`), and
  `RepairSubtitlesCommand`.
- **Knowing it exists** without an S3 round trip per request: a boolean in the stream's existing
  `meta` JSON (`sidecar_vtt`), set when the copy is uploaded. No migration.
- **Backfill**: `videos:repair-subtitles` gains the copy step, so running it over existing videos
  publishes their sidecars. It only adds files.

### Resolution cap

- **`MaxResolutionTransformer`** applies when `options.resolution` is set, for both formats. It
  resolves the output from the manifest's filename, asks `Output::resolveCap($resolution)` for the
  cap (null ⇒ nothing to do), and removes every video rendition of that output whose height is
  above it — by stream ULID, with the removal `ManifestEditor` already performs for a deleted
  stream (DASH `Representation` + recomputed set maxima; HLS `EXT-X-STREAM-INF` and I-frame
  variants). Audio and text are untouched.
- **Mint**: `VodLinkService` always signs the uncapped manifest path and puts `resolution` in the
  link's `ManifestOptions`. `Output::manifestPath`'s `$cap` argument stops being used by the mint.
- Runs before the subtitle transformer; the two are independent.
- With the API down the edge's static fallback serves the full ladder. Acceptable: the cap states
  what a device can play, it is not an entitlement.

### Docs

`docs/api/` entry for the new `subtitlesFormat` field; `docs/guide/streaming.md` and `cdn.md` for
the request path (master manifests are answered by the API) and the two edge variables.

## Constraints

- `vod/` and the node deploy are infrastructure; the owner approved changing them for this.
- The response `Content-Type` must be exact, or `secure_token` does not rewrite the body.
- Proxy nodes need a redeploy to pick up the new edge config; until then they serve statically.
- No new dependencies.

## Testing

- Pest, with fixture MPD/M3U8 manifests: the transformer (sidecar present, absent, mixed, no text
  tracks, HLS untouched); the registry (default not stored, TTL follows the link); the endpoint
  (secret required, unknown token ⇒ default manifest, missing manifest ⇒ 404, path traversal
  refused, content types); the mint (option recorded for manifest links, 422 when unsigned).
- Manual, on the local stack: mint with `subtitlesFormat=vtt`, load the DASH link in Shaka and
  dash.js, confirm the `.vtt` request carries the token and returns 200; stop the API and confirm
  the fallback serves the static manifest.

## Left to the builder

- The exact nginx spelling of the new location and the fallback, and how much of `location /` can
  be shared through an `include` instead of repeated.
- Whether the 30 s caches use `Cache::remember` or `flexible`.
- The grace period of `ManifestOptionsRegistry`.

## As built (differences from the design above)

- **Edge**: no new outer location. `location /` keeps doing everything it did and picks its
  upstream by path: master manifests go to `manifest_origin` — a loopback server (`:8081`) that
  proxies to the API, with the existing signing server (`:8080`, the bucket) as its `backup`. The
  fallback is therefore the unchanged static path, reached through `proxy_next_upstream`, and it
  also covers an API that answers 403/404 (not upgraded yet, or a different secret).
- **Secret**: the edge is given `INTERNAL_API_SECRET` and the route uses `VerifyInternalSecret`,
  as designed. A derived, manifest-only secret was built and removed at the owner's request, to
  keep the code small; the test that kept that secret out of the edge container was relaxed.
- **Sidecar presence is not checked** (owner's decision): every text track is pointed at its
  `sub.vtt`. Existing videos must be backfilled before this is deployed, or their DASH subtitles 404.
- **The cap is computed from the manifest's own dimensions**, not from `Output`; the endpoint
  runs no database query.
- **No backfill command.** Existing videos get their sidecars by a manual copy in production
  (`download/subtitle/{file}.vtt` → `play/{streamUlid}/sub.vtt`); until then they keep `wvtt`.
- **Unsigned deployments** refuse any non-default option (`resolution`, `subtitlesFormat=segments`)
  with a 422; the mint no longer names a capped manifest in any case.
- **Plain WebVTT is the default** (owner's decision after trying it): a link that asks for
  nothing gets its DASH subtitles as `.vtt`, and `subtitlesFormat=segments` asks for the packaged
  form. Defaults are still what is not stored; they are just no longer "the manifest as packaged".
- **Options are named by the token, not filed under it.** Two viewers minting the same video in
  the same second hold the same token, so a per-token entry crossed their options. The token now
  carries `~mo={16-hex key of the options}` (signed, opaque) and the registry keeps one entry per
  distinct set of options, renewed by each mint.
- **One cache entry per (manifest, options)**, 30 s. The separate cache of the source manifest
  was dropped.
