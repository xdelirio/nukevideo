# Add audio and subtitle tracks to a processed video

Status: draft, awaiting approval · 2026-10-30

## Intent

A video that is already `COMPLETED` (encoded, packaged, playing) should be able to receive a new
audio track (a dub, an audio description) or a new subtitle track (`.srt`, `.vtt`, `.ass`) after the
fact. The track is stored as one more `Stream` of the video and shows up in the HLS and DASH
manifests, so players offer it like any track that came with the source. This has to happen
without re-encoding or re-packaging the video renditions: adding a track takes seconds, and the
video keeps playing the whole time.

It is available from the admin panel and from the public API, with the same flow in both.

## Goals

- Upload one audio or subtitle file for a `COMPLETED` video, using the same direct-to-S3 Uppy
  multipart flow the video upload uses.
- Encode the file with the audio settings the video's **template** asks for (codec, bitrate,
  channel ladder). Subtitles become WebVTT, as at ingest.
- Package only the new track, and graft it into every existing manifest it belongs in:
  - audio: every master and capped manifest of each output it is attached to, in each of the
    output's `packaged_formats`;
  - subtitles: every output's manifests.
- Track status per stream (`processing` → `ready` | `failed`, with `error_log`) that the panel and
  the API can see. The video stays `COMPLETED` throughout.
- Once added, a track is an ordinary stream: the existing edit (`PUT streams/{ulid}`), delete and
  download endpoints work on it unchanged.

## Non-goals

- Replacing a track in place. Delete it, then add a new one.
- Adding **video** renditions.
- Adding tracks to videos that are not `COMPLETED` (pending, running, failed). They are rejected.
- Shifting the timing of a track (an offset or sync tool). The file is taken as already in sync
  with the video from t=0.
- Purging the CDN. No purge exists anywhere today. Self-hosted edges cache manifests for 30 s.
  On Bunny the new track appears when the manifest TTL expires. This is documented, not solved.
- More than one track per file. The first audio stream (or the first subtitle stream) of the file
  is used.

## Anti-goals (failure even if it "works")

- Any path that re-downloads the original, re-encodes video or re-runs the full packager.
- A graft that leaves a manifest inconsistent for players, such as an `AUDIO=` group with mixed
  codecs not declared in `CODECS`, or a duplicate `AdaptationSet` id. A failed add must leave the
  published manifests exactly as they were.
- A cross-tenant hole: a project key must not be able to add a track to another project's video.
- A track upload that is mistaken for a new video (by the webhook, or by `PruneScratchJob`'s
  re-ingest).

## Decisions

### Decided with the user

- Panel and public API both.
- Run on the existing queues, specifically `orchestration`. No new queue or supervisor.
- Audio parameters come from the video's template.

### Claude's calls (flagged for review)

1. **Where the audio parameters come from.** The video stores only `template_id`, which points at the
   live template; the template is not snapshotted. What *is* stored is each existing stream's
   resolved `input_params`. For each output of the video:
   - **Find the output's template entry.** There is no output→index column. Add a migration for a
     nullable `outputs.template_index` that `CreateVideoStreamsService` fills from now on.
     Backfill it in the same migration from creation order (id order within the video), which is
     how `CreateVideoStreamsService` creates outputs today.
   - **Build the ladder.** Use `query.outputs[template_index].audio` from the live template. Run
     the new file through the **same** ladder and bitrate cap logic as at ingest:
     `getOrCreateAudioStreams`'s per-track body, extracted into a reusable method. It clamps
     channels to the source and never upmixes. Streams with identical params are shared across
     outputs, as they are today.
   - **Codec guard.** If the output already has audio, and the template's current `audio_codec`
     maps to a different family than `Output::audioCodec()` (the template was edited after
     encoding), use the `input_params` of that output's existing audio streams instead. The ladder
     stays the same but the codec params are taken from the existing streams, so the HLS
     `AUDIO="audio"` group never mixes codecs.
   - **No audio block.** An output whose template entry has no `audio` block does not get the
     track. If **no** output would get it, the request is rejected with 422 when the upload is
     created.
   - **Template deleted.** If the template is gone, and an output has existing audio, fall back to
     those streams' params. Otherwise skip that output (and reject if none remain).
2. **Duration mismatch.**
   - **Audio** is fitted to the video's duration: padded with silence (`apad`) when short,
     trimmed (`-t`) when long. It is **rejected** when it differs from the video's duration by
     more than 10%, because that is almost certainly the wrong file.
   - **Subtitles**: cues past the end are left alone (players ignore them). A file with zero cues
     after conversion is rejected, since shaka would fail on it.
