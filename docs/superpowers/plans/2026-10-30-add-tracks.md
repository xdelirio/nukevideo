# Add Tracks to a Processed Video — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upload an audio or subtitle file to a `COMPLETED` video and publish it as new stream(s) in the existing HLS/DASH manifests, without repackaging the video.

**Architecture:** A new upload namespace (`tmp-tracks/` + `TrackUploadMeta`) rides the existing Uppy S3 endpoints. The bucket webhook routes those keys to `OnTrackUploaded`, which creates a `processing` placeholder stream. `AddTrackJob` (`orchestration`) probes it, resolves the template's audio ladder per output, encodes, packages only the new track(s) into a scratch dir, and grafts them into the manifests under the video row lock (`TrackPublisher`).

**Tech Stack:** Laravel 13 / PHP 8.4, Spatie Data, Pest 5, ffmpeg, shaka-packager, Vue 3 + Uppy.

**Spec:** `docs/superpowers/specs/2026-10-30-add-tracks-design.md` (approved). Executors read both. Call numbers below ("call 3") refer to the spec's "Claude's calls" list.

## Global Constraints

- Follow `CLAUDE.md`:
  - logic in `app/Services`;
  - no FormRequests (the Uppy controller's existing `$request->validate` pattern is the precedent for that controller only);
  - every lookup is scoped through the project;
  - job constructors take primitives;
  - `$tries`/`$backoff`/`$timeout` are set with a comment on why;
  - the queue goes in a `private const QUEUE`.
- Migrations: an anonymous class, a "why" docblock, a `down()`, `Schema::hasColumn` guards, and they must run on MariaDB and SQLite.
- No new dependencies. Never edit `front/src/types/generated.d.ts` or `front/src/components/ui/`. Run `php artisan typescript:transform` and `data:cache-structures` after changing `app/Data`/`app/Enums`.
- Everything in English. Comments explain why, matching the surrounding density.
- **Git:** work on branch `feat/add-tracks`, created from `main`.
  - **Do not commit.** The user reviews everything first; their memory rule overrides the skill's commit steps.
  - Every "Commit" step below is replaced by "leave changes in the working tree".
- **Always run `php artisan config:clear` before `php artisan test`.**
- All commands run in Docker:
  - backend: `docker compose exec nukevideo-api …`
  - frontend: `docker compose exec nukevideo-front …`
- Done for backend means `./vendor/bin/pint` and the relevant tests pass. Done for frontend means `pnpm exec eslint .` and `pnpm run type-check` pass.

## Review Focus

1. **Video retried or deleted mid-add.**
   - Scenario: a user deletes the video, or runs `videos:retry`, while `AddTrackJob` runs.
   - Expected: the job re-checks `COMPLETED` under the lock and stops without writing manifests. The stream ends `failed` (if the video still exists).
   - Test is in Task 6.
2. **Opus template into an HLS output.**
   - Scenario: a silent master (outputs with no audio), packaged HLS+DASH, whose template's audio is Opus.
   - Expected: the track is grafted into DASH only. The HLS manifests are byte-identical.
   - Tests are in Task 4 (formats intersection) and Task 6.
3. **Two subtitle uploads land seconds apart.**
   - Expected: both end `ready` and both appear in HLS and DASH.
   - Tests: the `hlsAddSubtitles` per-ulid regression in Task 2, and `WithoutOverlapping` in Task 6.
4. **The lost-webhook replay.**
   - Scenario: `PruneScratchJob` finds a `tmp-tracks/` object with live meta.
   - Expected: it re-dispatches `OnTrackUploaded`, never `OnVideoUploaded`.
   - Test is in Task 5.
5. **A malicious track name or language through the API.**
   - Scenario: a name containing `"`, `,` or a newline, or an invalid BCP-47 tag.
   - Expected: a 422 at multipart creation, the same as `UpdateStreamData`.
   - Test is in Task 5.

---

### Task 1: Schema — stream status and output template index

**Files:**
- Create: `database/migrations/2026_10_30_000001_add_status_to_streams_and_template_index_to_outputs.php`
- Create: `app/Enums/StreamStatus.php`
- Modify:
  - `app/Models/Stream.php` (fillable `status`, cast to `StreamStatus`)
  - `app/Models/Output.php` (fillable `template_index`)
  - `app/Data/StreamData.php` (add `?StreamStatus $status`)
  - `app/Observers/StreamObserver.php` (`SILENT_COLUMNS` += `status`, `error_log`)
  - `app/Services/CreateVideoStreamsService.php` (`createRenditionOutput` writes `template_index => $index`)
  - `app/Services/StreamManagementService.php` (409 on a `processing` stream in `update` and `destroy`)
- Test:
  - `tests/Feature/Video/StreamStatusTest.php`
  - extend `tests/Feature/Video/UpdateStreamTest.php` and `DeleteStreamTest.php`

**Interfaces:**
- Produces:
  - `enum StreamStatus: string { PROCESSING = 'processing'; READY = 'ready'; FAILED = 'failed'; }`
  - `Stream::$status: ?StreamStatus`, where null means a legacy stream and reads as ready.
  - `Output::$template_index: ?int`
  - `StreamData::$status`

- [ ] **Step 1: Write failing tests.**
  - `it('exposes stream status in StreamData')`: a stream with `status=processing`, so `StreamData::fromModel($s)->status === StreamStatus::PROCESSING`.
  - `it('refuses to edit a processing stream')`: `PUT /api/streams/{ulid}` gives 409.
  - `it('refuses to delete a processing stream')`: `DELETE` gives 409.
  - `it('deletes a failed added stream without touching manifests')`: `Storage::fake('s3')`, with a manifest stored. After the delete, the manifest bytes are unchanged and the row is gone.
  - `it('records the template index on each output')`: run the stream-creation path the existing tests use, with a 2-output template. Expect `outputs.template_index` to be `[0, 1]` in id order.
  - `it('does not announce a status-only change')`: `Queue::fake()`, a completed video, `$stream->update(['status' => 'ready'])`. Expect no `DispatchWebhookJob`.
- [ ] **Step 2: Run** `php artisan test --filter='StreamStatusTest|UpdateStreamTest|DeleteStreamTest'`. Expected: the new tests fail.
- [ ] **Step 3: Write the migration.**
  - `streams.status`: nullable `string(16)` after `type`.
  - `outputs.template_index`: nullable `unsignedSmallInteger` after `video_id`.
  - Backfill `template_index` per video in id order with a chunked PHP loop. **Not** a window function, which SQLite in older versions and the house rule both avoid.
  - `down()` drops both columns, guarded.
- [ ] **Step 4: Make the model, enum, observer, service and Data changes listed above.**
  - The 409 is `ValidationException::withMessages(['message' => 'This track is still being processed.'])->status(409)`, thrown before the COMPLETED check.
  - A failed stream with `status=failed` skips `ManifestEditor::removeStream` (it was never grafted). It still deletes the row, which lets the observer clean `path`/`storedPath`.
- [ ] **Step 5: Regenerate the types and caches.**
  - `php artisan migrate`
  - `php artisan typescript:transform`
  - `php artisan data:cache-structures`
- [ ] **Step 6: Run** `config:clear`, then the filter from step 2. Expected: PASS. Then run `pint`.

### Task 2: ManifestEditor — audio grafts and the multi-subtitle fix

**Files:**
- Modify:
  - `app/Services/ManifestEditor.php`
  - `app/Services/SubtitlePackager.php` (`graft()`: the HLS path can now chain per document like DASH; update the docblock that describes the old bail-out)
- Test: `tests/Feature/Encoding/ManifestGraftTest.php`, using `hlsFixture`/`dashFixture` from `tests/Pest.php`.

**Interfaces:**
- Produces:
  - `public function hlsAddSubtitles(string $masterContent, string $subsMaster): ?string`. The signature is unchanged; the guard becomes per ulid (skip `#EXT-X-MEDIA` lines whose `URI="{ulid}/` already appears in the master).
  - `public function hlsAddAudio(string $masterContent, string $audioMaster, string $audioCodecs, int $bandwidth): ?string`
    - `$audioCodecs` is the RFC 6381 string from the throwaway master's `STREAM-INF` `CODECS` minus the video part. Take it from the throwaway master, whose single `STREAM-INF` lists `CODECS="<video?>,<audio>"`. When it is audio-only, it is just the audio codec.
    - `$bandwidth` is the new track's peak bandwidth in bits/s, read from the throwaway master's `BANDWIDTH`.
  - `public function importDashAudio(string $masterXml, string $audioXml): ?string`
  - `public function existingManifests(Video $video, Output $output): array`, changed from private to public with an unchanged signature.

- [ ] **Step 1: Write failing tests.**
  - `it('adds a second subtitle to a master that already has subtitles')`: `hlsAddSubtitles(hlsFixture(...), $subsWithNewUlid)` contains both `TYPE=SUBTITLES` lines. Re-running it with the same subs returns null.
  - `it('adds an audio rendition to a master that has an audio group')`:
    - a new `#EXT-X-MEDIA:TYPE=AUDIO…URI="{new}/index.m3u8"` line, placed after the last existing `TYPE=AUDIO` line;
    - the `STREAM-INF` `CODECS` is unchanged when the codec is already listed;
    - the result is idempotent (the second call returns null).
  - `it('adds the audio group to a master without audio')`:
    - starting from a fixture with the `TYPE=AUDIO` line and `AUDIO="audio"` removed, every `STREAM-INF` gains `,AUDIO="audio"`;
    - `CODECS="av01.0.08M.08,mp4a.40.2"`;
    - `BANDWIDTH` and `AVERAGE-BANDWIDTH` are raised by `$bandwidth`.
  - `it('imports a DASH audio set with fresh ids')`: the result has 4 AdaptationSets, the new one's id is `3` and its Representation id is `3`. A second call returns null.
  - `it('keeps the period untouched when the audio MPD has no audio set')`: returns null.
- [ ] **Step 2: Run** `php artisan test --filter=ManifestGraftTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `importDashAudio` is `importDashSubtitles` with `contentType='audio'` and the idempotency XPath on audio sets. Extract the shared body into `private function importDashSets(string $masterXml, string $sourceXml, string $contentType): ?string` and make both public methods call it.
  - `hlsAddAudio` changes `BANDWIDTH` only when the master had no audio. With an existing group, the variant's declared peak already accounts for one audio rendition, and players pick one at a time.
- [ ] **Step 4: Run** the filter, plus `SubtitleIsolationTest`, `UpdateStreamTest`, `DeleteStreamTest` and `RepairSubtitles`. Expected: PASS. Run `pint`.

### Task 3: PackagerCommandBuilder — single-track audio run

**Files:**
- Modify: `app/Services/PackagerCommandBuilder.php`
- Test: `tests/Feature/Encoding/PackagerCommandBuilderAudioTest.php`

**Interfaces:**
- Produces:
  - `public const AUDIO_DASH_MANIFEST = '_audio.mpd';`
  - `public const AUDIO_HLS_MANIFEST = '_audio.m3u8';`
  - `public function buildAudio(array $inputs, string $outputDir, string $format): array`. It takes the same input shape as `build()`, with audio inputs only. It produces one run in **one** format: `$format` is `'hls'` or `'dash'`.

- [ ] **Step 1: Write failing tests.**
  - For `buildAudio([$in], '/w', 'hls')`, the argv:
    - contains `--hls_master_playlist_output` `/w/_audio.m3u8`;
    - contains no `--mpd_output`;
    - has a descriptor including `hls_group_id=audio` and `playlist_name={ulid}/index.m3u8`.
  - For `'dash'`: `--mpd_output /w/_audio.mpd` and `--generate_static_live_mpd`.
  - For a `visual_impaired` input: the descriptor includes `dash_roles=description`.
  - Running a non-audio input throws `InvalidArgumentException`.
- [ ] **Step 2: Run** the test. Expected: FAIL.
- [ ] **Step 3: Implement** with `streamDescriptor($input, $outputDir, [$format])` and the instance's normal `$segmentDuration`.
  - One run per format: both formats write the same `init.mp4`/`.m4s` from identical inputs, so the second run overwrites with identical bytes. The format-specific manifest is what differs. This matches `SubtitlePackager`.
- [ ] **Step 4: Run** the test. Expected: PASS. Run `pint`.

### Task 4: Audio parameter resolution per output

**Files:**
- Create: `app/Services/TrackParamsResolver.php`
- Modify: `app/Services/CreateVideoStreamsService.php`. Extract the per-track rung loop of `getOrCreateAudioStreams` into
  `public static function audioRungs(array $audioConfig, int $sourceChannels, ?string $sourceCodec, ?string $sourceProfile, ?int $sourceRate): array`, returning `array<int $channels, array $inputParams>`. Make `getOrCreateAudioStreams` call it, so ingest behaviour is unchanged. Make `channelLayoutLabel` public static.
- Test: `tests/Feature/Video/TrackParamsResolverTest.php`

**Interfaces:**
- Consumes: `Output::$template_index` and `Output::formats()`/`audioCodec()` (Task 1).
- Produces:
  - `TrackParamsResolver::forAudio(Video $video, int $sourceChannels, ?string $sourceCodec, ?string $sourceProfile, ?int $sourceRate): array`, returning `list<array{output: Output, channels: int, params: array, formats: list<string>}>`. There is one entry per (output × rung).
    - `formats` is `array_intersect($output->formats(), protocols of params['audio_codec'] from config('ffmpeg.codecs'))`.
    - Entries with empty `formats` are dropped.
  - `TrackParamsResolver::canTakeAudio(Video $video): bool`, which is true when at least one output would resolve to a non-empty entry for a stereo source.

- [ ] **Step 1: Write failing tests.** Templates are built inline with `Template::create(['query' => ['outputs' => [...]]])`.
  - `it('builds the template ladder for a 5.1 source')`: with `[stereo 128k, 5.1 384k]` aac and `sourceChannels=6`, there are 2 entries (channels 2 and 6) with the template bitrates.
  - `it('never upmixes')`: a stereo source under the same ladder gives 1 entry, channels 2.
  - `it('caps bitrate to the source')`: `sourceRate=96000` on an aac 128k rung gives `audio_bitrate='96k'`.
  - `it('keeps the existing codec when the template changed codec since encoding')`: the output has an existing aac audio stream and the template now says `libopus`. The entry params' `audio_codec` equals the existing stream's.
  - `it('skips an output whose template entry has no audio block')`.
  - `it('falls back to existing streams when the template is gone')`: `template_id` points at a deleted row.
  - `it('drops HLS for an opus track on an HLS+DASH output')`: `formats === ['dash']`.
  - `it('drops an output whose formats would be empty')`.
- [ ] **Step 2: Run** the tests. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Entry lookup: `$template?->query['outputs'][$output->template_index]['audio'] ?? null`.
  - Codec guard: compare the family of the template codec (via the `config('ffmpeg.codecs')` `family`, as `Output::codecFamily` does) with `$output->audioCodec()`. On a mismatch, or with no template, take the `input_params` of the output's existing audio streams minus `channels`, and use the template's `channels` ladder if present. Otherwise use one rung per distinct existing `channels`.
- [ ] **Step 4: Run** this test plus the whole `tests/Feature/Encoding` directory, to prove the ingest refactor is behaviour-neutral. Expected: PASS. Run `pint`.

### Task 5: Upload namespace, webhook routing, placeholder ingest

**Files:**
- Create:
  - `app/DTOs/TrackUploadMeta.php`
  - `app/Jobs/OnTrackUploaded.php`
  - `app/Services/TrackIngestService.php`
- Modify:
  - `app/Services/UppyS3Service.php`
  - `app/Services/FileService.php` (folder parameter)
  - `app/Http/Controllers/MyCustomUppyController.php`
  - `app/Http/Controllers/VideoWebhookController.php`
  - `app/Jobs/PruneScratchJob.php`
  - `config/uppy-s3-multipart-upload.php` (`s3.bucket.track_folder` => `env('UPPY_TRACK_FOLDER', 'tmp-tracks')`)
  - `.env.example`
- Test: `tests/Feature/Video/TrackUploadTest.php`

**Interfaces:**
- Produces:
  - `TrackUploadMeta`: readonly properties `user`, `project`, `video` (ulid), `type` (`'audio'|'subtitle'`), `name`, `language`, `forced`, `hearingImpaired`, `visualImpaired`, `filename`, `?externalUserId`.
    - `user` and `project` have the same names and meaning as on `UploadMeta`, so `authorizeUploadKey` keeps working unchanged.
    - It is built by `fromRequest(User, Project, Video, array $input)`.
  - `UppyS3Service::getUploadMeta(string $key): UploadMeta|TrackUploadMeta|null` (the return type is widened).
  - `UppyS3Service::generateKey(string $filename, ?string $folder = null): string`
  - `FileService::generateKey(string $name, ?string $folder = null)`
  - `OnTrackUploaded(string $key, int $size)`: runs on `default` (queued like `OnVideoUploaded`), with `tries=5`, `backoff=[10,30,60,120]` and `timeout=60`. Its `handle` calls `TrackIngestService::handle($key, $size)`.
  - `TrackIngestService::handle(string $key, int $size): void`
    1. It is idempotent on `streams.path = $key`.
    2. It creates the placeholder stream with:
       - `path = $key`;
       - `type`, `name`, `language`, `forced` from the meta;
       - `meta = ['track_upload' => $key, 'hearing_impaired' => …, 'visual_impaired' => …]` (audio keys only for audio);
       - `status = processing`.
    3. It re-checks that the video is `COMPLETED` and the name is free. On failure the row is created as `failed` with `error_log`.
    4. It records `UsageService::record(..., 'upload_bytes', $size, externalUserId ?? video's, project)`.
    5. It forgets the meta.
    6. It dispatches `AddTrackJob::dispatch($stream->id, $video->id)` (Task 6), but only when the stream is `processing`.

- [ ] **Step 1: Write failing tests**, with `Storage::fake('s3')` and `Queue::fake()`, mocking `S3Client` where the multipart call is reached, or asserting the 422 before it.
  - `it('rejects a track upload for a video that is not completed')`: 422.
  - `it('rejects a track upload for another project video')`: 404, under both a user session and a project token.
  - `it('rejects a name that could break the manifest')`: `name="a\"b"` gives 422. An invalid `language` gives 422.
  - `it('rejects a name already used by a track of the same type')`: 422. Subtitles are compared within `forced`.
  - `it('rejects an extension outside the allowlist')`:
    - audio allows `wav,mp3,m4a,aac,flac,ogg,opus,mka,mp4,mkv,mov,webm,ac3,eac3`;
    - subtitles allow `srt,vtt,ass,ssa`.
  - `it('rejects audio when no output can take it')`: the template has no audio anywhere and there is no existing audio.
  - `it('stores a TrackUploadMeta under the track folder')`: via `s3/params`. The key starts with `tmp-tracks/` and the meta is a `TrackUploadMeta`.
  - `it('routes tmp-tracks keys to OnTrackUploaded')`: POST the webhook with a signed record. `OnTrackUploaded` is pushed and `OnVideoUploaded` is not.
  - `it('creates a processing placeholder and dispatches AddTrackJob')`.
  - `it('creates a failed row when the video stopped being completed before ingest')`.
  - `it('replays a lost track webhook into the track path')`: `PruneScratchJob` with a `tmp-tracks/` object and live meta pushes `OnTrackUploaded`.
  - `it('prunes orphaned track uploads after the grace window')`.
- [ ] **Step 2: Run** `--filter=TrackUploadTest`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - **Controller.** `createMultipartUpload` and `getUploadParameters` branch on `$request->filled('metadata.video')` into `validateTrackMetadata($request, $project): Video`:
    - the video is looked up through `$project->videos()->where('ulid', …)->firstOrFail()`;
    - status is checked, the name is checked through the same rules as `UpdateStreamData`'s attributes (duplicate them by calling `UpdateStreamData::rules()` and its attribute regexes in a `Validator`, or extract them into a shared rule class in `app/Rules`);
    - name uniqueness reuses `StreamManagementService::assertNameIsFree`, made callable with `(Video, string $type, string $name, bool $forced)`;
    - for audio, `TrackParamsResolver::canTakeAudio`.
  - **Webhook controller.** It checks the track folder first, then the video folder.
  - **`PruneScratchJob`.**
    - Run `pruneUploadTmp` for both folders. The dispatched job depends on the meta's class.
    - Stale multipart uploads are pruned for both prefixes.
- [ ] **Step 4: Run** the filter, plus `VideoPriorityTest` and `TemplateShelfTest` (the upload regressions). Expected: PASS. Run `pint`.

### Task 6: AddTrackJob and TrackPublisher

**Files:**
- Create:
  - `app/Jobs/AddTrackJob.php`
  - `app/Services/TrackPublisher.php`
- Modify: `app/Services/WebhookDispatcher.php`. Add an optional `array $extra = []` merged into the payload's top level, so `forVideo('video.track_ready', $video, ['tracks' => [...]])` works.
- Test: `tests/Feature/Encoding/AddTrackJobTest.php`

**Interfaces:**
- Consumes:
  - from Task 2: `hlsAddAudio`, `importDashAudio`, `hlsAddSubtitles` and `existingManifests`;
  - from Task 3: `buildAudio`;
  - from Task 4: `TrackParamsResolver::forAudio`;
  - `SubtitlePackager::packageAndGraft`.
- Produces:
  - `AddTrackJob(int $streamId)`.
    - `private const QUEUE = 'orchestration'`, `tries=3`, `maxExceptions=2`, `backoff=[30,120]` and `timeout=1800`, each with a reasoning comment.
    - `middleware(): [(new WithoutOverlapping("add-track:{videoId}"))->releaseAfter(30)->expireAfter(1900)]`. It needs the video id, so the constructor takes `(int $streamId, int $videoId)`.
  - `TrackPublisher::publish(Video $video, Collection $streams, string $scratchDir, array $manifestEditsByPath): void`. Under `DB::transaction` + `Video::lockForUpdate()` it:
    - re-checks that the video is `COMPLETED` and that every stream still exists (otherwise it throws `TrackAbortedException`, a new class in `app/Exceptions`);
    - uploads `{ulid}/*` with the `PackageVideoJob::SYNC_CONTENT_TYPES` mapping (made public);
    - re-downloads each target manifest, re-applies the edit callables in `$manifestEditsByPath` (`path => callable(string): ?string`) to the fresh copy, and `put`s it with the `ManifestEditor` content type. That constant becomes a public `ManifestEditor::contentType(string $format): string`.
    - sets `status=ready`, `package_size` and `file_size`.
- Job flow (`handle`):
  1. Load the placeholder. Return if it is gone or not `processing`.
  2. Sign the URL for the source. Use `Storage::disk('s3')->temporaryUrl($key, now()->addHour())` and `MediaDuration::seconds`. Probe with `FFProbe::create()->streams($url)` and take the first stream of the type. **No stream of that type** → fail with "The file has no audio track." / "The file has no subtitle track.".
  3. **Audio.**
     - Reject when `abs(sourceDuration - video.duration) / video.duration > 0.10`, with the message "Audio is {x}s long but the video is {y}s; check it is the right file.".
     - Call `forAudio()`. For each distinct `params` signature (reuse `streamSignature` logic, made public static), the first one reuses the placeholder row and the rest create new rows. The rows get `input_params`, `channels`, a name with the `channelLayoutLabel` suffix when there is more than one rung, the same `meta.track_upload` and a fresh `path` `{videoUlid}/audio/{ulid}.{ext}`, and are attached to their outputs.
     - Encode each with `ChunkTranscodeService::buildAudioArguments()`, plus `-af apad=whole_dur={d} -t {d}` where `d` is `video.duration` formatted `%.3f`.
     - Package with `buildAudio` once per format in the union of the entries' `formats`. Read `_audio.*`, then unlink it.
  4. **Subtitle.** `meta.index` is unset. Encode with `EncodeCommandBuilder::build`, then `WebVtt::sanitize`, and require at least one `-->`, otherwise "The subtitle file has no cues.".
  5. Download each target manifest to the scratch dir:
     - for audio, `existingManifests` of each output that has the stream attached, filtered to that stream's formats;
     - for subtitles, every output.
     - Subtitles then call `SubtitlePackager::packageAndGraft` over that dir. Throw if no new text set appears.
  6. Build the edit callables:
     - audio HLS: `fn ($m) => $editor->hlsAddAudio($m, $throwawayHls, $codecs, $bandwidth)`;
     - audio DASH: `importDashAudio`;
     - subtitles: the grafted file contents are recomputed against the fresh copy by the same `hlsAddSubtitles`/`importDashSubtitles` call.

     Then call `TrackPublisher::publish`.
  7. Store the raw file at `storedPath()`: always for subtitles, and for audio only if `$video->template?->keep_processed_files`. Delete the `tmp-tracks/` source. Record `UsageService::record(..., 'encoding_cpu', seconds, …)` in a `finally`, as `ProcessChunkJob` does.
  8. Dispatch `WebhookDispatcher::forVideo('video.track_ready', $video->fresh(), ['tracks' => $ulids])`.
- `failed(Throwable $e)`:
  - every stream with this `meta.track_upload` is updated with `status=failed` and `error_log = Video::trimReason($e->getMessage())`;
  - attachments are detached;
  - the extra rungs' rows are deleted, keeping the placeholder;
  - `video.track_failed` is dispatched.
  - On `TrackAbortedException` because the rows are gone: delete any `{ulid}/` segments that were uploaded, and send no webhook.

- [ ] **Step 1: Write failing tests**, with `Storage::fake('s3')`, `Process::fake` (packager writes a `_audio.*` fixture into the dir via the closure) and `Queue::fake()`. Stub the probe through a seam: `AddTrackJob` resolves `App\Support\TrackProbe` from the container, so tests bind a fake. `TrackProbe::probe(string $url, string $type): ?array{channels:int,codec:?string,profile:?string,bit_rate:?int,duration:?float}`.
  - `it('grafts a new audio into every manifest of its outputs and marks it ready')`.
  - `it('creates one stream per rung for a 5.1 dub')`: they share `meta.track_upload`.
  - `it('pads and trims audio to the video duration')`: the ffmpeg command contains `apad=whole_dur=10.000` and `-t 10.000`.
  - `it('rejects audio that is more than 10% off')`: the stream is `failed` and the manifests are byte-identical.
  - `it('grafts a subtitle next to existing subtitles')`.
  - `it('leaves manifests untouched when the packager fails')`.
  - `it('stops when the video is no longer completed at publish time')` (Review Focus 1).
  - `it('only touches DASH for an opus track on an HLS+DASH output')` (Review Focus 2).
  - `it('sends video.track_ready with the track ulids')` / `it('sends video.track_failed')`.
  - `it('lets an added track be relabeled and deleted through the existing endpoints')`.
  - `it('declares WithoutOverlapping per video')`: assert on `(new AddTrackJob(1, 7))->middleware()`.
- [ ] **Step 2: Run** `--filter=AddTrackJobTest`. Expected: FAIL.
- [ ] **Step 3: Implement** as specified above.
- [ ] **Step 4: Run** the filter, then the full suite: `config:clear` and `php artisan test`. Expected: all PASS. Run `pint`.
- [ ] **Step 5: Run a manual smoke test on the local stack.**
  1. Pick a completed video in local.
  2. Run `docker compose exec nukevideo-api php artisan tinker` and dispatch through the real upload path, or upload through the panel once Task 7 lands.
  3. Confirm that `GET` on its HLS master lists the new track.

### Task 7: Panel — add track dialog and status badge

**Files:**
- Create: `front/src/pages/videos/components/AddTrackDialog.vue`
- Modify:
  - `front/src/pages/videos/VideoPage.vue` (an "Add track" button when `status === 'completed'`; on success it pushes the returned stream into `video.streams`, and polling picks up the status)
  - `front/src/pages/videos/components/StreamItem.vue` (a status badge for `processing`/`failed`, and `errorLog` in a tooltip when failed; the edit action is hidden while processing)
- Test: lint and type-check. The repo has no frontend unit tests.

**Interfaces:**
- Consumes: `App.Enums.StreamStatus` and `App.Data.StreamData['status']` from the regenerated `generated.d.ts`.

- [ ] **Step 1: Build `AddTrackDialog.vue`.**
  - It creates its **own** `Uppy` instance with `@uppy/aws-s3`, configured like `front/src/stores/upload.ts` (endpoint, credentials, XSRF refresh, 30 MB chunks). Extract the shared config into a `createUppy()` helper exported from `stores/upload.ts` instead of duplicating it.
  - Meta: `{ project, video: video.ulid, type, name, language, forced, hearingImpaired, visualImpaired }`.
  - The type is inferred from the extension (the allowlists from Task 5) and can be changed through a select.
  - The checkboxes depend on the type: forced and SDH for subtitles; audio description and SDH for audio.
  - It shows Uppy's 422 message inline.
  - It uses only the existing shadcn-vue components (`Dialog`, `Input`, `Select`, `Checkbox`, `Button`, `Progress`).
- [ ] **Step 2: Wire the dialog into `VideoPage.vue` and `StreamItem.vue`.**
- [ ] **Step 3: Run** `pnpm exec eslint .`, then `pnpm run type-check`. Expected: no errors. Then `pnpm run format`.
- [ ] **Step 4: Check it in the browser.** Open the video page at `*.nukevideo.localhost`, add an `.srt`, and watch the badge go processing → ready and the track appear in the player's menu.

### Task 8: Docs

**Files:**
- Modify:
  - `docs/api/streams.md` (an "Add a Track" section in the house format; the statuses table)
  - `docs/api/webhooks.md` (`video.track_ready` and `video.track_failed` rows, plus the top-level `tracks` field)
  - `docs/api/videos.md` (a cross-link from "Upload Metadata")

- [ ] **Step 1: Write the docs.** "Add a Track" covers:
  - the S3 flow with `metadata.video`, and the field table (`video`, `type`, `name`, `language`, `forced`, `hearingImpaired`, `visualImpaired`, `externalUserId`);
  - the extension allowlists;
  - the duration rule (pad or trim, and the reject threshold of more than 10%);
  - how the audio params come from the template;
  - that one file may produce several streams;
  - the 409 on editing or deleting a processing track;
  - the CDN caveat (self-hosted about 30 s; Bunny follows the manifest TTL).
- [ ] **Step 2: Build the docs:** `docker compose exec nukevideo-docs pnpm run docs:build`. Expected: the build succeeds with no dead links.
