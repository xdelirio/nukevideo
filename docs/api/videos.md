# Videos

Manage video resources. Videos are created automatically when a file is uploaded via S3 and the webhook is triggered.

## List Videos

Returns a paginated list of the project's videos.

```
GET /api/videos
```

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | integer | Page number |
| `perPage` | integer | Items per page (default 15, maximum 100) |
| `search` | string | Matches against the video name |
| `externalUserId` | string | Exact match on the id supplied at upload |
| `externalResourceId` | string | Exact match on the id supplied at upload |
| `status` | string | One status, or several comma-separated (`completed,failed`): `pending`, `downloading`, `running`, `uploading`, `completed`, `failed`. An unknown value is a `422` |
| `updatedSince` | datetime | Only videos changed at or after this instant (ISO 8601) — see [Keeping a copy in sync](#keeping-a-copy-in-sync) |
| `sort` | string | `created_at` (default), `updated_at`, `name`, `size`, `duration` or `status`. `size` is what the listing shows — package plus retained file bytes over every stream |
| `direction` | string | `desc` (default) or `asc` |

**Response:**

The pagination fields sit alongside `data`, not inside a `meta` object, and — as everywhere in
this API — the payload is camelCase. Each row is a full video, `outputs` and `streams` included
(they are elided below for brevity, not empty in the response), so a listing page carries every
field [Get Video](#get-video) returns. That is why `perPage` is capped at 100.

```json
{
  "data": [
    {
      "ulid": "01HX...",
      "name": "my-video.mp4",
      "status": "completed",
      "priority": "normal",
      "duration": 120.5,
      "aspectRatio": "16:9",
      "createdAt": "2025-01-15T10:30:00+00:00",
      "updatedAt": "2025-01-15T10:42:10+00:00",
      "externalUserId": "user-42",
      "externalResourceId": "movie-7",
      "thumbnailUrl": "https://api.example.com/api/videos/01HX.../thumbnail.jpg",
      "storyboardUrl": "https://api.example.com/api/videos/01HX.../storyboard.vtt",
      "outputs": ["..."],
      "streams": ["..."],
      "size": 812739584,
      "servedSize": 734003200
    }
  ],
  "currentPage": 1,
  "perPage": 15,
  "total": 72
}
```

### Keeping a copy in sync

An integration that mirrors the catalogue does not need to walk it. Keep the time of your last poll
and ask for what changed since, oldest first:

```
GET /api/videos?updatedSince=2026-04-16T10:00:00Z&sort=updated_at&direction=asc&perPage=100
```

Every video carries `updatedAt`. A status change, a rename or new metadata moves it; a deleted video
does not appear at all — the [`video.deleted` webhook](/api/webhooks) reports those, for videos
uploaded with an `externalResourceId`.

## Get Video

```
GET /api/videos/{ulid}
```

Returns a single video with its streams and outputs. The object is exactly the one the listing
returns for each row, and the one a [webhook](/api/webhooks#event-payload) carries in its `data`.

**Response:**

```json
{
  "data": {
    "ulid": "01HX...",
    "name": "my-video.mp4",
    "status": "completed",
    "priority": "normal",
    "duration": 120.5,
    "aspectRatio": "16:9",
    "createdAt": "2025-01-15T10:30:00+00:00",
    "updatedAt": "2025-01-15T10:42:10+00:00",
    "externalUserId": "user-42",
    "externalResourceId": "movie-7",
    "thumbnailUrl": "https://cdn.example.com/01HX.../assets/thumbnail.jpg",
    "storyboardUrl": "https://cdn.example.com/01HX.../assets/storyboard.vtt",
    "size": 812739584,
    "servedSize": 734003200,
    "outputs": [
      {
        "ulid": "01HZ...",
        "formats": ["hls", "dash"],
        "status": "completed",
        "progress": 100,
        "createdAt": "2025-01-15T10:31:02+00:00",
        "streams": [ "...the subset of `streams` this output packages..." ]
      }
    ],
    "streams": [
      {
        "ulid": "01HY...",
        "name": null,
        "type": "video",
        "packageSize": 52428800,
        "fileSize": 51380224,
        "inputParams": { "video_codec": "h264", "target_vmaf": 93 },
        "meta": {},
        "width": 1920,
        "height": 1080,
        "language": null,
        "forced": false,
        "hearingImpaired": false,
        "channels": null,
        "errorLog": null,
        "createdAt": "2025-01-15T10:31:02+00:00"
      }
    ]
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `priority` | string | `low`, `normal` or `high`: where the video sat in the dispatch queue. See [Priority](#priority). |
| `duration` | number | Seconds. `0` until the source has been probed. |
| `aspectRatio` | string | Empty until the source has been probed. |
| `thumbnailUrl` / `storyboardUrl` | string | Served unsigned from the CDN when a delivery node is available, and from `GET /api/videos/{ulid}/{filename}` otherwise. The URL is stable, but the object only exists once the video has been processed. |
| `size` | integer | Every stream's `packageSize` plus `fileSize` — the whole S3 footprint. |
| `servedSize` | integer | Only `packageSize`, i.e. the bytes that serve playback. `size` minus this is retained source and processed-file overhead. |
| `outputs[].formats` | string[] | Plural: one package serves both `hls` and `dash`. |
| `outputs[].progress` | integer | 0-100, averaged over the encoded chunks. Live only while the output is encoding: it reads `100` as soon as the output is `completed`, and `0` once the progress data has expired. Treat it as a value to poll, never as one to store. |
| `outputs[].streams` | object[] | The subset of the video's streams this output packages. Streams are **shared** between outputs when their parameters resolve identically, so the same stream can appear under several outputs. |

Streams follow the [Stream Properties](/api/streams#stream-properties) table. Note that
`inputParams` and `meta` are raw JSON columns passed through as stored, so **their keys are
snake_case** (`video_codec`, `audio_codec`, `target_vmaf`) while every other field is camelCase.
`hearingImpaired` is the exception that is lifted out of `meta` for you.

## Update Video

```
PUT|PATCH /api/videos/{ulid}
```

**Request Body:**

```json
{
  "name": "Updated Video Name",
  "externalUserId": "user-123",
  "externalResourceId": "post-456",
  "priority": "high"
}
```

| Field | Type | Description |
| --- | --- | --- |
| `name` | string | Required. |
| `externalUserId` | string\|null | Optional. Send `null` to clear it. |
| `externalResourceId` | string\|null | Optional. Send `null` to clear it. |
| `priority` | string | Optional. `low`, `normal` or `high`. Only reorders a video that is still `pending` (see [Priority](#priority)). |

Responds with `message` and the full updated video under `data`, in the same shape as
[Get Video](#get-video).

## Delete Video

Deletes the video, its streams, outputs, and associated S3 files.

Allowed while the video is `completed`, `failed` or `pending` — a pending video is one nothing has
started on yet, so there is no run to strand. Any status in between (`downloading`, `running`,
`uploading`) is refused with a `400`: deleting there would leave the fleet encoding chunks for a
video that no longer exists.

```
DELETE /api/videos/{ulid}
```

## Retry Video

Puts a `failed` video back in the queue. This is the only way back into the pipeline: dispatch only
ever picks up a video that is `pending`, and getting one there takes more than a status change —
the failed run's batches, per-chunk progress and stream errors are cleared first, or the retry
would hang behind them or report the old attempt's state.

Everything the failed run had already encoded is reused, so a video that died on its last chunk
comes back in minutes. The uploaded original is never touched.

```
POST /api/videos/{ulid}/retry
```

Takes no body. Re-probing the source — rebuilding the streams and outputs from the template
instead of reusing them — is a `videos:retry --reprobe` decision and is not exposed here: a run
that failed while probing left no derived streams, so a plain retry re-probes anyway, and the
difference the flag does make is re-encoding a whole video that only needed its last chunk.

Returns the requeued video, in the same shape as [Get Video](#get-video), with `status` back to
`pending`.

Responds `409` when the video cannot be retried, with the reason in `message`: it is not `failed`,
its source is gone from both the internal mirror and S3, or its previous run is not finished yet —
encode batches with jobs still out, its audio/subtitle pass or packaging still holding its lock, or
progress reported within the last few minutes. Those jobs must be allowed to land first.

## Playback URLs

Mint signed manifest URLs for **every** output of a video in one request. This is the only playback
mint: everything one needs besides the signature — your authentication, the project lookup, the
video's status, the delivery node — belongs to the video rather than to the output, and a player no
longer has to decide which output to ask for before it can ask for anything.

```
POST /api/videos/{ulid}/play
```

**Request Body** (all optional):

```json
{
  "resolution": 720,
  "ip": "203.0.113.7",
  "trackingId": "customer-42",
  "speedLimitKbps": 3000,
  "download": false,
  "expiresIn": 3600
}
```

| Field | Type | Notes |
|-------|------|-------|
| `resolution` | integer | Caps every output's ladder at this resolution, read as the short edge of the picture: `1080` is 1080p for a landscape 1920x1080 rendition and for a vertical 1080x1920 one alike. Each output resolves it against its own renditions: you get the largest packaged rendition at or below your ceiling, or the full ladder when the ceiling is already at or above the largest one. |
| `ip` | string | The viewer's address, when the link is minted from your backend. Defaults to the caller's own. With self-hosted [IP binding](/guide/cdn#ip-binding) on, the link plays from this address alone. |
| `trackingId` | string | Your own label for this viewer. One id for the whole answer — one answer is one viewer opening one video — recorded against every token it hands out. |
| `speedLimitKbps` | integer | Caps the transfer rate of the whole session — the manifest and every segment — in kilobits per second, the unit renditions are quoted in: `64` to `10000000`. Signed into the token, so it cannot be lifted by editing the URL. An adaptive player measures it as its bandwidth and settles on the renditions that fit under it. Omit for no limit. See [Speed limits](/guide/streaming#speed-limits). |
| `download` | boolean | `true` when the links are for saving the video to watch offline: your app downloads the manifest and its segments with the platform's own download manager (Media3 `DownloadManager` on Android, `AVAssetDownloadTask` on iOS, Shaka's offline storage on the web) instead of streaming them. Nothing about the links changes; their bytes are booked as `download_bytes` instead of `streaming_bytes` in [analytics](/api/analytics). Omitted or `null` is streaming. The links still expire at `expiresAt`, so a download that outlives it has to mint again and resume — or ask for a longer `expiresIn`. |
| `expiresIn` | integer | Seconds the links stay valid: `60` to `604800` (a week). Omit it for the CDN's token window, an hour unless the operator changed it. Signed into the token, and reported back as `expiresAt`. An offline download that may take longer than an hour is the usual reason to ask for more. |

Nothing in the body selects *what* comes back. The answer carries every manifest the video can
serve, and you pick from it.

**Response:**

```json
{
  "data": {
    "thumbnailUrl": "https://cdn.example.com/01HX.../assets/thumbnail.jpg",
    "storyboardUrl": "https://cdn.example.com/01HX.../assets/storyboard.vtt",
    "expiresAt": "2025-01-15T11:30:00+00:00",
    "sources": [
      {
        "url": "https://cdn.example.com/01HX.../play/01HZ....m3u8",
        "format": "hls",
        "outputUlid": "01HZ...",
        "videoCodec": "h264",
        "audioCodec": "aac"
      },
      {
        "url": "https://cdn.example.com/01HX.../play/01HZ....mpd",
        "format": "dash",
        "outputUlid": "01HZ...",
        "videoCodec": "h264",
        "audioCodec": "aac"
      },
      {
        "url": "https://cdn.example.com/01HX.../play/01J0....mpd",
        "format": "dash",
        "outputUlid": "01J0...",
        "videoCodec": "av1",
        "audioCodec": "opus"
      }
    ],
    "subtitles": [
      {
        "url": "https://cdn.example.com/01HX.../download/subtitle/01J1....vtt?token=...",
        "streamUlid": "01J1...",
        "language": "es",
        "name": "Español",
        "forced": false,
        "hearingImpaired": false
      }
    ]
  }
}
```

`sources` is **flat**: one entry per output *and* format, so each one is self-sufficient — pick one
and hand its `url` to your player. An output that serves both protocols produces two entries, which
is why the first two above repeat their codecs and their `outputUlid`.

| Field | Type | Notes |
|-------|------|-------|
| `url` | string | The signed manifest. It points at whichever delivery layer is configured and carries the access token. |
| `format` | `hls` \| `dash` | Given explicitly rather than left to be sniffed from the extension. |
| `outputUlid` | string | The output this manifest belongs to, matching an entry of `outputs[]` on [Get Video](#get-video). Several sources can share it. |
| `videoCodec` | string \| null | The decodable format — `h264`, `hevc`, `av1` — not the encoder that wrote it, so an output encoded on NVENC or QSV still reports `h264`. This is what lets you rule a source out before loading it: the manifest declares the exact codec string, but only once fetched. `null` if no rendition records one. |
| `audioCodec` | string \| null | The decodable audio format, `aac` or `opus`. One value, like `videoCodec`: a template names a single audio codec per output and applies it to every language that output carries. `null` when the output has no audio at all. |

**A manifest that cannot be served is simply absent** — an output that never finished packaging, or
one whose streams were all deleted. A video is `completed` once at least one output succeeded, so a
failed sibling is an ordinary state and does not cost the others their links. `sources` can come
back empty, still as a `200`: the video exists and you are entitled to it, there is just nothing to
play.

### Standalone subtitles

`subtitles` lists every subtitle track of the video as a standalone WebVTT file, for a player that
does not read its text tracks from the manifest and has to be handed them one by one — a
`<track src>` element, `addRemoteTextTrack()`, a custom transcript view. **The same tracks are
already inside every manifest in `sources`**, so a player that takes them from there needs nothing
from this list, and loading both shows each language twice.

| Field | Type | Notes |
|-------|------|-------|
| `url` | string | The signed `.vtt`: the whole track in one file, cue times on the video's own timeline. The token is in the URL, so it works where no header can be added. |
| `streamUlid` | string | The track, matching an entry of `streams[]` on [Get Video](#get-video). |
| `language` | string \| null | The track's language as stored on the stream; `null` when the source did not tag it. |
| `name` | string \| null | The label to show in a menu. |
| `forced` | boolean | A track of foreign-language dialogue only, meant to be shown without the viewer turning subtitles on. |
| `hearingImpaired` | boolean | SDH: sound descriptions as well as dialogue. |

The list is empty, never absent, when the video has no subtitles. A track still being
[added](/api/streams#add-a-track), or whose add failed, is left out until its file exists.

These links differ from the manifest ones in three ways. Each is signed for its **one file**, where
a manifest link covers the whole playback directory. They are **not bound to the viewer's IP**, even
with [IP binding](/guide/cdn#ip-binding) on, and `speedLimitKbps` does not apply to them — a few
kilobytes of text is not what either protects. And their bytes are booked as `download_bytes` in
[analytics](/api/analytics), under the same `trackingId`, because they are served from the download
zone. The self-hosted edge sends them with open CORS, so a cross-origin
`<track crossorigin="anonymous">` loads them; on Bunny that is the pull zone's own CORS setting,
which has to include `vtt`.

Every URL in one answer expires at the same `expiresAt`: they are signed in the same request
against the same provider window. Responds `409` while the video is still processing and `503`
when no delivery node is available — both conditions of the video, under which nothing could have
been served.

See [Streaming & VOD](/guide/streaming#requesting-a-playback-url) for the token model.

## Playback URLs for Many Videos

The same mint for a list of videos in one request — a page of a catalogue, say.

```
POST /api/videos/play
```

**Request Body:**

```json
{
  "videos": ["01HX...", "01HY..."],
  "resolution": 1080,
  "trackingId": "customer-42",
  "expiresIn": 3600
}
```

| Field | Type | Notes |
|-------|------|-------|
| `videos` | string[] | The video ULIDs, `1` to `500` of them. Duplicates are answered once. |
| `resolution`, `ip`, `trackingId`, `speedLimitKbps`, `download`, `expiresIn` | | Exactly as in [Playback URLs](#playback-urls), applied to every video in the list. |

**Response:** a list with one entry per video that can be played, in the order you asked for
them. Each entry is what [Playback URLs](#playback-urls) answers for that video, with its
`videoUlid`.

```json
{
  "data": [
    {
      "videoUlid": "01HX...",
      "thumbnailUrl": "https://cdn.example.com/01HX.../assets/thumbnail.jpg",
      "storyboardUrl": "https://cdn.example.com/01HX.../assets/storyboard.vtt",
      "expiresAt": "2025-01-15T11:30:00+00:00",
      "sources": [
        { "url": "https://cdn.example.com/01HX.../play/01HZ....m3u8", "format": "hls", "outputUlid": "01HZ...", "videoCodec": "h264", "audioCodec": "aac" }
      ],
      "subtitles": []
    }
  ]
}
```

**A video that cannot be played is simply absent** — one that does not exist, belongs to another
project, or is still processing — where the single-video mint answers `404` or `409`. One stale
ULID in a page of a catalogue does not cost the rest of the page its links; compare the
`videoUlid`s that came back with the list you sent to tell which ones are missing. Responds `503`
when no delivery node is available.

## Download Tracks

Mint signed URLs for a video's tracks in **one** request. Prefer this over calling
[Download a Track](/api/streams#download-a-track) per stream: everything a mint needs besides the
signature itself — your authentication, the project lookup, the video's status, the delivery node —
belongs to the video, not to the track, so asking per track pays for all of it again each time. A
video with several renditions, audio languages and subtitles is one request here and a dozen there.

```
POST /api/videos/{ulid}/downloads
```

**Request Body:**

```json
{
  "streamUlids": ["01KZW4GN1K3B7Y4RFQBGM0KQF6"],
  "trackingId": "customer-42",
  "speedLimitKbps": 8000,
  "expiresIn": 86400
}
```

| Field | Type | Notes |
|-------|------|-------|
| `streamUlids` | string[] \| null | Which tracks to mint. **Omit it to get every downloadable track of the video**, which is the usual call. An empty array is taken literally and returns nothing. Up to 200 entries. |
| `trackingId` | string \| null | Your own tracking id, applied to every link in the batch. Same rules and same behaviour as the per-track mint. |
| `speedLimitKbps` | integer \| null | Applied to each link on its own, not shared across the batch. Same rules as the per-track mint. |
| `expiresIn` | integer \| null | Seconds every link in the batch stays valid. Same rules as the per-track mint. |

The links are the same ones the per-track endpoint returns, signed per object and expiring
together. Tracks that could not be minted do not fail the request — they come back in `skipped`
with a reason, so one stale id in a list does not cost the rest their links.

**Response:**

```json
{
  "data": {
    "links": [
      {
        "url": "https://cdn.example.com/01J.../download/audio/01J....mp4?token=HS256-...&expires=1787709204",
        "expiresAt": "2026-08-13T02:41:24+00:00",
        "filename": "01KZW4GN1K3B7Y4RFQBGM0KQF6.mp4",
        "type": "audio",
        "size": 4779203
      }
    ],
    "skipped": [
      { "ulid": "01KZW4GN1K3B7Y4RFQBGM0KQF7", "reason": "not_retained" }
    ]
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `links` | object[] | One entry per minted track, in the order you asked for them, or in the video's own track order when `streamUlids` was omitted. Same fields as the per-track response. |
| `skipped` | object[] | One entry per track that produced no link, with the `ulid` you sent and a `reason`. |

| `reason` | Meaning |
|----------|---------|
| `not_retained` | The template has `keepProcessedFiles` off, so the track was discarded before it reached storage. |
| `not_downloadable` | The untouched original. It lives in its own zone and is never handed out. Only ever returned for a ULID you named explicitly. |
| `not_found` | This video has no such track — a stale id, or one belonging to another video. |

Responds `409` while the video is still processing, `503` when no delivery node is available, `404`
when the video belongs to another project, and `422` when validation fails. The first two are
conditions of the whole video, so they fail the request rather than appearing in `skipped`.

## Video Statuses

`video.status` reflects the ingestion pipeline phase. Check individual `outputs[].status` to see which encodings succeeded or failed.

| Status | Description |
|--------|-------------|
| `pending` | Uploaded, waiting to be dispatched |
| `downloading` | Source file being fetched |
| `running` | Probing and chunk encoding in progress |
| `uploading` | Encoded chunks being packaged and uploaded to S3 |
| `completed` | All outputs have reached a terminal state (at least one completed) |
| `failed` | No output could be produced |

## Priority

Every video carries a `priority` — `low`, `normal` (the default) or `high` — chosen at upload
through `metadata.priority`. It decides the order in which `pending` videos are dispatched: every
`high` video ahead of every `normal` one, and every `normal` ahead of every `low`, oldest first
within each level.

It only ever reorders the **queue**. A video already dispatched keeps its worker slot, and its
chunks share the same encode queue as everyone else's, so raising the priority of a `running` video
does not speed it up, and a `high` upload does not preempt work in progress — it takes the next
free slot. A `high` video waiting on hardware no active node provides (a GPU template with no GPU
node up) does not hold back the videos behind it that other hardware can take.

The priority is kept for good, so a [retry](#retry-video) requeues the video at the level it had.

## Output Statuses

Each output carries its own status, which is what [Playback URLs](#playback-urls) reads: only a
`completed` output's manifests are minted.

| Status | Description |
|--------|-------------|
| `pending` | Waiting for the encode batch to start |
| `running` | Chunks being encoded |
| `completed` | Packaged and synced to S3, ready for playback |
| `failed` | The run failed before this output was packaged; it is unusable |

## Upload Flow

Videos are not created via a direct `POST` endpoint. Instead:

1. Initiate a multipart upload to S3 (see [S3 Upload](#s3-multipart-upload)).
2. S3 triggers a webhook when the upload completes.
3. NukeVideo creates the video record and starts processing.

## S3 Multipart Upload

These endpoints coordinate multipart uploads with S3:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/s3/params` | Get upload parameters |
| `POST` | `/api/s3/multipart` | Create multipart upload |
| `GET` | `/api/s3/multipart/{uploadId}` | Get uploaded parts |
| `GET` | `/api/s3/multipart/{uploadId}/{partNumber}` | Sign a part |
| `POST` | `/api/s3/multipart/{uploadId}/complete` | Complete upload |
| `DELETE` | `/api/s3/multipart/{uploadId}` | Abort upload |

### Upload Metadata

`GET /api/s3/params` and `POST /api/s3/multipart` take the file name and the upload's metadata,
including optional ids that associate the video with external systems:

```json
{
  "filename": "video.mp4",
  "metadata": {
    "project": "01ABD...",
    "template": "01ABC...",
    "externalUserId": "user-123",
    "externalResourceId": "post-456",
    "priority": "high"
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `filename` | string | **Required.** Up to 255 characters. |
| `metadata.project` | string | **Required.** ULID of the project the video belongs to. Uppy's requests do not carry the `X-Project-Ulid` header, so the project travels here. With a project API key it must be that key's own project (`403` otherwise). |
| `metadata.template` | string | **Required.** ULID of an **enabled** template of that project, used for processing |
| `metadata.externalUserId` | string | Optional, up to 255 characters. ID of the user in your external system |
| `metadata.externalResourceId` | string | Optional, up to 255 characters. ID of the resource (post, product, etc.) in your external system |
| `metadata.priority` | string | Optional. `low`, `normal` or `high`; `normal` when omitted. Any other value is a `422`. See [Priority](#priority). |

The other multipart endpoints take the upload's `key` and refuse (`403`) an upload that was not
started by the same caller.

The same endpoints upload an audio or subtitle track for a video that already finished processing,
with `metadata.video` in place of `metadata.template` — see [Add a Track](/api/streams#add-a-track).

These fields are stored on the video record and returned in API responses as `externalUserId` and `externalResourceId`. The `externalUserId` is also recorded in usage tracking, allowing you to query per-user metrics via the [Usage API](/api/analytics#usage).
