# CDN & Delivery

NukeVideo serves the same [static CMAF](/guide/streaming) (shared HLS + DASH segments on S3) through one of two delivery modes. You pick the mode per deployment in the admin panel under **CDN Settings** by setting the `provider` field to `self_hosted` or `bunny`.

Both modes gate access with time-limited tokens and read the packaged output from your S3 bucket. Only the front door differs.

## Self-Hosted Proxy Nodes

`provider: self_hosted`

Proxy nodes are servers you run and manage from the admin panel (over SSH — see [Nodes](/guide/nodes)). Each runs a custom nginx build that:

- Validates Akamai-style stream tokens (HMAC) on incoming requests.
- Reads the pre-packaged CMAF segments from S3 using AWS authentication.
- Caches segments on the node's own disk pool so repeat requests don't hit S3 every time; **manifests are cached for 30 seconds** (each viewer still gets their own token written into them), and **downloads never enter it** (they are whole files, fetched once, and must keep supporting `Range` resumes). See [Cache disks](/guide/nodes#cache-disks).
- Embeds the token a manifest was requested with into the segment URLs it rewrites, rather than minting one per manifest, so every request of a playback session carries the same token — the one the API recorded a caller's tracking id against. The token is logged and hashed on the node; nothing downstream sees it. See [Requesting a Playback URL](/guide/streaming#requesting-a-playback-url).
- Logs, per request, what its cache did and what it fetched from the origin; the nodes page turns that into a hit ratio per node. Origin bytes are recorded under the `origin_bytes` metric and account `0` — the operator's cost, never a customer's usage.
- Answers CORS itself, for any origin: the bucket's CORS rules play no part in self-hosted delivery, and one cached copy of a segment serves every embedding site.
- Resolves the real client IP behind Cloudflare or another reverse proxy.
- Ships access-log bandwidth to the API through a Vector.dev pipeline.

Token validation is configured in **CDN Settings** under the `self_hosted` provider (token secret, token name, token window, IP binding). These are injected into the proxy container at deploy time — see [Configuration: Proxy Node Delivery](/guide/configuration#proxy-node-delivery).

### IP binding

By default a playback link plays from anywhere until it expires. Turn on **Bind playback links to
the viewer's IP** and every playback token is signed with the address it was minted for — the `ip`
the request named, or the caller's own — and the edge answers `403` to any request from another
address: the manifest and every segment, since they share the token. The address is signed, so it
cannot be edited out of the URL.

- **Integrations must mint with the viewer's `ip`.** A backend that mints without it binds every
  link to its own server's address, and nothing plays.
- **An address change ends the session.** A phone moving from Wi-Fi to mobile data, or a dual-stack
  viewer who reached the API over IPv4 and the edge over IPv6, gets a `403` and has to request a new
  link. Addresses are compared exactly; IPv6 is not widened to a `/64`.
- **Downloads are not bound**: their tokens carry no address, so a download manager can resume from
  anywhere.
- **It applies to links minted from then on**, with no redeploy. Turning it off does not release
  links minted while it was on: they stay bound until they expire.

**Choose self-hosted when** you want full control over delivery, keep traffic on your own infrastructure, need the built-in bandwidth analytics per node, or already run edge servers.

## Bunny CDN

`provider: bunny`

Instead of running proxy nodes, point a [Bunny](https://bunny.net/) pull-zone at your S3 bucket as its origin. Bunny pulls the packaged CMAF on demand and handles edge caching globally — there is no delivery infrastructure for you to operate.

Access is protected with Bunny's **token authentication** (HMAC-SHA256) in **directory mode**: the token is embedded as a path prefix scoped to the video's directory (via `token_path`), so the manifest **and all of its relative segments** authenticate under a single token. The client's IP is **not** folded into the signature, so tokens work across CDN edges and roaming clients.

Configure it in the admin panel under **CDN Settings** with the `bunny` provider:

| Setting | Description |
|---------|-------------|
| **Host** | The Bunny pull-zone hostname that fronts your S3 origin |
| **Token key** | The pull-zone's URL-signing key, used to sign the HMAC token |
| **Token window** | How long a link stays valid, in seconds, when the request does not ask for its own with `expiresIn` (up to a week) |
| **API key** | Account API key, used to pull request logs for bandwidth analytics (optional) |
| **Pull zone ID** | The pull zone's numeric id, for the same log ingestion (optional) |

Bunny is configured entirely from the panel (stored in the database); it does not use `.env` variables.

With the API key and pull zone ID set, the scheduler polls Bunny's [Logging API](https://bunny.net/docs/cdn/logging) every five minutes (`bunny:ingest-logs`) and feeds per-video bandwidth into the same analytics the self-hosted edges use.

### How the log ingestion works

`bunny:ingest-logs` reads `GET https://logging.bunnycdn.com/v2/pullzones/{id}/logs` and writes to the
same `usage` table the self-hosted Vector pipeline feeds, so both providers answer the same
[Analytics API](/api/analytics) and the same [Usage API](/api/analytics#usage).

- **Window and cursor.** Each run reads from where the last one stopped up to two minutes ago, so a
  window is only read once Bunny has finished writing it. The cursor lives in the cache; losing it
  (a flush, a first run) falls back to a ten-minute lookback rather than a wide re-read, because
  `usage` is a `SummingMergeTree` where a re-read would double-count.
- **Retention.** Bunny keeps logs for **3 days** and rejects an older `from` outright. If ingestion
  is down longer than that, the traffic in the gap is gone — there is no backfill.
- **What is counted.** Only `2xx` responses (including `206`, which is what a resumed or ranged
  download reports) whose path contains a video ULID. Bytes served with a `403` from an expired
  token or a `404` are not billed to anyone.
- **Dating.** Every line is dated by its own timestamp in UTC, not by the run, so a window that
  straddles midnight splits across the two days instead of landing on one.
- **Zones.** The directory after the video ULID — `play`, `download`, `assets` — decides which
  metric the bytes land under. It is read from the logged path, the only place the distinction
  survives; a path whose zone cannot be read still counts, under the generic `bandwidth_bytes`.
- **Tracking ids.** Never in the URL. The mint records each link's token against the caller's id,
  and the ingest hashes the token a logged line carries — the `bcdn_token=` prefix every segment
  of a playback session inherits, or the `token` query parameter of a download, both of which the
  v2 log's `path` keeps — and hands the hash to the same job the self-hosted edges feed, which
  resolves it. The mapping lives in the cache for the token's lifetime plus a margin: a token the
  ingest no longer recognises — expired, minted before the mapping existed, a cache restart —
  costs the label, never the bytes.
- **Client IPs.** If the pull zone has **Log IP Anonymization** enabled (Bunny's default), Bunny
  zeroes the last octet before you ever see the line. Bandwidth totals are unaffected, but the
  "unique IPs" figures become an approximation. Turn it off in the Bunny panel if you need exact
  counts.

Run it by hand — to catch up after downtime, or to check the credentials — with an explicit window:

```bash
php artisan bunny:ingest-logs --from=2026-08-20T10:00:00Z
```

**Choose Bunny when** you want a global CDN without operating proxy nodes, need to scale delivery quickly, or prefer to offload edge caching and bandwidth entirely to a managed provider.

## Which Should I Use?

| | Self-hosted proxy nodes | Bunny CDN |
|---|---|---|
| Infrastructure to run | Your own proxy servers | None (managed by Bunny) |
| Origin | S3 (via AWS auth) | S3 pull-zone origin |
| Token scheme | Akamai-style HMAC | HMAC-SHA256, directory mode |
| IP binding | Optional, off by default ([IP binding](#ip-binding)) | Not IP-bound |
| Edge caching | Local nginx cache per node | Bunny global edge |
| Bandwidth analytics | Built-in (Vector → API) | Built-in (Logging API poll) |

Both serve identical CMAF, so you can start with one and switch by changing the CDN Settings provider — no re-packaging required.
