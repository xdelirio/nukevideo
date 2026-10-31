# Streams

Streams represent individual encoded tracks of a video (video, audio, subtitle, or the original file).

Streams are created by the pipeline when a video is ingested. Audio and subtitle tracks can also be
[added to a video that already finished processing](#add-a-track).

## Update Stream

Relabel an **audio or subtitle** track. The change is written to the video's already-published DASH
and HLS manifests in place, so it takes effect on the next player request without re-encoding or
re-packaging.

```
PUT|PATCH /api/streams/{ulid}
```

**Request Body:**

```json
{
  "name": "Latin American Spanish",
  "language": "es-MX",
  "forced": false,
  "hearingImpaired": false
}
```

| Field | Type | Notes |
|-------|------|-------|
| `name` | string | **Required.** Shown in the player's track selector. Must be unique among the video's tracks of the same type, and cannot contain `,`, `"` or a line break. |
| `language` | string \| null | **Required**, but nullable. BCP-47 (`es`, `en-US`, `es-MX`), and it must be a language that exists — the packager normalizes it, so a manifest may show `en` for a source tagged `eng`. |
| `forced` | boolean | **Required**, on every stream. Takes effect on subtitles only — marks a track that carries foreign-language dialogue; on audio it is stored and changes nothing. |
| `hearingImpaired` | boolean \| null | Optional, **subtitles only** — a `true` on an audio track is a `422`, because audio SDH is baked into the packaged manifests. Omit it (or send `null`) to keep whatever the probe or a previous edit set. Stored in the stream's `meta`, and returned lifted out of it as `hearingImpaired`. |

`name`, `language` and `forced` are required on every call, `PATCH` included — omitting one is a
`422`, not a field left untouched. Read the track first and send all three back. `hearingImpaired`
is the only field that is genuinely optional.

Returns `message` and the updated stream under `data`. Responds `400` for a video rendition, the
original file, or a video that is still being processed, and `422` when validation fails.

## Download a Track

Mint a signed URL for **one** track. Downloads are per-track by design: the encoded video
renditions carry no audio, so a single playable file does not exist on our side — you fetch the
tracks you want and mux them yourself (for example with `ffmpeg -i video.mp4 -i audio.mp4 -c copy
out.mp4`).

```
POST /api/streams/{ulid}/download
```

List a video's tracks first with `GET /api/videos/{ulid}`, then request a link per track you want.

For more than one track of the same video, use
[Download Tracks](/api/videos#download-tracks) instead — it mints them all in one request, and
the per-request work this endpoint repeats is per video rather than per track.

**Request Body:**

```json
{ "trackingId": "customer-42", "speedLimitKbps": 8000, "expiresIn": 86400 }
```

| Field | Type | Notes |
|-------|------|-------|
| `trackingId` | string \| null | Your own tracking id — a customer, a tenant. The bytes of the transfer land against it in the bandwidth analytics. Up to 64 characters of `A-Z a-z 0-9 _ -`. |
| `speedLimitKbps` | integer \| null | Caps the transfer rate, in **kilobits** per second (divide by 8 for kB/s): `64` to `10000000`. Signed into the token, so it cannot be lifted by editing the URL. Enforced per request, so a download manager opening several ranged connections gets this much per connection. Omit for no limit. See [Speed limits](/guide/streaming#speed-limits). |
| `expiresIn` | integer \| null | Seconds the link stays valid: `60` to `604800` (a week). Omit it for the CDN's token window, an hour unless the operator changed it. Signed into the token and reported back as `expiresAt`. |

The id never enters the link, nor the response. The mint records the link's token against your id
server-side, and the CDN's access log — which carries the token on every request the link
produces — is attributed through it. The same link is minted
whoever asked for it, so treat the id as a label you chose, never as an authorization input. The
mapping is best-effort: it lives server-side for the token's lifetime plus a margin. Traffic with
no id is still recorded, under an empty one — except on a session or personal-token request, where
an omitted `trackingId` defaults to the ULID of the authenticated user, so the panel's own
downloads stay attributed. A project key that omits it leaves the traffic unattributed: naming the
viewer is the integrator's job.

Read the bytes back per id with `topTrackingIds` on the
[Analytics API](/api/analytics#bandwidth-by-tracking-id), optionally narrowed to one video. The id is
attributed from the CDN's access log, so it lands **after** the transfer, not with the link: a
self-hosted edge reports within seconds, a Bunny pull zone within about seven minutes.

**Response:**

```json
{
  "data": {
    "url": "https://cdn.example.com/01J.../download/audio/01J....mp4?token=HS256-...&expires=1787709204",
    "expiresAt": "2026-08-13T02:41:24+00:00",
    "filename": "01KZW4GN1K3B7Y4RFQBGM0KQF6.mp4",
    "type": "audio",
    "size": 4779203
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `url` | string | Signed for **this object only** — a link to one rendition will not fetch another. Not bound to your IP, so it survives resumes and download managers. |
| `expiresAt` | string | ISO 8601. Validity is checked when the request starts, so a transfer already in flight is not cut off; a resume after this fails and needs a new link. |
| `filename` | string | The stored name — a ULID plus its extension. Unique per track, so fetching several never lands two on the same name. |
| `type` | string | `video`, `audio` or `subtitle`. |
| `size` | integer \| null | Bytes, when known. |

Responds `422` for the original file, `409` while the video is still processing, `404` when the
track was not retained (a template with `keepProcessedFiles` off discards the renditions before they
reach storage) or belongs to another project, and `503` when no delivery node is available.

## Add a Track

Add an **audio or subtitle** file to a video that is already `completed`. Only the new track is
encoded and packaged; it is then grafted into the published DASH and HLS manifests, so the video
keeps playing throughout and nothing else is re-encoded.

There is no `POST` with the file in it. The file goes straight to storage through the same
[S3 upload endpoints](/api/videos#s3-multipart-upload) a video uses, with `metadata.video` instead
of `metadata.template`:

```json
{
  "filename": "dub-en.wav",
  "metadata": {
    "project": "01ABD...",
    "video": "01ABC...",
    "trackType": "audio",
    "trackName": "English (dub)",
    "language": "en",
    "visualImpaired": false,
    "hearingImpaired": false
  }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `filename` | string | **Required.** Its extension has to match `trackType`: audio takes `wav`, `mp3`, `m4a`, `aac`, `flac`, `ogg`, `opus`, `mka`, `mp4`, `mkv`, `mov`, `webm`, `ac3`, `eac3` (the first audio stream of the file is used); subtitles take `srt`, `vtt`, `ass`, `ssa`. |
| `metadata.project` | string | **Required.** As for a video upload. |
| `metadata.video` | string | **Required.** ULID of a `completed` video of that project. |
| `metadata.trackType` | string | **Required.** `audio` or `subtitle`. Not `type`: Uppy fills that field with the file's MIME type. |
| `metadata.trackName` | string | **Required.** Up to 240 characters — room for the layout suffix a ladder adds. Same rules as `name` in [Update Stream](#update-stream): unique among the video's tracks of that type, no `,`, `"` or line break. Not `name`, for the same reason as above. |
| `metadata.language` | string | **Required.** BCP-47, as in [Update Stream](#update-stream). |
| `metadata.forced` | boolean | Optional, subtitles only. |
| `metadata.hearingImpaired` | boolean | Optional. SDH subtitles, or a dialogue-enhanced audio mix. |
| `metadata.visualImpaired` | boolean | Optional, audio only. An audio-description track. |
| `metadata.externalUserId` | string | Optional. Usage is recorded under it; the video's own when omitted. |

Everything the upload could be refused for is checked when it is created — before any byte is
sent — and answered with a `422`: a video that is not `completed`, a taken name, an extension that
does not match the type, or an audio track for a video none of whose outputs carries audio in its
template. A video of another project is a `404`.

Once the upload completes, the track shows up in the video's `streams` with `status: "processing"`,
and moves to `ready` (or `failed`, with the reason in `errorLog`) on its own. Your webhook gets
[`video.track_ready` or `video.track_failed`](/api/webhooks#events) either way — including when the
upload is refused as it lands (the video was retried meanwhile, or the name was taken). The
uploaded file is deleted once the track is ready or has failed; a failed track never retries, so
upload it again. A track whose processing stops without an outcome — say, a lost worker — is
failed automatically after about seven hours.

**How the track is encoded:**

- **Audio** is encoded the way the video's template encodes audio, output by output: same codec,
  same channel ladder, same bitrate cap. A 5.1 file under a template with a stereo and a 5.1 rung
  therefore becomes **two** streams, named with a `(Stereo)` / `(5.1)` suffix; they succeed or fail
  together, and the suffixed names must be free too. The settings are those of the template
  **version** the video was encoded with: editing the template since changes nothing for it. An
  Opus track is only added to DASH manifests, as at ingest.
- **Audio is fitted to the video's duration**: padded with silence when shorter, cut when longer.
  A file more than **10%** longer or shorter than the video is refused (`failed`) as almost
  certainly the wrong file. Timing is taken as is from `0`; there is no offset.
- **Subtitles** are converted to WebVTT and added to every output. A file with no cues fails.

While a track is `processing` it cannot be edited or deleted (`409`). A `failed` track can be
deleted. A `ready` one is an ordinary stream: edit, download and delete it like any other.

Players may take a moment to see the new track: self-hosted delivery edges cache manifests for
about 30 seconds; with Bunny, the manifest's cache TTL applies.

## Delete Stream

Removes one track from an already-packaged video: the CMAF segments are deleted and the track is
edited out of the published manifests, so playback stops offering it without a re-encode.

```
DELETE /api/streams/{ulid}
```

Responds `400` while the video is still processing, `409` while the track itself is still being
[added](#add-a-track), and `400` when the track is the last video
rendition of any output — that would leave the output's published manifests with no video at all.
Deleting a track notifies your webhook with [`video.updated`](/api/webhooks#events), carrying the
video without it.

## Stream Types

| Type | Description |
|------|-------------|
| `original` | The source file as uploaded |
| `video` | Video-only encoded track |
| `audio` | Audio-only encoded track |
| `subtitle` | Text track |

## Stream Properties

| Field | Type | Description |
|-------|------|-------------|
| `ulid` | string | Unique identifier. Not stable across a re-probe: `videos:retry --reprobe` drops the derived streams and mints new ULIDs. |
| `name` | string \| null | Display name. `null` on video renditions — they carry no label anywhere, the panel shows their height instead. |
| `type` | string | `original`, `video`, `audio` or `subtitle` |
| `status` | string \| null | Only set on a track [added after processing](#add-a-track): `processing`, `ready` or `failed`. `null` on every stream the video's own run created — those are ready once the video is. |
| `width` | integer \| null | Video width in pixels |
| `height` | integer \| null | Video height in pixels |
| `packageSize` | integer \| null | Bytes of the packaged CMAF segments — what serves playback. `null` on the `original`, which is never packaged. |
| `fileSize` | integer \| null | Bytes of the retained rendition file, i.e. "there is a file to fetch". `null` when the template has `keepProcessedFiles` off, because the renditions are discarded before the sync and never reach storage — so it is `null` from the start rather than becoming `null` later. This is the field that decides whether a track is downloadable. |
| `language` | string \| null | Language code (e.g., `en`, `es-MX`) |
| `forced` | boolean | Forced subtitle track |
| `hearingImpaired` | boolean | SDH track. Stored inside `meta`, lifted out here for you. |
| `channels` | integer \| null | Audio channels |
| `meta` | object \| null | FFprobe metadata. Raw JSON: **snake_case keys**. |
| `inputParams` | object \| null | Encoding parameters (`video_codec`, `audio_codec`, `target_vmaf`, …). Raw JSON: **snake_case keys**. |
| `errorLog` | string \| null | Error details if the stream's encode failed |
| `createdAt` | string | ISO 8601 timestamp |

`packageSize` and `fileSize` answer two different questions and are never summed per stream: the
video's totals are `servedSize` (packaged bytes) and `size` (both), on
[the video itself](/api/videos#get-video).