3. **Shared upload namespace (revised 2026-10-30).** Track uploads land in the same folder as
   videos (`tmp-videos/`), with their own cached metadata DTO (`TrackUploadMeta`: user, project,
   video ulid, type, name, language, forced, hearingImpaired, visualImpaired, filename). The same
   `s3/*` endpoints are used, branched on the presence of `metadata.video`, and the multipart is
   validated at creation (video in the project and `COMPLETED`, extension allowlist per type, name
   free, name/language rules of `UpdateStreamData`, and for audio at least one output able to take
   it). `OnVideoUploaded` routes by the metadata's class to `TrackIngestService`, and
   `PruneScratchJob` is unchanged: its lost-webhook replay re-dispatches `OnVideoUploaded`, which
   routes the same way. A separate `tmp-tracks/` prefix was the first version; it was dropped
   because a bucket notification filtered on the video folder would silently never fire for
   tracks, and every path needs the metadata anyway.
4. **Per-stream status.**
   - Add a nullable `streams.status` column, backed by a new `StreamStatus` enum (`processing`,
     `ready`, `failed`). Null means a legacy or ingest-created stream and reads as ready. Exposed
     on `StreamData`.
   - `status` joins `StreamObserver::SILENT_COLUMNS`, so status flips do not spray
     `video.updated`.
   - Explicit webhook events instead: `video.track_ready` and `video.track_failed`, each carrying
     the full `VideoData` payload as every event does (the documented contract), plus the ulids
     of the streams in a top-level `tracks` field (see call 8).
   - The stream row is created by `OnTrackUploaded` with `status=processing`, after it re-checks
     the name and the video's status. If either check fails, the row is created as `failed` with a
     reason, so the user still sees what happened.
5. **The job: `AddTrackJob`.**
   - **Queue and settings.** `orchestration` queue, one add at a time per video (adds share
     manifests) through `WithoutOverlapping` (see call 11), `$tries`/`$backoff`/`$timeout` set and
     commented as house style. The constructor takes the placeholder stream's id.
   - **Steps:**
     1. Encode from a presigned URL to the uploaded object, with `EncodeCommandBuilder`,
        mapping stream 0 of the right type. Validate as described in call 2, plus `WebVtt::sanitize`
        for subtitles.
     2. Package the single track into a scratch dir:
        - subtitles: the existing `SubtitlePackager` path;
        - audio: one packager run per format, with a throwaway `_audio.*` manifest, using
          `PackagerCommandBuilder` with the normal segment duration.
     3. **Under `Video::lockForUpdate()`** (the same lock edit and delete take): re-check that the
        video is still `COMPLETED`, download the existing manifests, graft, upload `{ulid}/*`
        segments first and the manifests last (with the `CONTENT_TYPES`), then record
        `package_size`/`file_size` and set the status to `ready`.
     4. Store the raw track at `storedPath()`: always for subtitles, and for audio only if
        `keep_processed_files`, which mirrors `PackageVideoJob`. Delete the uploaded source.
   - **`failed()`** marks the stream `failed` with `error_log` and leaves the manifests untouched,
     since they are only written in step 3's final moments. The video is **not** marked failed.
6. **ManifestEditor changes.**
   - New `hlsAddAudio` and `importDashAudio`, modeled on the subtitle grafts, idempotent by the
     stream ulid.
   - **HLS, when the master has no audio group yet:** add `AUDIO="audio"` to every `STREAM-INF`
     and append the audio codec to `CODECS`. Raise `BANDWIDTH` on each variant by the track's
     bitrate when it is the new maximum.
   - **Fix the existing `hlsAddSubtitles` guard**, which today bails out when the master already
     has *any* subtitles. It should only skip ulids that are already present, so that a second
     subtitle can be added at all.
   - Make `existingManifests` reachable, either public or wrapped in a method.
7. **Panel.**
   - An "Add track" button on `VideoPage.vue` when the video is `COMPLETED`. It opens a dialog
     with:
     - the file, with the type inferred from the extension and editable;
     - name;
     - language;
     - forced and SDH, for subtitles.
   - Its own Uppy instance, not the global upload store, so `VideosPage`'s `upload-success`
     listener is not triggered.
   - `StreamItem` shows a status badge, and the `error_log` when the track failed. The page's
     existing polling picks up the transition.
