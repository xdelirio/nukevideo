# Configuration

NukeVideo is configured through environment variables. This page documents the settings that matter; the **Default** column is the value `.env.example` ships with, or the code's fallback where it has none.

The **Used in** column indicates where each variable is used:

- **API** — The Laravel application (API, scheduler and Horizon on the main host)
- **Proxy** — Self-hosted CMAF delivery proxy nodes
- **Worker** — FFmpeg encoding nodes

Nodes have no `.env` of their own. A deploy copies the database, Redis, S3, Sentry, `WEBHOOK_SECRET` and `INTERNAL_API_SECRET` values from the API host's environment into every node container, and adds what it sets itself; see [Node Environment Variables](#node-environment-variables) for the rest.

## Application

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `APP_ENV` | `local` | API | Environment (`local`, `production`) |
| `APP_DEBUG` | `true` | API | Enable debug mode (disable in production) |
| `APP_URL` | `http://localhost` | API | Base URL for the API |
| `APP_KEY` | — | API | Application encryption key (generate with `php artisan key:generate`) |
| `WEBHOOK_SECRET` | — | API | Secret for the storage upload webhook (`POST /api/webhooks/video-uploaded`): checked as an HMAC-SHA256 of the body when the bucket sends `x-e2-notification-signature`, otherwise as the bearer token |
| `INTERNAL_API_SECRET` | — | API, Proxy | Shared secret for `POST /api/internal/bandwidth`, where Vector on proxy nodes reports bandwidth |
| `INTERNAL_API_URL` | `APP_URL` | API | Base URL nodes use to reach the API; the deploy hands them `{INTERNAL_API_URL}/api/internal`. Set it when nodes reach the API at a different address than `APP_URL` (a LAN address, say) |
| `DOCKER_REGISTRY` | — | API | Registry the node images are pulled from. Unset means Docker Hub (`chikenare/nukevideo-{api,proxy}`, tagged with the panel's version). A development panel (`APP_ENV=local`) refuses to deploy without it and pulls the `node-dev` tag that `bin/push-node-dev` builds and pushes |

## Database

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `DB_CONNECTION` | `mysql` | API, Worker | Database driver |
| `DB_HOST` | `db` | API, Worker | Database hostname |
| `DB_PORT` | `3306` | API, Worker | Database port |
| `DB_DATABASE` | `laravel` | API, Worker | Database name |
| `DB_USERNAME` | `root` | API, Worker | Database user |
| `DB_PASSWORD` | — | API, Worker | Database password |

## Redis

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `REDIS_HOST` | `127.0.0.1` | API, Worker | Redis hostname |
| `REDIS_PORT` | `6379` | API, Worker | Redis port |
| `REDIS_PASSWORD` | `null` | API, Worker | Redis password |
| `REDIS_CLIENT` | `phpredis` | API | Redis client (not propagated to nodes, which use the default) |

## S3 Storage

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | — | API, Worker, Proxy | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | — | API, Worker, Proxy | S3 secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | API, Worker, Proxy | S3 region |
| `AWS_BUCKET` | — | API, Worker, Proxy | S3 bucket name |
| `AWS_ENDPOINT` | — | API, Worker, Proxy | S3 endpoint URL (for MinIO/RustFS) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | API, Worker | Use path-style URLs (required for MinIO) |

## Proxy Node Delivery

These values control token validation on self-hosted proxy nodes, which serve the pre-packaged CMAF from S3. They are **not** set in `.env` — they live in the **CDN Settings** panel (the `self_hosted` provider) and are injected into the proxy's nginx container at deploy time under the names below. Leaving one empty falls back to the container's default.

The segment cache normally needs no configuration: the node's deploy builds it from the host's spare disks and the edge sizes it to that pool on boot. See [Cache disks](/guide/nodes#cache-disks), and [Proxy cache](#proxy-cache) for the knobs that exist anyway.

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `VOD_TOKEN_SECRET` | — | Proxy | Secret for signing and validating stream tokens |
| `VOD_ALLOWED_DOMAINS` | empty | Proxy | Comma-separated sites whose pages the edge serves, from **Allowed domains**; empty allows any site. Changing it requires redeploying the proxy. See [Allowed domains](/guide/cdn#allowed-domains) |
| `VOD_TOKEN_NAME` | `__hdnea__` | Proxy | Query argument carrying the token. Lowercase, digits and `_` only; changing it requires redeploying the proxy, and links already signed with the old name stop validating |

## CDN

Delivery is chosen per deployment in the admin panel under **CDN Settings**, using the `provider` field:

- `self_hosted` — Deliver through your own proxy nodes (uses the [Proxy Node Delivery](#proxy-node-delivery) settings above).
- `bunny` — Deliver through a Bunny CDN pull-zone pointed at your S3 origin. Configure the pull-zone **host**, **token key**, and **token window** in the panel.

Bunny is configured entirely from the panel (stored in the database), not through `.env`. See [CDN & Delivery](/guide/cdn) for details on both modes.

## Video Processing

Variables that control FFmpeg concurrency on worker nodes. They are read on the node, so they belong in the [node environment](#node-environment-variables), not in the API's `.env`.

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `VIDEO_WORKER_PROCESSES` | auto | Worker (CPU) | Concurrent FFmpeg processes on a CPU node for renditions up to 1080p. Auto-sized from the hardware the node sees: the smaller of `floor(cores / 5)` and `floor(usable RAM / 2.75 GB)`, where usable RAM is total minus 10% (min 4 GB) left to the OS, the chunk store, packaging and Horizon. Never below 1. Each chunk also waits (up to 90 s) for the memory its picture needs to be free before it starts. Explicit value wins. |
| `HEAVY_WORKER_PROCESSES` | auto | Worker (CPU) | Concurrent FFmpeg processes for renditions above 1080p, which queue on `video-processing-heavy` and never share the common pool: one 2160p encode holds about 12 GB. Auto-sized from the memory left once the common pool is full, in whole 2160p encodes, between 1 and 2. Each job also waits for that memory to be free before it starts. Explicit value wins. |
| `GPU_WORKER_PROCESSES` | auto | Worker (GPU) | Concurrent encodes on a GPU node (`intel` or `nvidia` acceleration), which drains only its `video-processing-{accel}` queue and never the CPU one. Throughput is bound by the media engine's sessions, not cores, so the default is the smallest of 6 (where a measured Arc B580 flattens out), `cores − 2` and `floor((RAM × 0.8 − 2 GB) / 1 GB)`, the last two never below 2. Explicit value wins. |
| `VIDEO_FFMPEG_THREADS` | `4` | Worker | Threads per encoder (SVT-AV1 `lp`). Fixed rather than a share of the node: more, leaner encodes move more pixels in less memory than fewer, fatter ones. Never more than the node has. Explicit value wins. |
| `PROBE_CONCURRENCY` | auto | Worker | Sample encodes a per-title probe runs at once: one per 16 cores, between 2 and 4. Explicit value wins. |
| `CHUNK_OOM_SCORE_ADJ` | `1000` | Worker | `oom_score_adj` every chunk FFmpeg raises itself to (probes take half), so that when memory runs out the kernel kills one encode — which is released and retried — and not a Horizon worker or the chunk store. `0` switches it off. |
| `VIDEO_WORKER_TIMEOUT` | `600` | Worker | Per-chunk wall-clock ceiling in seconds, and the transcode supervisors' job timeout. FFmpeg gets this minus 120 s, and chunk windows are planned against that. Set by the deploy on every node (`NodeService::WORKER_TIMEOUT`) and not overridable: chunks are planned on one node and encoded on another, and the stuck-video reaper and the redeploy drain window read the same value, so it has to be the same across the fleet. The API's `.env` value never reaches a node. |
| `DISABLE_PACKAGING` | `false` | Worker | `true` keeps the `packaging` supervisor off this worker. Every worker packages by default — the safe failure is packaging on too many nodes, not on none — so leave at least one worker without it. |
| `DOCKER_MEMORY` | — | Worker | Passed to `docker run --memory` (e.g. `8g`) instead of into the container's environment. |
| `DOCKER_CPUSET_CPUS` | — | Worker | Passed to `docker run --cpuset-cpus` (e.g. `0-7`). |

On a node that shares its box with other services, set `DOCKER_MEMORY` (and optionally `DOCKER_CPUSET_CPUS`): the worker container gets that limit, the auto-sizing above budgets against it instead of the whole host, and an encode that overruns takes the worker down — not whatever else the host is running. Both are read from the global node environment and then the node's own `env`, the node winning, like every other variable.

## Analytics

Bandwidth, upload volume and encoding time are stored in MariaDB, in `usage_daily`: one row per day
per combination of video, viewer address, tracking id, node and cache status, summed as the traffic
arrives. There is nothing to configure — it uses the application's own database connection, and
Vector never writes to it directly: it posts to the API's internal bandwidth endpoint and receives
only `INTERNAL_API_URL` and `INTERNAL_API_SECRET`.

### Viewer country

Each delivery row can carry the viewer's country, resolved from the address when the traffic is
ingested, which makes `country` a dimension of [`/api/metrics`](/api/analytics). It is optional and
needs a free [MaxMind GeoLite2](https://www.maxmind.com/en/geolite2/signup) account:

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `MAXMIND_LICENSE_KEY` | — | API | MaxMind license key. A new key takes a few minutes to become valid |
| `MAXMIND_ACCOUNT_ID` | — | API | MaxMind account ID. Optional but recommended: with it the key is sent as credentials instead of in the download URL |

With the key set, the GeoLite2-Country database is downloaded when the next traffic is ingested and
refreshed weekly, on the container that runs Horizon. To fetch it right away:

```bash
docker compose exec nukevideo-horizon php artisan geoip:update
```

Without a key nothing is downloaded and `country` stays empty; everything else works the same.
Traffic ingested before the database was there keeps an empty country unless the same viewer
returns that day. If you show these numbers publicly, MaxMind's licence asks for the attribution
"This product includes GeoLite2 data created by MaxMind, available from https://www.maxmind.com".

## Monitoring

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `SENTRY_LARAVEL_DSN` | — | API, Worker | Sentry DSN for error tracking |
| `SENTRY_TRACES_SAMPLE_RATE` | `1.0` | API, Worker | Sentry trace sampling rate (0.0 – 1.0) |

## Mail

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `MAIL_MAILER` | `log` | API | Mail driver (`smtp`, `ses`, `log`) |
| `MAIL_HOST` | `127.0.0.1` | API | SMTP host |
| `MAIL_PORT` | `2525` | API | SMTP port |
| `MAIL_USERNAME` | `null` | API | SMTP username |
| `MAIL_PASSWORD` | `null` | API | SMTP password |

## Node Environment Variables

Nodes are configured from the panel, not from a file on the node. There are two layers, both plain text of `KEY=VALUE` lines (blank lines and lines starting with `#` are ignored):

- **The node environment** — one for every node, workers and proxies alike; a worker receives all of it, and a proxy only the variables it reads (see [Proxy Nodes](#proxy-nodes)). Managed from the panel or through `GET` / `PATCH /api/node-environment` (admin only), whose body is `{"environment": "KEY=VALUE\n..."}`. The same endpoint holds the [chunk store](/guide/nodes#chunk-store) address.
- **A node's own `env`** — per-node overrides, edited on the node (the `env` field of `PATCH /api/nodes/{node}`). A key set here wins over the node environment.

Both are layered over what the deploy sets itself: the values copied from the API host (see the tables above), `APP_ENV`, `APP_DEBUG`, `NODE_ACCEL`, `VIDEO_WORKER_TIMEOUT`, `REDIS_QUEUE_RETRY_AFTER`, `DOMAIN`, `VOD_BASE_URL`, `CHUNKS_S3_ENDPOINT` (workers only, from the [chunk store](/guide/nodes#chunk-store) address) and the CDN token settings. An override replaces any of those — a node that reaches Redis at another address sets its own `REDIS_HOST`, for instance — except the deploy-owned keys, which make a node part of *this* installation or must match across the fleet, and are dropped from both layers (with a warning in the log):

`APP_KEY`, `APP_URL`, `API_UPSTREAM_HOST`, `NODE_ID`, `NODE_TYPE`, `INTERNAL_API_URL`, `INTERNAL_API_SECRET`, `WEBHOOK_SECRET`, `VOD_TOKEN_SECRET`, `VOD_TOKEN_NAME`, `VOD_ALLOWED_DOMAINS`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `VIDEO_WORKER_TIMEOUT`, `REDIS_QUEUE_RETRY_AFTER`.

Everything here is applied when the container is created, so a change takes effect on the node's next **redeploy**.

### Proxy Nodes

Proxy containers receive only what they read: `NODE_ID`, the [S3 Storage](#s3-storage) credentials, bucket, region and endpoint (to read packaged CMAF from the bucket), the token settings from [Proxy Node Delivery](#proxy-node-delivery), which are sourced from the CDN Settings panel, and the tuning below. Any other variable stays out of them, wherever it is set, including database and Redis credentials, `APP_KEY` and the webhook and internal secrets.

They sign with the main S3 key unless **CDN Settings** holds a key for the proxies, which is the better setup: they only ever read, so give them a key limited to `s3:GetObject` on the bucket, and one leaked from a proxy cannot delete or rewrite anything. Workers keep the main key. The secret is never returned by the API. A change applies when the proxy nodes are redeployed, and a key that cannot read the bucket fails every cache miss with a `403`, so try it on one proxy first.

| Variable | Default | Description |
|----------|---------|-------------|
| `VOD_S3_TLS_VERIFY` | `on` | Whether the edge verifies the certificate of an `https` S3 endpoint against the system CAs. Set it to `off` only for an endpoint with a private or self-signed certificate: with `on`, such an endpoint fails every cache miss with a `502`. |

#### Proxy cache

The edge sizes its segment cache to the filesystem it is given on every boot. These override the result; sizes take `m` or `g` (nginx does not parse `t`).

| Variable | Default | Description |
|----------|---------|-------------|
| `VOD_CACHE_MAX_SIZE` | filesystem minus `VOD_CACHE_MIN_FREE` | nginx `max_size`: the cache manager evicts by LRU to stay under it. On a filesystem smaller than twice the reserve, half of it. The deploy sets it itself, to `10g`, only when the host has no cache pool and the cache is a docker volume on the OS disk; that value comes after the environment and wins. The edge also caps itself at `10g` when a pool was deployed but is not mounted |
| `VOD_CACHE_MIN_FREE` | 3% of the filesystem, at least 20 GB (a quarter of it on a filesystem too small for that) | nginx `min_free`: space kept free even for what `max_size` cannot see (misses still streaming in, entries the loader has not indexed after a restart) |
| `VOD_CACHE_KEYS_ZONE` | one key per 2 MB of filesystem, at least `20m` | Size of the shared zone holding the keys (~8k keys per MB) |
| `VOD_CACHE_INACTIVE` | `30d` | nginx `inactive`: entries unrequested for this long are deleted even with space to spare |

### Worker Nodes

Worker containers receive the database, Redis, S3 and Sentry settings from the API host, plus the [Video Processing](#video-processing) variables set in the node environment. `DOCKER_MEMORY` and `DOCKER_CPUSET_CPUS` apply to workers only.

### Vector (Proxy Nodes, self-hosted CDN only)

Vector runs on proxy nodes when the CDN provider is `self_hosted` — nothing else produces the access-log lines it ships, and a deploy removes it anywhere else. It reads the edge nginx's log from the Docker socket, sums bytes per video, viewer and link token on the node (the token is hashed there; it never leaves), and posts the aggregates to the API's internal bandwidth endpoint, which sums them into `usage_daily`. Its config is rendered from `vector/vector.yaml` at deploy time; to watch what it emits on a node, `docker exec <vector container> vector tap aggregate_bandwidth_logs`.
