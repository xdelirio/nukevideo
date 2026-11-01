# Webhooks

Webhooks run in both directions, and they are unrelated to each other:

- **Inbound** — your object store tells NukeVideo an upload finished. One endpoint, authenticated
  with `WEBHOOK_SECRET`. This is infrastructure wiring, done once per installation.
- **Outbound** — NukeVideo tells *your* application that a video changed. Configured per project,
  authenticated with a secret you choose. This is what an integrator consumes.

[[toc]]

## Outbound: Video Events

Set a **Webhook URL** on the project (and optionally a **Webhook secret**) in the admin panel, and
NukeVideo will `POST` to it as videos move through the pipeline. A project with no webhook URL sends
nothing.

### Authentication

The secret, when set, is sent as a bearer token:

```
Authorization: Bearer <your webhook secret>
```

There is no signature over the body. Anyone who can reach your URL can post to it, so treat the
bearer token as the whole check, and ignore requests without it.

### Event Payload

Every event has the same envelope, and every event carries the **complete video** in `data` — the
same object [`GET /api/videos/{ulid}`](/api/videos#get-video) returns. No event sends a reduced
payload, so a receiver can store `data` without branching on the event name and let `event` decide
only the side effects.

```json
{
  "event": "video.completed",
  "timestamp": 1757000000,
  "data": { "ulid": "01HX...", "status": "completed", "outputs": ["..."], "streams": ["..."] }
}
```

| Field | Type | Notes |
|-------|------|-------|
| `event` | string | See the table below. Treat an unknown value as "store `data` and carry on" — new events are additive. |
| `timestamp` | integer | Unix seconds, taken when the delivery was queued. Second precision, so it is a rough ordering hint, not a sequence number. |
| `data` | object | The full video, [as documented for `GET /api/videos/{ulid}`](/api/videos#get-video). |
| `tracks` | string[] | Only on `video.track_ready` and `video.track_failed`: the ULIDs of the streams the upload became. |

### Events

| Event | When |
|-------|------|
| `video.created` | The upload was ingested and the video row exists. Fires **before the source is probed**: `duration` is `0`, `aspectRatio` is empty, `outputs` is empty and `streams` holds only the `original`. |
| `video.updated` | The video's own fields (`name`, `priority`, `externalUserId`, `externalResourceId` through `PUT\|PATCH /api/videos/{ulid}`) changed — at any point — or one of its tracks (`PUT\|PATCH /api/streams/{ulid}`, `DELETE /api/streams/{ulid}`) changed after the run finished (`completed` or `failed`), including the `original` being reclaimed. A track change sends the **whole video**, like every other event. Never sent for the writes a run performs on its own — those end in `video.completed` or `video.error`. |
| `video.completed` | Every output reached a terminal state and at least one succeeded. |
| `video.error` | The video failed — either every output failed, or a pipeline failure (a stalled worker, an unreachable source) ended the run. |
| `video.track_ready` | A track [added to the processed video](/api/streams#add-a-track) was published: it is in the manifests now. `tracks` lists its streams (more than one when the audio ladder split it). |
| `video.track_failed` | Adding a track failed; the manifests are untouched. The reason is in that stream's `errorLog`. |
| `video.deleted` | The video was deleted. Only sent for videos that carry an `externalResourceId`. The payload is the video as it was just before deletion. |

### Delivery

Deliveries are queued, not sent inline. Each one is attempted **at most 3 times**, waiting 60s after
the first failure and 300s after the second — so a delivery is abandoned about 6 minutes after it
was first queued, then logged and dropped. There is no replay endpoint.

Your endpoint has **5 seconds** to respond. Anything at or above `400`, and any timeout, counts as a
failure. Acknowledge first and do your own work asynchronously.

Two consequences worth designing around:

- **A retry can arrive out of order, carrying stale data.** The payload is built when the delivery
  is queued, not when it is sent, so a delivery that only succeeds on its last attempt can land ~6
  minutes late and overwrite a newer one that already got through. If you mirror the video, ignore a
  payload whose `status` is behind the one you already stored.
- **Deliveries are at-least-once.** Make your receiver idempotent on `data.ulid`. One edit can also
  legitimately produce more than one `video.updated`: the `original` reclaimed right after
  `video.completed` is the common case, and the last payload is the current one.

### What is *not* covered

These change a video without emitting any event. If you keep a local copy, refresh it from
[`GET /api/videos/{ulid}`](/api/videos#get-video) after them:

| Change | How to stay in sync |
|--------|---------------------|
| Intermediate pipeline states (`downloading`, `running`, `uploading`) and the probe that fills in `duration`, `aspectRatio`, `outputs` and `streams` | Poll while the status is not terminal. |
| A re-probe (`videos:retry --reprobe`), which mints **new stream ULIDs** | Refetch the video; any stored stream ULID is stale. |

`outputs[].progress` is live encoding progress read from a short-lived store. It is never worth
mirroring — poll it only while a video is in a non-terminal status.

## Inbound: Video Uploaded

Triggered when a video file upload to S3 is complete.

```
POST /api/webhooks/video-uploaded
```

### Authentication

Every request is authenticated against the `WEBHOOK_SECRET` environment variable, in one of two
forms. If `WEBHOOK_SECRET` is unset, both forms are rejected.

**Bearer token** — the generic form, and the one to use for any bucket that lets you set an
arbitrary header:

```
Authorization: Bearer <WEBHOOK_SECRET>
```

**HMAC signature** — for object stores that sign their notifications themselves. The value is the
base64-encoded SHA-256 HMAC of the raw request body, keyed with `WEBHOOK_SECRET`:

```
x-e2-notification-signature: <base64(hmac_sha256(body, WEBHOOK_SECRET))>
```

When the signature header is present it is the one checked, and a bad signature is rejected
outright rather than falling back to the bearer token.

### Request Headers

| Header | Description |
|--------|-------------|
| `Authorization` | `Bearer <WEBHOOK_SECRET>` — the generic form |
| `x-e2-notification-signature` | base64 HMAC-SHA256 of the body; takes precedence when sent |

A request that fails either check gets `403`.

### Request Body

A standard S3 event notification (the shape MinIO and RustFS send), either wrapped in `Records` or
as a bare array of records:

```json
{
  "EventName": "s3:ObjectCreated:CompleteMultipartUpload",
  "Records": [
    { "eventName": "s3:ObjectCreated:CompleteMultipartUpload",
      "s3": { "bucket": { "name": "videos" }, "object": { "key": "tmp-videos/...", "size": 734003200 } } }
  ]
}
```

Only `s3:ObjectCreated:CompleteMultipartUpload` and `s3:ObjectCreated:Put` are acted on, and only
for keys under the upload folder (`tmp-videos/`); anything else is acknowledged and ignored. The
body carries no user, project or template: those were stored server-side when the upload was
signed, and are looked up by the object key.

### Processing

For each matching record, NukeVideo:

1. Queues an `OnVideoUploaded` job with the object key and size, and answers `200` straight away.
2. The job looks up the upload's metadata, creates the video (status `pending`) with its
   `original` stream, books the upload volume and sends `video.created`.
3. The `videos:dispatch` scheduler later claims a worker slot and hands the video to the encoding
   pipeline, which probes the source and fans out the encode.

Ingestion is idempotent on the object key, so a redelivered notification never creates a second
video.

### Retry Behavior

If ingestion fails (typically the upload metadata or the user, project or template cannot be
found), `OnVideoUploaded` is attempted **5 times** in all, waiting 10s, 30s, 60s and 120s between
attempts.

### Failure Handling

On final failure there is no video to mark, so the system:
- Keeps the uploaded source — it may be the user's only copy; orphaned sources are reclaimed later
  by age.
- Records a `video_upload_processing_failed` entry in the uploader's activity log.
- Logs the error for investigation.

## Configuring the Inbound Webhook

Set the webhook secret in your `.env` file:

```env
WEBHOOK_SECRET=your_secure_random_string
```

When configuring your S3 provider to send webhooks, point the notification URL to:

```
https://api.yourdomain.com/api/webhooks/video-uploaded
```

Ensure the webhook secret matches between your S3 configuration and the NukeVideo `.env` file.
