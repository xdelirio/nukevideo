# Video Processing

NukeVideo uses FFmpeg to transcode uploaded videos into an adaptive ladder of renditions and
shaka-packager to package them as CMAF (HLS and DASH over the same segments). The entire pipeline is
queue-based and runs on distributed worker nodes.

## Pipeline Overview

When a video is uploaded, the following jobs execute in order:

```
OnVideoUploaded — creates the video (PENDING) and its `original` stream
  └── videos:dispatch — claims a worker slot (RUNNING)
       └── PrepareVideoJob (orchestration queue)
            ├── download source once, mirror it to the chunk store (DOWNLOADING → RUNNING)
            ├── probe → outputs + streams, per-title CRF / bitrate probe / preflight
            ├── ExtractThumbnailJob        ┐ in parallel with the encode
            ├── EncodeSidecarTracksJob     ┘ (audio + subtitles, one pass, never chunked)
            └── Bus::batch per hardware queue — one ProcessChunkJob per (window × rendition)
                 └── last batch's .then() (UPLOADING)
                      └── PackageVideoJob (packaging queue)
                           concat chunks → storyboard → shaka-packager → subtitles → s5cmd sync to S3
                           └── every output settles → video COMPLETED / FAILED
```

## Video Statuses

`video.status` tracks the pipeline phase. Output-level results are tracked on each `output`.

| Status | Description |
|--------|-------------|
| `pending` | Uploaded (or requeued by a retry), waiting to be dispatched |
| `downloading` | Source file being fetched from S3 |
| `running` | Probing, planning and chunk encoding in progress |
| `uploading` | Every chunk encoded; packaging and the upload to S3 in progress |
| `completed` | All outputs have settled, at least one of them completed |
| `failed` | No output could be produced |

## Output Statuses

An output is one CMAF package, with its HLS and/or DASH manifests. Its status is what the
[playback mint](/api/videos#playback-urls) reads to decide whether its manifests can be served.

| Status | Description |
|--------|-------------|
| `pending` | Waiting for the encode batch |
| `running` | Chunks encoding in progress |
| `completed` | Packaged and synced to S3, ready for playback |
| `failed` | The run failed before this output was packaged |

## Stream Types

Each video can have multiple streams:

| Type | Description |
|------|-------------|
| `original` | The source file as uploaded |
| `video` | Encoded video-only rendition |
| `audio` | Encoded audio-only track |
| `subtitle` | Text subtitle track, converted to WebVTT |

Streams are **shared** between outputs when their resolved parameters are identical, so two outputs
with the same 720p rendition encode it once.

## Processing Steps

### 1. Dispatch

The `videos:dispatch` scheduler (every five seconds) picks up `pending` videos by
[priority](/api/videos#priority) — `high`, then `normal`, then `low`, oldest first within each
level — and dispatches a `PrepareVideoJob` for each one its hardware can take (see
[Dispatch and Hardware](#dispatch-and-hardware)).

### 2. Download Original

The worker downloads the original from primary S3 **exactly once** and mirrors it to the LAN
`chunks` store; every later job, and every retry, reads the mirror instead.

### 3. Probe & Create Streams

The source is probed with FFprobe (and mkvmerge, for Matroska language tags) to extract duration,
resolution, codecs, audio and subtitle tracks. One output is created per entry of the template's
`query.outputs`, with its streams:

- **Video renditions**: every variant is resolved against the source and never upscaled; variants
  that would produce the same size collapse into one. A variant without `gop_size` gets one derived
  from the source frame rate.
- **Audio**: one track per source audio track and per distinct channel count of the template's
  channel ladder, never upmixed.
- **Subtitles**: text tracks only (SRT, ASS/SSA, WebVTT, mov_text); bitmap and empty tracks are
  skipped.

Each video rendition is then settled against the source: `target_vmaf` steers the CRF per title,
a bitrate probe measures what the quality mode costs, and a preflight encode proves the parameters
work before anything fans out.

### 4. Encode Chunks

The video is divided into keyframe-aligned windows, sized per encoder so a chunk finishes inside
the worker timeout. One `ProcessChunkJob` per (window × rendition) encodes and uploads its chunk to
the chunk store, fanned out as one `Bus::batch` per hardware queue. A chunk already staged by an
earlier attempt is reused, which is what makes a retry cheap.

Encoding progress is tracked per output in Redis and exposed via `output.progress` (0–100).

### 5. Audio & Subtitles

`EncodeSidecarTracksJob` encodes every audio and subtitle track in a single pass over the whole
source, never chunked: per-chunk audio concatenation corrupts gapless codecs such as Opus.

### 6. Extract Thumbnail

A thumbnail is extracted at 30% of the video duration, up to 1920 px wide (never upscaled), and
uploaded to S3. A failure is logged and never fails the video.

### 7. Generate Storyboard

Sprite sheets are created for seek preview, during packaging, from a rendition already concatenated
on the packaging worker's disk rather than from the source. That rendition is the smallest one at
least 640 px wide, so nothing is upscaled and nothing larger is decoded than the thumbnails show.
- Frames extracted at 10-second intervals, 640 px wide, the trick-play size for a full HD screen.
- Six columns per sheet, and as many rows as keep the sheet within 4096 px (ten for 16:9), the
  largest texture many TV browsers accept.
- A WebVTT file is generated with coordinates for each frame.

Like the thumbnail, a failure never fails the video: the storyboard is then simply absent.

### 8. Package & Settle

When the last encode batch finishes, the video moves to `uploading` and `PackageVideoJob` runs on
one worker once every audio and subtitle track is staged. It concatenates each rendition's chunks
with `-c copy`, packages every output with shaka-packager into shared CMAF segments, grafts the
subtitles into the manifests, and `s5cmd sync`s everything to primary S3. Only then are the outputs
marked `completed`, so a completed video is already servable.

The video is then marked `completed` if at least one output succeeded, or `failed` if none did, and
the chunk store's copy of the source and chunks is cleaned up.

## Dispatch and Hardware

Chunk jobs are routed by the template's video codec: CPU codecs go to `video-processing`, Intel QSV
codecs to `video-processing-intel`, NVIDIA NVENC codecs to `video-processing-nvidia`. GPU nodes do
not drain the CPU queue.

The dispatcher admits videos per hardware family: each family may have as many videos in flight as
it has active worker nodes, plus one. A full GPU family does not hold back the CPU videos queued
behind it. A video whose template needs hardware no active node provides stays `pending` — it is
not failed — and is picked up as soon as such a node comes back.

## Error Handling

- **Any failure fails the run.** A chunk that exhausts its retries, a failed audio/subtitle pass,
  or a packaging failure marks the video `failed`, fails every output that had not completed,
  cancels the remaining encode batches, and fires the `video.error` webhook. The failing
  rendition's `errorLog` names the cause.
- **Dead workers**: jobs retry after a worker is lost. `videos:reap` fails any active video whose
  heartbeat has gone stale for longer than one queue redelivery window, unless the queue still has
  its jobs pending.
- **Upload webhook**: `OnVideoUploaded` retries up to 5 times (10s, 30s, 60s, 120s). If it fails for
  good, the uploaded source is kept rather than deleted.
- **Retry**: a failed video can be requeued with [Retry Video](/api/videos#retry-video) or
  `php artisan videos:retry`. The mirrored source and every staged chunk survive a failure, so a
  retry resumes instead of starting over.
- **Pruning**: `videos:prune` deletes videos that have been `failed` for more than 7 days, their
  source included. Retry within that window.
