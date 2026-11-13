# Analytics

Delivery bandwidth, viewer IPs, encoding time and account consumption, read from the `usage_daily` table. This is
the read side of the CDN access logs, which the edges ship through Vector
([CDN & Delivery](/guide/cdn)) into that table.

Everything on this page is readable with **any authenticated token** — a personal access token or a
[project API key](/api/authentication) — except [per-node delivery](#per-node-delivery), which is
admin-only. Reading these numbers back is what an integrating backend holds a key for.

What a caller may see is decided by the **project it names**, not by whether it is an administrator:
there is no operator shortcut on these endpoints, and an administrator that names no project sees
exactly what a tenant would.

::: tip Send your project and the answer is yours alone
Every read below narrows to one project when the request carries project context — the
`X-Project-Ulid` header, or a project API key, which carries its own. Scoped that way, the numbers
and the identifiers in them are your project's and nobody else's. The exceptions say so where they
appear: encoding time, the node count and the [queue](#queue) are always instance-wide, and the
[batch by tracking id](#batch-bandwidth-by-tracking-id) read never narrows to a project.

Without project context the aggregates are **instance-wide**: totals across every project on the
installation. Those name nobody, which is why they are shared at all. The breakdowns that *do* name
things — viewer addresses, viewer labels, video ULIDs — are then withheld or must be asked for by
name, because unscoped they would enumerate other tenants'.

This is not an administrator/tenant distinction. An administrator that names no project gets the
same aggregates, and the same withheld breakdowns, as anyone else.
:::

## The metric catalogue

Every number on this page comes out of one `value` column whose unit lives in the **metric name**.

| Metric | Unit | Description |
|--------|------|-------------|
| `streaming_bytes` | bytes | Delivered to players streaming — manifests and CMAF segments |
| `download_bytes` | bytes | Delivered through [track download links](/api/streams#download-a-track), and the segments of [playback URLs](/api/videos#playback-urls) minted with `download: true` for offline viewing |
| `asset_bytes` | bytes | Thumbnails and storyboards |
| `bandwidth_bytes` | bytes | Delivery that predates the streaming/download split, or whose path zone could not be read |
| `upload_bytes` | bytes | Source file size of uploaded videos |
| `encoding_cpu` | **seconds** | CPU encoding time |
| `origin_bytes` | bytes | What an edge had to fetch from S3 to serve a request. Booked under the operator, never an account — it surfaces only through [per-node delivery](#per-node-delivery) |

::: warning Never sum across metrics
`encoding_cpu` is seconds and everything else is bytes. Always constrain or group by `metric` —
summing across them adds seconds to bytes and returns a number that means nothing. Every endpoint
here refuses a metric name it does not recognise rather than answering with an empty result, because
an empty result on an invoice reads as zero rather than as a typo.
:::

The first four are the **delivery metrics**: the set every bandwidth endpoint below is constrained
to, and the only values their `metric` parameter accepts.

## Delivery Dashboard

Everything the panel's analytics screen draws, in one payload: totals, a bandwidth time series and
several top-N breakdowns.

```
GET /api/analytics?from=2026-04-01&to=2026-04-30
```

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | Yes | Start date (`YYYY-MM-DD`), inclusive |
| `to` | date | Yes | End date (`YYYY-MM-DD`), inclusive |
| `video` | string | No | Narrow every bandwidth series to one video ULID |
| `trackingId` | string | No | Narrow every bandwidth series to one tracking id. Pass it **empty** (`?trackingId=`) to isolate traffic that carried no id |
| `metric` | string | No | Narrow to one delivery metric. Omit for all of them |
| `limit` | integer | No | How many rows each flat top-N list returns — `topIps`, `topVideos`, `topTrackingIds`, `topExternalUsers`. 1–1000, defaults to `10` |
| `videoSeriesLimit` | integer | No | How many videos `bandwidthByVideo` follows. 1–100, defaults to `5`. Smaller because that one is a time series: its row count is this **times** the length of the range |

`video` and `trackingId` are matched against columns written from CDN access logs, so they are
validated to what those columns can hold — a 26-character ULID and up to 64 characters of
`A-Z a-z 0-9 _ -` respectively. Anything else responds `422`.

**Response:**

```json
{
  "data": {
    "cards": [{ "key": "total_bandwidth", "value": 5368709120, "unit": "bytes" }],
    "bandwidthOverTime": [{ "date": "2026-04-16", "bytes": 1073741824, "sessions": 42 }],
    "topIps": [{ "ip": "203.0.113.7", "bytes": 734003200, "sessions": 3 }],
    "topVideos": [{ "video": "01J...", "externalResourceId": "", "bytes": 2147483648, "sessions": 88, "uniqueIps": 71 }],
    "topExternalUsers": [{ "externalUserId": "user-123", "bytes": 52428800 }],
    "topTrackingIds": [{ "trackingId": "customer-42", "bytes": 943718400, "videos": 3, "uniqueIps": 11 }],
    "bandwidthByVideo": [{ "date": "2026-04-16", "video": "01J...", "bytes": 536870912 }],
    "encodingOverTime": [{ "date": "2026-04-16", "device": "cpu", "seconds": 1820.5 }]
  }
}
```

::: tip These lists are top-N, not breakdowns
Every `top*` list is ordered by bytes and cut at `limit`. To get the totals for values **you** name —
all of them, whether or not they rank — use [`/api/metrics`](#metrics-query) or the batch endpoints
below. That is the difference between a dashboard and an invoice.
:::

::: warning The identifier lists need project context
`topIps`, `topVideos`, `topTrackingIds`, `topExternalUsers` and `bandwidthByVideo` come back
**empty** unless the request carries project context. A total names nobody; a *list of identifiers*
is a different thing, and unscoped those five would enumerate whoever else is on the installation —
viewer addresses, viewer labels, customer labels and video ULIDs.

Send `X-Project-Ulid` (or call with a project API key) and they are populated with your project's
own. `cards`, `bandwidthOverTime` and `encodingOverTime` are unaffected — they are aggregates. Of
those, `encodingOverTime` and the `cpu_encoding` and `nodes` cards are instance-wide even with
project context; everything else narrows to the project.
:::

#### Card keys

`key` is a stable machine name and `unit` says what the number is; neither is display copy. The API
sends no labels, in any language — those belong to whatever is drawing them.

| Key | Unit |
|-----|------|
| `total_bandwidth` | `bytes` |
| `unique_ips` | `count` |
| `active_videos` | `count` |
| `tracking_ids` | `count` |
| `nodes` | `count` |
| `cpu_encoding` | `seconds` |
| `upload_volume` | `bytes` |

### One panel at a time

The same dashboard, split by panel. Each endpoint takes the query parameters above and answers with
exactly its piece of the full payload — same rules, same project context, same shape under `data`.
Request the ones you draw, all at once, and render each as it arrives: one payload is as slow as its
slowest breakdown, and nothing can show until all of them are done.

| Endpoint | Answers with |
|----------|--------------|
| `GET /api/analytics/summary` | `cards` |
| `GET /api/analytics/bandwidth` | `bandwidthOverTime` |
| `GET /api/analytics/top-ips` | `topIps` |
| `GET /api/analytics/top-videos` | `topVideos` |
| `GET /api/analytics/top-tracking-ids` | `topTrackingIds` |
| `GET /api/analytics/top-external-users` | `topExternalUsers` |

```
GET /api/analytics/top-videos?from=2026-04-01&to=2026-04-30&limit=20
```

```json
{
  "data": [{ "video": "01J...", "externalResourceId": "", "bytes": 2147483648, "sessions": 88, "uniqueIps": 71 }]
}
```

The four `top-*` endpoints follow the warning above: without project context they answer `[]`.

## Bandwidth by tracking id

`topTrackingIds` is the read side of the `trackingId` you mint links with
([Download a Track](/api/streams#download-a-track)) — it is how an external project reports the
transfers it handed to each of its own customers.

| Field | Type | Notes |
|-------|------|-------|
| `trackingId` | string | The id the link was minted for. **Empty** for traffic that has none — links minted by a project key without a `trackingId`, and links whose token → id mapping was no longer available at ingest time. Links minted from a session or personal token without a `trackingId` carry the authenticated user's ULID, so the panel's own traffic shows up under it. Reported rather than dropped, so the rows add up to `Total Bandwidth` |
| `bytes` | number | Bytes served |
| `videos` | integer | Distinct videos this id pulled |
| `uniqueIps` | integer | Distinct client IPs. Approximate when the CDN anonymizes log IPs |

::: tip Bytes are never lost to a bad label
A tracking id that does not survive the round trip through the CDN log costs its attribution, not
its bytes: the transfer is still counted, under the empty id. Bandwidth totals are always complete,
whatever happens to the labels on top of them.
:::

To read one customer's traffic on one video across a month:

```
GET /api/analytics?from=2026-04-01&to=2026-04-30&video=01J...&trackingId=customer-42
```

Every bandwidth series in the response is narrowed by the same filter, so the whole payload describes
that one slice. `topExternalUsers` (upload volume) and the encoding series come from other metrics
and are not affected.

## Metrics query

One endpoint for any breakdown of `usage` you need, so that the next question you have does not
require a new endpoint. Sum delivered bytes — or upload volume, or encoding seconds — grouped by the
dimensions you name.

```
GET  /api/metrics?from=2026-04-01&to=2026-04-30&dimensions[]=date&dimensions[]=metric&trackingIds[]=customer-42
POST /api/metrics
```

**Request Body:**

```json
{
  "from": "2026-04-01",
  "to": "2026-04-30",
  "dimensions": ["date", "metric", "tracking_id"],
  "trackingIds": ["customer-42", "reupload-9f1c"],
  "metrics": ["streaming_bytes", "download_bytes"],
  "shape": "long"
}
```

| Field | Type | Notes |
|-------|------|-------|
| `from`, `to` | date | The range, inclusive. Required |
| `dimensions` | string[] | What to group by, from the table below. Required, at least one |
| `metrics` | string[] | Which metrics to include. Defaults to the four delivery metrics |
| `videos` | string[] | Narrow to these video ULIDs, up to 1000. With project context a ULID your project does not own simply matches nothing; without it the list is taken as given |
| `trackingIds` | string[] | Narrow to these tracking ids, up to 1000 |
| `externalUserIds` | string[] | Narrow to these customer labels, up to 1000 |
| `shape` | string | `long` (default) or `wide` — see [Shapes](#shapes) |
| `measures` | string[] | What to compute beside the sum — see [Measures](#measures). Long shape only |

### Dimensions, and what each one requires

The dimensions of `usage_daily` are **not equally shareable**, and that is what the rules below are about.
The table is shared across the installation, so each dimension can only be offered as far as it can
be scoped.

| Dimension | Who | What it requires |
|-----------|-----|------------------|
| `date` | anyone | — |
| `metric` | anyone | — |
| `external_user_id` | anyone | Pins the whole query to your account, automatically |
| `video` | anyone | Project context **or** a `videos` list of your own titles |
| `ip` | anyone | Project context **or** a `videos` list |
| `country` | anyone | Project context **or** a `videos` list. ISO 3166-1 alpha-2; `""` when unknown or when no [country database](/guide/configuration#viewer-country) is configured |
| `tracking_id` | anyone | Project context **or** a `trackingIds` list |
| `node_id` | anyone | Project context — no list can stand in |
| `cache` | anyone | Project context — no list can stand in |

The ones that name something take **either** route: scope the query to a project, and they are yours
by construction; or name exactly what you are asking about, and the question is bounded to values
you already had. Without one of the two the answer would enumerate whoever else is on the
installation, so it responds `422` and says which field would satisfy it.

`node_id` and `cache` take only the first route: inside a project they say which edge served *your*
traffic and how well its cache did, which is your business — but there is no list of edges you could
name, because you own none. The fleet-wide view is the admin-only
[per-node delivery](#per-node-delivery) report.

::: tip Why those three and not `date` or `metric`
A date is not anybody's. A viewer's address, a viewer label and a video ULID are: unscoped, grouping
by one of them hands back a list of identifiers belonging to whoever happens to be on the same
installation. A viewer's address is also personal data, which is why it gets no exception.
:::

::: warning Account pinning is automatic, not optional
Grouping by `external_user_id`, filtering by `externalUserIds`, or asking for `upload_bytes` or
`encoding_cpu` adds `user_id = <your account>` to the query. Those numbers are booked per account,
and `external_user_id` is *your* customer label — reporting them across accounts would hand one
tenant another's customers. Delivered bytes carry no such pin: they are as wide as the project
scope leaves them — your project's with project context, instance-wide without.
:::

### Shapes

```json
{ "data": [
  { "date": "2026-04-16", "metric": "streaming_bytes", "value": 943718400 }
] }
```

`long` (the default) is one row per (dimensions, metric) — the honest shape of the query, and what
you want when summing for an invoice. Every row carries its `metric`, and **the unit follows the
metric**: `encoding_cpu` is seconds, everything else is bytes. Never sum across them.

`wide` pivots the metric out into one field per series and fills the days that produced no traffic
with zero:

```json
{ "data": [
  { "date": "2026-04-15", "streaming_bytes": 0, "download_bytes": 0 },
  { "date": "2026-04-16", "streaming_bytes": 943718400, "download_bytes": 52428800 }
] }
```

That is shape, not presentation — there are no labels, formats or colours anywhere in this API. It
is what a charting library asks for: one accessor per series, and a dense x axis, because a line
drawn across a day the query simply omitted interpolates traffic that never happened. A field
missing from a row means zero.

Zero fill only applies to series that appear at all: a tracking id with no traffic anywhere in the
range still gets no rows, because absence is the answer everywhere in this API.

::: warning Bounded, and it will say so
A query returning more than **50,000** rows responds `422` rather than a truncated answer, and so
does a `wide` fill that would expand past it. Shorten the range, drop a dimension, or narrow a list.
:::

### Measures

Every row carries `value`, the sum of the metric. `measures` adds what else `usage_daily` knows about
the same group:

| Measure | What it is |
|---------|------------|
| `value` | The sum, in the metric's unit. Always returned; naming it changes nothing |
| `requests` | How many requests were summed into it: segment and manifest fetches for delivery, uploads and encoded chunks otherwise |
| `unique_ips` | Distinct viewer addresses |
| `unique_tracking_ids` | Distinct tracking ids — distinct viewers, when every link carries one. Unattributed traffic is not a viewer and is not counted |

```json
{ "from": "2026-04-01", "to": "2026-04-30", "dimensions": ["video"], "measures": ["requests", "unique_tracking_ids"] }
```

```json
{ "data": [
  { "video": "01J...", "value": 2147483648, "requests": 8812, "unique_tracking_ids": 341 }
] }
```

A distinct count is counted within each group — viewers per video per day are not summable into
viewers per video, so ask for the grouping you mean. The `wide` shape carries the sum only: asking it
for another measure is a `422`.

Upload volume and encoding seconds carry their video from this release on, so `video` breaks them
down per title too. Rows booked before it carry no video and fall under `""`.

## Batch bandwidth by tracking id

`topTrackingIds` answers *who used the most*, which is a dashboard question. Billing a per-subscriber
bandwidth quota is the opposite one: the totals for the ids **you** name, all of them, whether or not
they rank. This endpoint answers that in one call.

```
GET  /api/analytics/tracking-ids?from=2026-04-01&to=2026-04-30&trackingIds[]=customer-42&trackingIds[]=reupload-9f1c
POST /api/analytics/tracking-ids
```

Both verbs run the same read and take the same fields — as a query string or as a JSON body. Use
`POST` once the list outgrows a URL: a thousand 64-character ids is roughly 80 KB of query string,
past what most proxies will put in a request line. Nothing is written either way.

**Request Body:**

```json
{
  "from": "2026-04-01",
  "to": "2026-04-30",
  "trackingIds": ["customer-42", "reupload-9f1c"],
  "metric": "streaming_bytes",
  "granularity": "total",
  "includeUnattributed": false
}
```

| Field | Type | Notes |
|-------|------|-------|
| `from` | date | Start date (`YYYY-MM-DD`), inclusive. Required |
| `to` | date | End date (`YYYY-MM-DD`), inclusive. Required |
| `trackingIds` | string[] | The ids to report on, 1 to 1000 of them. Each is up to 64 characters of `A-Z a-z 0-9 _ -` — the same alphabet the links are minted with. Duplicates are folded; an empty or null entry is rejected |
| `metric` | string | Optional. One delivery metric. Omit to get every one |
| `granularity` | string | `total` (default) for one row per id and metric over the whole range, or `daily` to add the date |
| `includeUnattributed` | boolean | Adds the traffic whose id did not survive the CDN log, under an empty `trackingId`. This is how you reconcile the sum of your own ids against the instance total |

**Response:**

```json
{
  "data": [
    { "trackingId": "customer-42", "metric": "streaming_bytes", "bytes": 943718400, "date": null },
    { "trackingId": "customer-42", "metric": "download_bytes", "bytes": 52428800, "date": null },
    { "trackingId": "reupload-9f1c", "metric": "download_bytes", "bytes": 7, "date": null }
  ]
}
```

| Field | Type | Notes |
|-------|------|-------|
| `trackingId` | string | One of the ids you asked about, or empty for the unattributed bucket |
| `metric` | string | The delivery metric these bytes were served under |
| `bytes` | number | Bytes served for that id under that metric |
| `date` | string \| null | The day, on a `daily` read. `null` when the row is the total for the whole range — the field is always present, so your type does not change shape with the query |

::: warning An id with no traffic gets no row
Ids that moved nothing in the range are **omitted**, not returned as zero — a thousand-id request
would otherwise be mostly padding. Read a missing id as zero bytes, not as an error.
:::

The breakdown is per metric rather than pre-summed, because streaming and the downloads that reupload
to a viewer's own file host are usually not the same line on an invoice. One row per
(id, metric) pair — or per (id, metric, day) when `granularity=daily` — so add them up for a single
total.

This endpoint reports on the ids you name, whatever project they belong to — so name only your own.
Unlike the rest of this page it does not narrow to project context, deliberately: traffic whose video
has since been deleted keeps its tracking id but loses its project, and dropping it would quietly
under-report the bandwidth a subscriber actually consumed.

## Batch bandwidth by video

The same read on the other dimension: per-title delivery for a list of your videos.

```
GET  /api/analytics/videos?from=2026-04-01&to=2026-04-30&videos[]=01J...&videos[]=01K...
POST /api/analytics/videos
```

| Field | Type | Notes |
|-------|------|-------|
| `from` | date | Start date (`YYYY-MM-DD`), inclusive. Required |
| `to` | date | End date (`YYYY-MM-DD`), inclusive. Required |
| `videos` | string[] | Video ULIDs, 1 to 1000 of them. Duplicates are folded |
| `metric` | string | Optional. One delivery metric |
| `granularity` | string | `total` (default) or `daily` |

**Response:**

```json
{
  "data": [
    { "video": "01J...", "metric": "streaming_bytes", "bytes": 2147483648, "date": null },
    { "video": "01J...", "metric": "asset_bytes", "bytes": 79996, "date": null }
  ]
}
```

::: tip Always scoped to your project
It requires project context — the `X-Project-Ulid` header, or a project API key, which carries its
own — and responds `400` without it. The scoping is `project_id` in the query itself, so a ULID that
is not yours simply matches nothing.

A ULID that is not yours and one that moved no bytes therefore produce the same answer — **no row** —
on purpose: answering differently would turn this into a way to find out which videos exist on the
instance.
:::

## Shared addresses

Viewer addresses that several tracking ids were served to — the read for catching one person behind
many accounts, each collecting its own free quota. Requires project context.

```
GET /api/analytics/shared-ips?from=2026-04-01&to=2026-04-30&minTrackingIds=2&maxTrackingIds=10&limit=100
```

| Parameter | Type | Notes |
|-----------|------|-------|
| `from`, `to` | date | Required |
| `minTrackingIds` | integer | How many distinct tracking ids an address needs to be listed. Default `3`, minimum `2` |
| `maxTrackingIds` | integer | Leave out addresses shared by more ids than this — a carrier NAT, a campus or a VPN serves hundreds. At least `minTrackingIds`. Omitted, no ceiling |
| `limit` | integer | How many addresses. Default `100`, maximum `1000` |
| `offset` | integer | Addresses to skip, for the next page. Default `0` |

```json
{ "data": [
  { "ip": "203.0.113.7", "country": "MX", "trackingIdCount": 3, "bytes": 52428800, "days": 12,
    "trackingIds": ["acct-1", "acct-2", "acct-3"] }
] }
```

Ordered by `trackingIdCount`, then bytes, then `ip`, so pages read with `offset` neither repeat nor
skip an address — provided `to` is before today: today's traffic is still being added, and bytes
that move between two reads can shift a row across a page boundary. A page shorter than `limit` is
the last. `trackingIds` lists up to 50 per address, heaviest first;
`trackingIdCount` is always exact. Unattributed traffic is left out.

::: warning A signal, not a verdict
Mobile carriers put thousands of phones behind one IPv4 address (CGNAT), and households, offices and
campuses share one honestly. `days` is what tells a farm — the same few ids, day after day — from a
gateway, where hundreds of ids each appear once. Addresses are compared exactly: an IPv6 device
rotating within its /64 shows up as several addresses. The address the edge records is the viewer's,
behind Cloudflare and the local proxy alike.
:::

## Addresses of a tracking id

The other direction: every address one tracking id was served to. Requires project context.

```
GET /api/analytics/tracking-ids/{trackingId}/ips?from=2026-04-01&to=2026-04-30&limit=100
```

```json
{ "data": [
  { "ip": "203.0.113.7", "country": "MX", "bytes": 52428800, "requests": 912, "days": 12,
    "firstSeen": "2026-04-02", "lastSeen": "2026-04-29" }
] }
```

## Queue

How many videos are waiting, running or failed right now. Not a metrics read — it counts rows in
the videos table, instance-wide.

```
GET /api/analytics/queue
```

**Response:**

```json
{ "data": { "pending": 3, "running": 1, "failed": 0 } }
```

## Per-node delivery

What each proxy node served, what it had to fetch from S3 to do it, and how much of what it served
came out of its cache. The three numbers that say whether a node needs more disk (ratio falling with
a full pool) or the fleet needs another node (ratio fine, egress at the node's limit).

```
GET /api/analytics/edges?from=2026-04-01&to=2026-04-30
```

::: warning Admin only
This one names the operator's infrastructure and its origin egress, which no project key has any
business reading. A project key gets `403`.
:::

**Response:**

```json
{
  "data": [
    { "nodeId": 3, "deliveredBytes": 5368709120, "originBytes": 536870912, "hitRatio": 0.9 }
  ]
}
```

| Field | Type | Notes |
|-------|------|-------|
| `nodeId` | integer | The node. Rows that predate the dimension (node 0) are left out |
| `deliveredBytes` | number | Bytes the node served |
| `originBytes` | number | Bytes it fetched from S3 to serve them |
| `hitRatio` | number \| null | Share of cacheable bytes served from cache. `null` when the node served nothing the cache had a say in — no traffic, or only manifests and downloads, which are `BYPASS` and `OFF` and are left out rather than counted as misses |

## Usage

Consumption for the authenticated **account**, across every metric — the one read on this page
scoped to an account rather than to a project, and the one to bill from when your attribution happens at upload
time rather than at link-mint time.

```
GET /api/usage?from=2026-04-01&to=2026-04-30&metric=streaming_bytes
```

A project key resolves to the account that **owns** the project, so the figures it returns are that
account's across all of its projects — not the calling project's alone.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | Yes | Start date (`YYYY-MM-DD`) |
| `to` | date | Yes | End date (`YYYY-MM-DD`) |
| `metric` | string | No | One metric from the catalogue above, except `origin_bytes`. An unrecognised name responds `422` |
| `externalUserId` | string | No | One of your own customer labels, up to 255 characters |

**Response:**

```json
{
  "data": [
    { "metric": "streaming_bytes", "external_user_id": "user-123", "value": 52428800, "date": "2026-04-16" }
  ]
}
```

Rows are grouped by `(metric, external_user_id, date)` — always daily, and always broken down by
customer, whether or not you filtered to one.

### Which axis to bill from

There are two ways to attribute delivered bytes to one of your users, and they are not
interchangeable:

| | `external_user_id` (this endpoint) | `tracking_id` ([batch](#batch-bandwidth-by-tracking-id)) |
|---|---|---|
| Where it comes from | The `externalUserId` you set on the video at [upload](/api/videos#upload-metadata), resolved server-side from the video at ingest | The id you mint each playback or download link with, recovered from the CDN log through the link's token |
| Can it be lost? | No — nothing about it travels in the URL | Yes — a mapping that has expired leaves the traffic in the unattributed bucket |
| Grain | Per customer | Per link, so per viewer or per session |
| Scope | Your account | Instance-wide; you name the ids |
| Cost of the read | Indexed by account and date | Indexed by tracking id and date |

**Ask which one names the person you are billing.** `external_user_id` is resolved from the video, so
it names whoever your system attached to the *upload*. That is the right axis only when the uploader
is the customer — user-generated video, per-creator hosting, a tenant uploading its own library.

In a **catalogue**, it is not: staff uploads each title once and thousands of subscribers watch it,
so every byte of a film would be attributed to the employee who added it. One user with the traffic
of the whole catalogue is not a worse number, it is a meaningless one. There, `tracking_id` is the
only axis that names a viewer, and its cost and fragility are the price of asking a question the
upload label cannot answer.

::: warning What the fragility costs, concretely
A mapping lives as long as its link plus a 35-minute grace — **1h 35m** with the default one-hour
window ([CDN settings](/guide/cdn)), longer for a link minted with a longer `expiresIn` — and it lives in Redis. Traffic whose log line reaches
the ingest after that falls into the unattributed bucket.

In steady state that is the tail of a session near expiry, which the grace is sized for. It becomes
material when the ingest falls behind by more than the grace — a stalled queue, a lagging pull-zone
log API — or when the cache is flushed, restarted without persistence, or evicting under memory
pressure. The loss is then proportional to the incident, not to your traffic.

Watch it: query with `includeUnattributed` and treat the empty-id row as an alarm rather than a
rounding line. A bucket that is normally near zero and suddenly is not is an ingestion incident, and
the bytes in it are bytes somebody consumed and nobody was billed for.
:::

::: tip
Bandwidth is only as complete as the ingestion behind it: an edge ships its log in batches and
the API ingests them from a queue, so the last moments of traffic are normally not in yet.
:::

::: warning Deleted videos leave their bytes behind
Delivery whose video no longer exists is booked to the operator, not to an account — the ingest
resolves the owner **from the video**, and there is none. Those bytes are still in the instance-wide
reads on this page, but they are not in this one. A video deleted mid-period therefore leaves a gap
between what `/api/usage` reports and what the CDN actually served.
:::