8. **One upload can make several audio streams (an "added track").** The template's channel ladder
   applies exactly as it does at ingest. A 5.1 dub under a [stereo, 5.1] template gives two streams,
   named with the usual `(Stereo)`/`(5.1)` suffix, and so can outputs whose resolved params differ.
   - **The upload id groups them.** Every stream born from one upload carries the same upload id in
     `meta.track_upload`.
   - **The placeholder row.** `OnTrackUploaded` creates a single `processing` row, so a file that
     fails before probing still has something to show. After probing, `AddTrackJob` turns it into
     the first rung and creates the others with the same upload id.
   - **The group moves together.** Every stream in it goes `ready` or `failed` at once, since one
     graft publishes all of them.
   - **Webhooks.** The events carry `tracks: [ulid, …]`, not a single ulid.
9. **Accessibility flags on audio.** For audio, `TrackUploadMeta` also accepts `visualImpaired`
   (audio description) and `hearingImpaired`. They are stored in `meta`, where the probe writes them
   at ingest. `PackagerCommandBuilder` already turns them into DASH roles and HLS `CHARACTERISTICS`
   (`audioAccessibility()`), so the throwaway manifest carries them and the graft copies them. No
   hand-written descriptors are needed.
10. **Usage is metered like ingest.**
    - `upload_bytes` is recorded when `OnTrackUploaded` lands.
    - Encode seconds are recorded by the job, both through `UsageService`.
    - `TrackUploadMeta` carries `externalUserId`, defaulting to the video's own, so per-customer
      billing keeps working.
11. **Concurrent adds queue, they are not dropped.** `AddTrackJob` is **not** `ShouldBeUnique`.
    - It uses `WithoutOverlapping` keyed by the video, releasing with a delay. Bulk adds (ten
      subtitle languages at once from the API) all land, one after another.
    - The manifest step still takes `Video::lockForUpdate()`. That lock is what protects against the
      edit and delete endpoints.
12. **Processing tracks are locked.**
    - `StreamManagementService::update`/`destroy` return 409 for a stream that is `processing`.
    - Deleting a `failed` stream is allowed. It has no segments or manifest entries, so it only
      removes the row and the `tmp-tracks/` source.
    - Under the lock, the job also re-checks that its rows still exist. If they are gone, it stops
      cleanly and deletes what it uploaded.
13. **`template_index` drift is accepted.**
    - If the template's outputs are reordered after encoding, the index can point at a different
      entry.
    - The codec guard (call 1) keeps playback correct. The ladder and bitrate then follow the
      template as it is now, which is acceptable for a track added later.
14. **Docs.**
   - `docs/api/streams.md`: an "Add a Track" section covering the S3 flow with `metadata.video`,
     the fields, the statuses and the duration rules.
   - `docs/api/webhooks.md`: the two new events.
   - `docs/api/videos.md`: a cross-link from Upload Metadata.

## Constraints

- House conventions in `CLAUDE.md`:
  - logic in Services;
  - Data objects as validation;
  - project scoping on every lookup;
  - jobs take primitives;
  - a migration with docblock, `down()`, `hasColumn` guards, and MariaDB plus SQLite support;
  - no new dependencies;
  - no edits to generated files (run `typescript:transform` instead).
- Every binary it needs (ffmpeg, shaka `packager`, s5cmd) is already in every image. The job does
  not need the `chunks` disk.
- CI: Pint, Pest, ESLint, `type-check`.

## Tests the builder should cover

- Multipart creation:
  - accepted for a `COMPLETED` video in the project;
  - 422 for a non-completed video, a name clash, a bad extension, or a template with no audio
    anywhere;
  - refused (404) for another project's video, under both a user session and a project key.
- The webhook routes `tmp-tracks/` to the track path. `PruneScratchJob` never re-ingests a track
  as a video.
- Audio params: the ladder from the template, the codec guard after a template edit, and an output
  with no audio block being skipped.
- Grafts against `hlsFixture`/`dashFixture`:
  - audio into a master with audio, and into one without (`CODECS`/`AUDIO=` added);
  - a second subtitle into a master that already has subtitles (the regression for the guard fix);
  - idempotency on a re-run;
  - unique DASH ids.
- The job, with `Process::fake` and `Storage::fake('s3')`:
  - the happy path to `ready`;
  - a failure leaves the manifests byte-identical and the stream `failed`;
  - the video is not `COMPLETED` at run time.
- An added track can be relabeled and deleted through the existing endpoints.

## Left to the builder

- The exact allowlist of audio extensions, and the ffmpeg filter details for pad and trim.
- The `$tries`/`$backoff`/`$timeout` values, within house reasoning.
- Whether the audio packager run reuses `build()` with a manifest-name override or adds a
  `buildAudio()`.
- The UI layout details of the dialog and the badge, within the existing shadcn-vue components.
