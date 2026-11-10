# Worker capacity — Phases 0–1 Implementation Plan

> **Status (2026-11-06):** every task below was built and tested. After the production measurement in the spec's "Revision 2", only part of it stays on `feat/worker-capacity-phase-0-1`:
> - Task 1 (logs);
> - Task 2 (node sampler);
> - Task 3 (fan-out);
> - Task 5 without the heavy-batch wait (claim + reaper);
> - Task 10's `failed()` claim guard.
>
> Tasks 4, 6, 7, 8 and 9 are parked, complete, on the local branch `park/worker-capacity-phase-1-full`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every interruption of a video's preparation, chunk encode or packaging costs a retry, never the video, and every timeout, OOM kill and slow transfer leaves a log line and a memory sample to measure against.

**Architecture:**
- Phase 0 adds log lines and a per-node memory sampler stored in the shared Redis cache.
- Phase 1 changes several parts of the pipeline:
  - it makes `PrepareVideoJob`'s fan-out recoverable and its steps checkpointed;
  - it gives the prep a claim key the reaper respects;
  - it makes ffmpeg the preferred OOM victim;
  - it lets packaging resume an interrupted upload;
  - it moves big-source downloads onto a long-timeout connection.

No migrations; every behaviour change has an env kill switch or is inert for old payloads.

**Tech Stack:** Laravel 13.35 / PHP 8.4, Horizon 5.48, Pest 5, Redis (cache + queue), s5cmd 2.3.0, ffmpeg.

**Spec:** `docs/superpowers/specs/2026-11-06-worker-capacity-design.md`, Phases 0–1. Its "Revision 1" does not change these phases.

## Global Constraints

- **House rules (`CLAUDE.md`):**
  - Business logic lives in `app/Services` or `app/Support`.
  - Jobs take primitives, keep their queue in a class constant, and state `$tries`/`$backoff` with a comment on why.
  - Comments explain why and reason about failure modes; everything is in English.
- **No new dependencies.** No edits to `Dockerfile`, CI, `compose.yml` or `vod/`.
- **No migration.** State shared by the API host (H) and the workers (W) lives in the Redis cache (`Cache::` default store, `redis` in production, `array` in tests).
- **CI runs on SQLite with no Redis:**
  - Nothing new may require Redis in tests.
  - Horizon/Redis reads are wrapped so they degrade to "unknown".
- **New job properties sit outside the constructor, with defaults** (`public ?string $claim = null;`), so old payloads deserialize.
- **`then()` closures stay self-contained.** They use DB queries and primitives only, with no new class or method references: an old worker may run them during a rolling deploy.
- **Invariant:** the prep timeout (1800) stays below `REDIS_QUEUE_RETRY_AFTER` (1850).
- **Tests:** run `docker compose exec nukevideo-api php artisan config:clear` before running them. Then run `docker compose exec nukevideo-api php artisan test --filter=<Name>`.
- **Formatting:** `docker compose exec nukevideo-api ./vendor/bin/pint` on touched files.
- **Commits: none.** The owner reviews first. Work on branch `feat/worker-capacity-phase-0-1` and leave changes uncommitted. Every "Commit" step below is replaced by "run Pint + the task's tests".

## Review Focus

1. **Rolling deploy.** Old payloads (no `retryUntil`, no `claim`) run on new workers, and new payloads on old workers. Both must behave exactly as today; Task 4, Task 5 and Task 6 each pin this with a test.
2. **Redis cache flaps** (the Tailscale/CGNAT link). Expected behaviour:
   - The sampler and the package-sync counter swallow cache errors.
   - A claim refresh that fails must not fail the job.
   - The reaper failing to read the claim crashes the reaper run, which is safe, never the video.
   - Task 2 and Task 5 add a throwing-cache test.
3. **Two-queue templates (CPU + GPU) interrupted mid-fan-out.** Only the missing queue is fanned out, and the video never packages before every expected queue has a finished batch with jobs (Task 3).
4. **A retried video whose old prep job is still queued.** The late job must not run beside the retry's own prep (Task 5: claim mismatch exits).
5. **Our own timeout vs a kernel SIGKILL.**
   - Exit 137 from a process our `Process::timeout()` did not stop means OOM; a `ProcessTimedOutException` keeps today's path.
   - The OOM release must never loop for a whole retry window (Task 7: last attempt throws; probe killed twice in one step throws).

---

### Task 1: Phase 0 log lines (spec 0a–0d)

**Files:**
- Create: `app/Listeners/LogJobTimedOut.php` (auto-discovered: there is no `app/Listeners` today and Laravel 13 discovers it; do not also register it by hand)
- Modify: `app/Jobs/PrepareVideoJob.php` (`ensureLocalSource()`, `handle()` timing, new `logPlanned()`)
- Modify: `app/Jobs/PackageVideoJob.php` (`syncToPrimary()`)
- Test: `tests/Feature/Encoding/PipelineLogsTest.php`

**Interfaces:**
- Produces:
  - `LogJobTimedOut::handle(JobTimedOut $event): void`.
  - `PrepareVideoJob::logPlanned(Video $video, array $timings, int $jobs): void`, private. `$timings` keys: `probe_s`, `plan_s`, `fanout_s`.
  - `PrepareVideoJob::claimWaitSeconds(): ?int`, private: now − payload `createdAt`, or null without a queue job.

- [ ] **Step 1: Write the failing tests**
  - `it('logs a timed-out job with its class, queue, uuid and attempts')`:
    - Build a Mockery `Illuminate\Contracts\Queue\Job` with `resolveName()` = `App\Jobs\PrepareVideoJob`, `getQueue()` = `orchestration`, `uuid()` = `u-1`, `attempts()` = `2`.
    - Spy `Log`, then fire `event(new JobTimedOut('redis', $job, 1800))`.
    - Assert `Log::warning('Queue job timed out', ['class' => PrepareVideoJob::class, 'queue' => 'orchestration', 'uuid' => 'u-1', 'attempts' => 2, 'timeout' => 1800])`.
  - `it('never throws from the timeout listener')`: the mock's `resolveName()` throws `RuntimeException`, and firing the event raises nothing. The listener runs inside the SIGALRM handler.
  - `it('logs the source fetch with its origin, bytes and seconds')`:
    - Fake `s3`/`chunks` with a 1 234-byte original, and `Process::fake()`.
    - Call `ensureLocalSource()` through closure binding, as `StaleChunkPlanTest` does.
    - Assert `Source fetch started` with `['video' => id, 'from' => 's3', 'bytes' => 1234]`.
    - Assert `Source fetched` with keys `from`, `bytes`, `s`, `attempt`.
    - When the mirror already exists, `from` is `mirror` and `bytes` is the mirror's size.
  - `it('logs the planned preparation with its step timings')`: call `logPlanned($video, ['probe_s' => 12.5, 'plan_s' => 3.0, 'fanout_s' => 1.2], 40)`. Assert `Preparation planned` carries `probe_s`, `plan_s`, `fanout_s`, `jobs` = 40, `attempt`, and `claim_wait_s` (null here).
  - `it('logs a package sync with bytes, seconds and concurrent syncs')`:
    - Fake the s5cmd sync with `Process::fake()`, and build a gather dir with two files totalling 3 000 bytes.
    - Call `syncToPrimary()` via closure binding.
    - Assert `Package synced` with `bytes` = 3000, an `s` key, and `concurrent` = 1.

- [ ] **Step 2: Run them to verify they fail** — `php artisan test --filter=PipelineLogsTest`; expected FAIL (missing listener / log lines).

- [ ] **Step 3: Implement**
  - **Listener:** the whole body sits in `try { … } catch (Throwable) {}`. The context is `class`, `queue`, `uuid`, `attempts` and `timeout`.
  - **`ensureLocalSource()`:**
    - Replace `Storage::disk('chunks')->exists($mirrorPath)` with a `size()` call. `UnableToRetrieveMetadata` means "no mirror". It is the same HEAD request.
    - The `s3` branch reads `Storage::disk('s3')->size($this->originalPath)`.
    - Log `Source fetch started` before the copy and `Source fetched` after it: `s` is the rounded wall seconds, and `attempt` is `$this->attempts()`.
  - **`handle()`:** measure `probe_s` (`resolveRenditions`), `plan_s` (`planWindows`) and `fanout_s` (`fanOut`) with `microtime(true)`, then replace the existing `Log::info('Preparation planned', …)` with `logPlanned()`.
  - **`syncToPrimary()`:**
    - Sum the file sizes under `$gatherDir` and `$processedDir`.
    - `Cache::increment('package-syncs')` before the sync and `Cache::decrement` in a `finally`; both sit in `try/catch` that ignores cache errors.
    - Log `Package synced` with `video`, `bytes`, `s` and `concurrent` (the value right after the increment).

- [ ] **Step 4: Run the tests to verify they pass** — same filter; expected PASS. Then run `--filter='RetryVideoTest|StaleChunkPlanTest|GpuQueueRoutingTest'`; expected PASS.

- [ ] **Step 5: Pint the touched files.**

---

### Task 2: Node sampler and `fleet:samples` (spec 0e)

**Files:**
- Create: `app/Support/NodeSampler.php`
- Create: `app/Console/Commands/FleetSamplesCommand.php`
- Modify: `config/nuke.php` (`'node_id' => env('NODE_ID')`)
- Modify: `app/Services/Concerns/EmitsHeartbeat.php` (call the sampler after a heartbeat write)
- Modify: `app/Console/Commands/DispatchPendingVideosCommand.php` (record queue lengths and Horizon process counts)
- Test: `tests/Feature/Encoding/NodeSamplerTest.php` (fixtures under `tests/Fixtures/node/{proc,sys/fs/cgroup}`)

**Interfaces:**
- Produces:
  - `NodeSampler::sample(?string $root = null): void`. `$root` defaults to `/`, and tests point it at a fixture dir. It never throws.
  - `NodeSampler::recordQueues(array $lengths, array $processes): void`.
  - Cache keys:
    - `fleet-sample-gate:{node}`: `Cache::add`, 30 s;
    - `fleet-sample:{node}:{YmdHi}`: an array with keys `anon_max`, `mem_available_min`, `mem_total`, `oom_kill`, `load1_max` and `cores`;
    - `fleet-sample:queues:{YmdHi}`.

    Both sample keys live 7 days.
  - Command: `fleet:samples {--hours=48}`.

- [ ] **Step 1: Write the failing tests**
  - `it('records anon, oom kills, load and available memory under the node and minute')`:
    - Set `config(['nuke.node_id' => 7])` and `Carbon::setTestNow('2026-11-06 12:00:10')`.
    - The fixtures hold `memory.stat` `anon 2147483648`, `memory.events` `oom_kill 3`, `loadavg` `5.50 …`, and `meminfo` `MemTotal: 65536000 kB` / `MemAvailable: 30000000 kB`.
    - Expect `Cache::get('fleet-sample:7:202611061200')` to match `['anon_max' => 2147483648, 'oom_kill' => 3, 'load1_max' => 5.5, 'mem_available_min' => 30000000 * 1024, 'mem_total' => 65536000 * 1024]`.
  - `it('samples at most once per 30 seconds per node')`: rewrite the anon fixture to a larger value and sample again at +10 s; the value is unchanged.
  - `it('keeps the maximum anon and the minimum available memory within a minute')`: the second sample at +31 s has a lower anon; `anon_max` keeps the first.
  - `it('does nothing on a host without a node id')`: `nuke.node_id` null → no `fleet-sample:*` key.
  - `it('never throws when the cgroup files are missing or the cache fails')`:
    - With an empty root dir, there is no exception and the fields stay absent.
    - With `Cache::shouldReceive('add')->andThrow(new RuntimeException)`, there is no exception either.
  - `it('reports max and p95 anon, oom kills and idle minutes per node')`:
    - Seed 60 minute keys for node 7, with `anon_max` rising to 90% of `mem_total`, `oom_kill` going 3 → 5, and `cores` 32.
    - Run `fleet:samples --hours=2`; the output contains `90%`, `oom kills: 2` and the node's name.

- [ ] **Step 2: Run them to verify they fail** — `--filter=NodeSamplerTest`.

- [ ] **Step 3: Implement**
  - **`sample()`:**
    - Return when `config('nuke.node_id')` is null, or when `Cache::add` on the gate fails.
    - Read each file in its own `try`. Merge into the minute key with max/min semantics; `oom_kill` keeps the latest reading and `cores` comes from `Cpu::cores()`.
    - `Cache::put` with a 7-day TTL. The whole method is wrapped in `try/catch (Throwable)`.
  - **`EmitsHeartbeat::heartbeat()`:** call `NodeSampler::sample()` right after `$video->heartbeat()`.
  - **`videos:dispatch`:** after the active-node check, call `NodeSampler::recordQueues()`, wrapped in `try/catch (Throwable)`. Its inputs are:
    - lengths from `Queue::connection()->size($q)`, for `orchestration`, `packaging`, `video-processing` and the active accel queues;
    - processes from `app(\Laravel\Horizon\Contracts\SupervisorRepository::class)->all()`, summed per queue.
  - **`fleet:samples`:**
    - One row per worker `Node`: samples, max anon %, p95 anon %, min `MemAvailable`, `oom_kill` delta (max − min), max load1, and idle minutes (minutes with `load1_max` < 0.1 × `cores`).
    - Then the queue maxima.

- [ ] **Step 4: Run the tests to verify they pass** — same filter, then `--filter=DispatchConcurrencyTest` to confirm the dispatcher still passes without Redis.

- [ ] **Step 5: Pint.**

---

### Task 3: Interruption-safe fan-out (spec 1a; the video 17621 bug)

**Files:**
- Modify: `app/Jobs/PrepareVideoJob.php` (redelivery guard in `handle()`, `fanOut()`, the `then()` gate)
- Test: `tests/Feature/Encoding/FanOutRecoveryTest.php`

**Interfaces:**
- Produces:
  - `PrepareVideoJob::fanOutComplete(Video $video): bool`, private. It is true only when the video has `video` streams and every queue in `$streams->map->encodeQueue()->unique()` has a `job_batches` row named `encode video {id} {queue}` with `total_jobs > 0`.

- [ ] **Step 1: Write the failing tests**

  Use the `encodeBatch()` row shape of `ReapWindowTest`, with `total_jobs` as a parameter.
  - `it('fans out again when the only batch row is empty (video 17621)')`:
    - The video has one libsvtav1 rendition and one `encode video {id} video-processing` row with `total_jobs = 0`.
    - `fanOutComplete()` is false.
    - With `Bus::fake()`, `fanOut($video, [[0,15],[15,30]], 'm.mp4')` batches `encode video {id} video-processing` with 2 jobs, and the empty row is gone.
  - `it('skips a redelivery once every queue has a batch with jobs')`: one row with `total_jobs = 40` → `fanOutComplete()` true.
  - `it('fans out only the queue whose batch is missing')`:
    - The streams are libsvtav1 + av1_qsv, with an active intel `Node` (as in `GpuQueueRoutingTest`), and a `video-processing` row with jobs.
    - `fanOut()` batches only `encode video {id} video-processing-intel`.
    - The CPU rendition's `.plan` key is not rewritten, and `chunk_count` keeps its old value.
  - `it('does not package while an expected queue has no finished batch')`:
    - Take the intel batch's `then` closure from `Bus::assertBatched` (`$pending->thenCallbacks()[0]`).
    - With the `video-processing` row deleted, run it: the video stays `running` and no `PackageVideoJob` is queued (`Queue::fake()`).
    - With both rows finished, with jobs and uncancelled, run it again: the status is `uploading` and `PackageVideoJob` is pushed.
  - `it('does not package when an expected queue only has a cancelled batch')`: set `cancelled_at` on one row → no transition.

- [ ] **Step 2: Run them to verify they fail** — `--filter=FanOutRecoveryTest`.

- [ ] **Step 3: Implement**
  - **Guard:** `if ($this->fanOutComplete($video)) { Log::info('Segments already planned; skipping redelivery', …); return; }`.
  - **`fanOut()`:**
    1. Delete this video's rows with `total_jobs = 0`, logging `Empty encode batch removed` with `video` and `queue`.
    2. `$done` = the queues that still have a row with `total_jobs > 0`.
    3. `$missing` = the expected queues minus `$done`.
    4. Run `discardStaleChunks`, output status and progress seeding only for the streams of `$missing`, and write `chunk_count` only when `$done` is empty.
    5. Batch only `$missing`.
  - **`then()` closure:** capture `$expectedQueues` (a list of strings) next to `$videoId`. It completes only when:
    - no row of the video has a null `finished_at`;
    - every expected queue has a row with `total_jobs > 0`, `finished_at` not null and `cancelled_at` null.

    Keep it plain DB queries; keep the existing active-status check and `PackageVideoJob::dispatchIfReady`.

- [ ] **Step 4: Run the tests** — `--filter='FanOutRecoveryTest|StaleChunkPlanTest|RetryVideoTest|GpuQueueRoutingTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 4: Prep retry policy and side jobs only when missing (spec 1b retry table + side jobs)

**Files:**
- Modify: `app/Jobs/PrepareVideoJob.php`
- Modify: `tests/Pest.php` (add helper `fakeQueueJob(array $payload = [], int $attempts = 1): FakeJob`, an anonymous `FakeJob` subclass whose `getRawBody()` returns `json_encode($payload)`)
- Test: `tests/Feature/Encoding/PrepRetryPolicyTest.php`

**Interfaces:**
- Produces:
  - The `#[CountCrashesAsExceptions]` attribute (`Illuminate\Queue\Attributes\CountCrashesAsExceptions`), with `$maxExceptions = 3`.
  - `retryUntil(): DateTimeInterface` = `now()->addHours(12)`.
  - `$tries` is removed; a comment explains why: `retryUntil` governs, and old payloads keep their `maxTries=5`.
  - `dispatchSideJobs(Video $video, string $mirrorPath): void`, private.

- [ ] **Step 1: Write the failing tests**
  - `it('retries a preparation for 12 hours and fails on the third counted failure')`:
    - `(new PrepareVideoJob(1, 'o.mp4'))->retryUntil()` equals `now()->addHours(12)` under `Carbon::setTestNow`.
    - `maxExceptions` is 3.
    - `(new ReflectionClass(PrepareVideoJob::class))->getAttributes(CountCrashesAsExceptions::class)` is not empty.
  - `it('does not dispatch a side job whose output already exists')`:
    - With `Storage::fake('s3')`, the thumbnail exists at `$video->assetKey(Video::THUMBNAIL_FILENAME)` and the storyboard VTT does not.
    - The audio stream is staged on `chunks` at `stagingPath()`.
    - With `Queue::fake()`, call `dispatchSideJobs()`: `GenerateVideoStoryboard` is pushed; `ExtractThumbnailJob` and `EncodeSidecarTracksJob` are not.
  - `it('dispatches every side job on a first preparation')`: nothing exists yet, so all three are pushed on `orchestration`.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement.** Move the three dispatches from `handle()` into `dispatchSideJobs()`, with existence checks for:
  - the thumbnail key on `s3`;
  - the storyboard key on `s3`, via `$video->assetKey(Video::STORYBOARD_VTT_FILENAME)`;
  - for the sidecar, any audio or subtitle stream whose `stagingPath()` is missing on `chunks`.

- [ ] **Step 4: Run the tests** — `--filter='PrepRetryPolicyTest|RetryVideoTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 5: Prep claim key, and a reaper that waits for queued work (spec 1d)

**Files:**
- Create: `app/Support/PrepClaim.php`
- Modify:
  - `app/Console/Commands/DispatchPendingVideosCommand.php`: issue the claim and dispatch with it.
  - `app/Jobs/PrepareVideoJob.php`: the `$claim` property, `withClaim()`, refresh on heartbeat, forget on clean exit and in `failed()`, a hop copies it, and a mismatch exits.
  - `app/Console/Commands/ReapStuckVideos.php`: `isWaitingOnTheQueue()`.
  - `app/Services/VideoService.php`: make `sidecarPassRunning()` public and add a public `packagingHoldsLock(Video $video): bool` over the existing private `holdsUniqueLock()`.
- Test: `tests/Feature/Encoding/PrepClaimTest.php`; extend `tests/Feature/Encoding/ReapWindowTest.php`

**Interfaces:**
- Produces:
  - `PrepClaim::KEY` = `prep-pending:%d`.
  - `PrepClaim::TTL` = `21600`.
  - `issue(int $videoId, int $ttl = self::TTL): string`: a ULID token.
  - `refresh(int $videoId, ?string $token, int $ttl = self::TTL): void`: re-puts only while the key holds `$token`, or exists when `$token` is null.
  - `holds(int $videoId, ?string $token): bool`: a null token holds, because it is an old payload.
  - `pending(int $videoId): bool`.
  - `forget(int $videoId, ?string $token): void`: deletes only while it holds.

  `refresh` and `forget` swallow cache errors and log a warning; `pending` lets them propagate, since the reaper crashing is safe.
  - `PrepareVideoJob::$claim` is `public ?string $claim = null`, declared outside the constructor.
  - `PrepareVideoJob::withClaim(?string $token): static`.

- [ ] **Step 1: Write the failing tests**
  - **`PrepClaimTest`:**
    - `it('issues a claim at dispatch and hands the token to the job')`: with a CPU node, a pending video and `Queue::fake()`, run `videos:dispatch`. The pushed job's `claim` equals `Cache::get('prep-pending:{id}')`.
    - `it('keeps the claim across a node hop')`: with the `nodeHops` setup from `GpuQueueRoutingTest`, the hop job pushed has the same `claim`.
    - `it('drops the claim when the preparation hands the video back')`: `releasedForMissingCapacity()` → true, and `PrepClaim::pending()` → false.
    - `it('drops the claim in failed()')`.
    - `it('exits without work when its claim was superseded')`:
      - `PrepClaim::issue()` runs twice; the job carries the first token.
      - `handle()` returns before any S3 access: no `Storage::fake('s3')` file exists and no exception is thrown.
      - It logs `Preparation skipped: claim superseded`.
    - `it('runs as today when the payload carries no claim')`: a null claim and an existing key → `holds()` is true.
    - `it('does not fail the job when the cache refresh fails')`: `Cache::shouldReceive('put')->andThrow(...)` on `refresh` → no exception.
  - **`ReapWindowTest`:**
    - `it('leaves a stale video alone while its preparation is pending')`: stalled 60 min with `PrepClaim::issue()` → still `running`.
    - `it('fails a stale video once its claim is gone')`: stalled 60 min, with no claim, no batch and no lock → `failed`.
    - `it('leaves a stale video alone while packaging holds its unique lock')`: acquire `Cache::lock('laravel_unique_job:'.PackageVideoJob::class.':'.$id, 10800)` → `running`.
    - `it('waits up to 48 hours for a pending heavy batch')`:
      - An unfinished `encode video {id} video-processing-heavy` row with `pending_jobs > 0` and the heartbeat 20 h ago → `running`.
      - 49 h ago → `failed`.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement**
  - **Dispatcher:** after the atomic claim, `$token = PrepClaim::issue($video->id)` and `dispatch((new PrepareVideoJob($video->id, $originalPath))->withClaim($token))->onQueue(self::QUEUE)`. On dispatch failure, `PrepClaim::forget()` before reverting to PENDING.
  - **Job:**
    - At the top of `handle()`, when `$this->claim !== null` and `! PrepClaim::holds(...)`, log `Preparation skipped: claim superseded` and return.
    - Refresh in the heartbeat path: alias the trait method, `use SyncsViaS5cmd { heartbeat as private beatVideo; }`, and define `heartbeat()` = `beatVideo()` + `PrepClaim::refresh()`, throttled by the same interval.
    - Forget on every clean exit: fanned out, `fanOutComplete` skip, handed back, video no longer active, and `failed()`.
    - Do not forget on a crash, a timeout or a `release()`.
    - The hop passes `->withClaim($this->claim)`.
  - **Reaper `isWaitingOnTheQueue()`:** true when any of these holds:
    - an existing batch with pending jobs (today's check, under the `ABANDONED_MINUTES` ceiling);
    - `PrepClaim::pending($id)`;
    - `$videos->sidecarPassRunning($video) || $videos->packagingHoldsLock($video)`;
    - an unfinished `… video-processing-heavy` batch with pending jobs while the heartbeat is under 48 h old, with the constant `HEAVY_ABANDONED_MINUTES = 2880`.

    Inject `VideoService` into `handle()`.

- [ ] **Step 4: Run the tests** — `--filter='PrepClaimTest|ReapWindowTest|StuckTrackReaperTest|DispatchConcurrencyTest|GpuQueueRoutingTest|VideoPriorityTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 6: Checkpointed preparation (spec 1b steps)

**Files:**
- Modify: `app/Jobs/PrepareVideoJob.php`
- Test: `tests/Feature/Encoding/CheckpointedPrepTest.php`

**Interfaces:**
- Consumes: `retryUntil()` (Task 4) and `PrepClaim` refresh (Task 5).
- Produces (all private on `PrepareVideoJob`):
  - `float $attemptStartedAt`, set first thing in `handle()`;
  - `deadline(): float` = `$attemptStartedAt + $this->timeout - 60`;
  - `canRelease(): bool`: there is a queue job, its payload `retryUntil` is not null, and `retryUntil - now() >= $this->timeout + 10`;
  - `fits(int $estimate): bool` = `microtime(true) + $estimate <= deadline()`;
  - `releaseFor(string $step): void`: logs `Preparation released: {step} would not fit` with `video`, `step`, `estimate` and `left`, then calls `$this->release(10)`;
  - `renditionEstimate(Stream $next, Collection $done): int`;
  - stream meta `prepared` (bool) and `prep_step_s` (float), written after a rendition's per-title, QBP and preflight all ran.

- [ ] **Step 1: Write the failing tests**
  - `it('estimates a rendition from an earlier one scaled by pixels')`: done 1920×1080 with `prep_step_s` 300; next 1280×720 → `(int) ceil(300 * (1280*720)/(1920*1080) * 1.2)` = 160.
  - `it('starts from 300 s up to 2,304,000 pixels and 900 s above')`: nothing done → 1920×1200 gives 300 and 3840×2160 gives 900.
  - `it('releases instead of starting a rendition that would not fit')`:
    - `$job->setJob(fakeQueueJob(['retryUntil' => now()->addHours(10)->timestamp]))` and `attemptStartedAt` = now − 1700.
    - Run the rendition step through closure binding on a 1080p stream with no `prepared` flag.
    - The fake job is released with delay 10, and `PerTitleCrfService` never ran: no `meta.per_title`.
  - `it('runs as today when the payload carries no retryUntil')`: same setup with `fakeQueueJob([])` → no release, and the step runs (stub the probes with `Process::fake()`).
  - `it('runs as today when less than one attempt is left before retryUntil')`: `retryUntil` = now + 1000 → no release.
  - `it('skips renditions an earlier attempt already prepared')`: `meta.prepared` true → no probe command runs (`Process::assertNothingRan()`).
  - `it('always runs the first step of an attempt')`: the download runs even with `attemptStartedAt` = now − 1790.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement**

  The steps, in order:
  1. `ensureLocalSource()` + streams. This is the first step and always runs.
  2. Side jobs (Task 4).
  3. Per video rendition not yet `prepared`: estimate → `fits` or (`canRelease` → `releaseFor("rendition {$stream->id}")`, return) → run the three probes → write `prepared`/`prep_step_s`.
  4. Window plan: estimate 120 s.
  5. Fan-out: estimate `60 + (int) ceil(0.15 * windows * renditions)`.

  Steps that do not fit and cannot release run anyway, which is today's behaviour; per-title keeps its own 420 s budget. A release never forgets the claim, and the `finally` still unlinks the local scratch.

  `renditionEstimate()`:
  - With an earlier measurement: `ceil(max over $done of prep_step_s × pixels(next) / pixels(done) × 1.2)`.
  - Otherwise 300 if `width × height ≤ 2_304_000`, else 900.

- [ ] **Step 4: Run the tests** — `--filter='CheckpointedPrepTest|FanOutRecoveryTest|PrepClaimTest|PrepRetryPolicyTest|PerTitleCrfServiceTest|RenditionPreflightTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 7: ffmpeg as the OOM victim (spec 1c)

**Files:**
- Create:
  - `app/Support/OomScore.php`
  - `app/Exceptions/ProbeKilledException.php`
- Modify:
  - `config/nuke.php`: `'video.chunk_oom_score_adj' => (int) env('CHUNK_OOM_SCORE_ADJ', 1000)`, plus a `.env.example` entry with a comment.
  - `app/Jobs/ProcessChunkJob.php`: `encode()` returns `bool`, `false` meaning released.
  - The ffmpeg runs in `app/Services/PerTitleCrfService.php`, `app/Services/SampleEncode.php`, `app/Services/QualityBitrateProbe.php` and `app/Services/RenditionPreflight.php`.
  - `app/Jobs/PrepareVideoJob.php`: catch `ProbeKilledException` in the rendition step.
- Test: `tests/Feature/Encoding/OomVictimTest.php`

**Interfaces:**
- Produces:
  - `OomScore::chunk(): int` = the config value.
  - `OomScore::probe(): int` = `intdiv(chunk(), 2)`.
  - `OomScore::wrap(string $command, int $score): string|array`:
    - with `$score > 0` it returns `['sh', '-c', "echo {$score} >/proc/self/oom_score_adj 2>/dev/null; exec {$command}"]`;
    - otherwise it returns `$command` unchanged.

    The wrapper is needed because Symfony Process prefixes string commands with `exec `, so a bare `echo …; exec ffmpeg` would exec `echo` and never run ffmpeg.
  - `OomScore::killed(int $exitCode): bool` = `$exitCode === 137`.
  - `ProbeKilledException extends RuntimeException`. QBP and preflight throw it on a SIGKILL; per-title keeps swallowing failures as today.
  - Cache key `prep-probe-kills:{video}:{stream}`: 12 h TTL, incremented by the prep.

- [ ] **Step 1: Write the failing tests**
  - `it('starts chunk ffmpeg with an oom score of 1000')`: with `Process::fake()`, run the chunk encode via closure binding. `Process::assertRan` sees a command array starting with `['sh', '-c']`, whose script contains `echo 1000 >/proc/self/oom_score_adj` and `exec ffmpeg`.
  - `it('runs ffmpeg unwrapped when CHUNK_OOM_SCORE_ADJ is 0')`: the command is a string starting with `ffmpeg`.
  - `it('starts probe ffmpeg with an oom score of 500')`: a preflight on a fake process → the script contains `echo 500 >`.
  - `it('releases a chunk whose ffmpeg was SIGKILLed instead of counting an exception')`:
    - Use `Process::fake(['*' => Process::result(exitCode: 137)])` and `$job->withFakeQueueInteractions()`.
    - `handle()` raises nothing, `assertReleased(30)` holds, and the warning `Chunk ffmpeg killed by SIGKILL (likely OOM)` is logged.
  - `it('fails a chunk OOM-killed on its last attempt')`: with the fake job's attempts at 5, `handle()` throws `Chunk OOM-killed 5 times`.
  - `it('releases the preparation when a preflight is SIGKILLed once and fails it the second time in the same step')`:
    - The preflight's process returns 137, under `canRelease()` conditions.
    - The first run is released with 10.
    - The second run, with the kill counter at 1, throws `ProbeKilledException`.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement**
  - Wrap every chunk and probe `Process` command with `OomScore::wrap`. Sidecar, thumbnail and storyboard are untouched.
  - **`ProcessChunkJob::encode()`:** check `OomScore::killed($process->exitCode())` before `causedTermination`:
    - log the warning with `stream`, `chunk` and `attempt`;
    - if `$this->attempts() >= $this->tries`, throw `RuntimeException("Chunk OOM-killed {$this->attempts()} times")`;
    - otherwise `$this->release(30)` and return `false`.

    `handle()` and `assertWindowEncoded()` stop when `encode()` returns `false`.
  - **QBP and preflight:** on exit 137, throw `ProbeKilledException`.
  - **Prep rendition step:** catch it:
    - increment `prep-probe-kills:{video}:{stream}`;
    - if the count is ≥ 2, rethrow;
    - else if `canRelease()`, `releaseFor("rendition {$id} after OOM")`;
    - else rethrow.

- [ ] **Step 4: Run the tests** — `--filter='OomVictimTest|ChunkProgressTest|SourceGapTest|TruncatedSourceTest|PerTitleCrfServiceTest|RenditionPreflightTest|CheckpointedPrepTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 8: Resumable packaging upload (spec 1e)

**Files:**
- Modify: `app/Jobs/Concerns/SyncsViaS5cmd.php` (`s5cmdSyncTyped()` adds `--size-only` to the `m4s` pass)
- Modify: `app/Jobs/PackageVideoJob.php` (`$startedAt`, `syncToPrimary()` returns `bool`, release on timeout; download-zone pass adds `--size-only`)
- Test: `tests/Feature/Video/ResumablePackagingTest.php`

**Interfaces:**
- Produces:
  - `PackageVideoJob::syncToPrimary(...): bool`: false means released.
  - `private int $startedAt`.
  - `private function syncTimeout(): int` = `max(60, $this->startedAt + $this->timeout - time() - 60)`.

- [ ] **Step 1: Write the failing tests**
  - `it('uploads segments with --size-only so a retry skips what already landed')`:
    - With `Process::fake()`, run `syncToPrimary()` with a processed dir.
    - The `*.m4s` pass and the download-zone pass contain `--size-only`; the `*.mpd` and `*.m3u8` passes do not.
  - `it('releases instead of failing when the upload runs out of time')`:
    - The fake process throws `ProcessTimedOutException` on the m4s pass, and the job has fake queue interactions at attempts 2.
    - `handle()` returns, `assertReleased(60)` holds, and the outputs are not COMPLETED.
  - `it('fails on the last attempts instead of releasing forever')`: at attempts 4 (= `$tries - 1`) the timeout is rethrown.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement**
  - Set `$startedAt = time()` first in `handle()`. Every sync in `syncToPrimary()` uses `syncTimeout()`.
  - Catch `Illuminate\Process\Exceptions\ProcessTimedOutException`:
    - while `$this->attempts() <= $this->tries - 2`, log `Package sync released: out of time` (with `video`, `attempt` and `bytes_left` unknown → omit) and `release(60)`, then return `false`;
    - otherwise rethrow.
  - `handle()` returns before the output/finalize block on `false`; the `finally` cleanup stays.
  - Leave `uniqueFor` (10800) as is: 5 × 1800 = 9000 < 10800. Add the comment the spec asks for: if packaging ever moves to `redis-long`, raise `uniqueFor` and the reaper's lock wait above `$tries` × the new timeout.

- [ ] **Step 4: Run the tests** — `--filter='ResumablePackagingTest|PackageFailureTest|OutweighsSourceWarningTest|ManifestGraftTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 9: Big-source fetch on a long connection (spec 1f)

**Files:**
- Create: `app/Jobs/FetchSourceJob.php`
- Modify:
  - `config/queue.php`: a `redis-long` connection.
  - `config/horizon.php`: a worker supervisor `fetch-worker`.
  - `config/nuke.php`: `video.big_source_fetch_gb` and `video.big_sources_in_flight`.
  - `.env.example`.
  - `app/Console/Commands/DispatchPendingVideosCommand.php`: routing and the cap.
  - `app/Jobs/PrepareVideoJob.php`: extract the shared download/mirror code into `app/Services/SourceMirror.php`.
- Test: `tests/Feature/Encoding/BigSourceFetchTest.php`

**Interfaces:**
- Produces:
  - **`redis-long` connection:** driver `redis`, `connection` = `env('REDIS_QUEUE_CONNECTION','default')`, `queue` `fetch`, `retry_after` `15000`, `block_for` null, `after_commit` false. In tests it uses `NODE_OPS_QUEUE_DRIVER`-style overriding: `env('LONG_QUEUE_DRIVER','redis')`, set to `sync` in `phpunit.xml`.
  - **`fetch-worker` supervisor:** connection `redis-long`, queue `['fetch']`, balance `none`, `minProcesses` 1, `maxProcesses` 1, `timeout` 14460, `tries` 1, `memory` 512. It runs on workers only.
  - **Config:**
    - `nuke.video.big_source_fetch_gb` = `env('BIG_SOURCE_FETCH_GB')`; null or 0 means routing is off, and that is the default.
    - `nuke.video.big_sources_in_flight` = `(int) env('BIG_SOURCES_IN_FLIGHT', 2)`.
  - **`FetchSourceJob`:**
    - Constructor: `(int $videoId, string $originalPath, int $bytes)`, with `public ?string $claim = null` and `withClaim()`.
    - `QUEUE = 'fetch'`, `CONNECTION = 'redis-long'`.
    - `$tries = 3`, with the reason: a dead worker or a flaky link, and each attempt resumes nothing but is bounded. `$backoff = [300]`.
    - `$timeout` = `min(14400, max(1800, (int) ceil($bytes / 2_000_000)))`, set in the constructor.
    - `failed()` puts the video back to PENDING (guarded on active statuses) and calls `PrepClaim::forget`.
  - **`SourceMirror::ensureMirrored(Video $video, string $originalPath, string $mirrorPath, Closure $beat, int $timeout): void`:** used by `FetchSourceJob`, so the download, the mirror upload and the local cleanup live once.
  - **Claim TTL for a big source:** `15000 + $timeout + 600`.

- [ ] **Step 1: Write the failing tests**
  - `it('routes a source above the threshold to a FetchSourceJob on the long connection')`:
    - Set `config(['nuke.video.big_source_fetch_gb' => 7])` with the original's `file_size` at 8e9 and `Queue::fake()`.
    - `videos:dispatch` pushes `FetchSourceJob` with connection `redis-long` and queue `fetch`, and no `PrepareVideoJob`.
    - The claim's TTL covers `15000 + timeout`.
  - `it('keeps routing off when BIG_SOURCE_FETCH_GB is unset')`: the same video → `PrepareVideoJob`.
  - `it('admits at most BIG_SOURCES_IN_FLIGHT big sources')`: two big videos already RUNNING with big originals → a third stays PENDING, while a small one behind it is dispatched.
  - `it('reads the size from S3 when the upload recorded 0')`: with `file_size` 0 and the `Storage::fake('s3')` object at 8e9 bytes (`Storage::shouldReceive` size), it routes to fetch.
  - `it('mirrors the source and then dispatches the preparation with the claim')`:
    - Use `Process::fake()` for s5cmd, `Storage::fake('chunks')` and `Queue::fake()`, then call `FetchSourceJob::handle()`.
    - The status passes through DOWNLOADING, and `PrepareVideoJob` is pushed on `orchestration` with the same claim.
  - `it('hands the video back to pending when the fetch fails for good')`: `failed()` → PENDING and the claim gone.
  - `it('sizes the fetch timeout from the bytes, between 30 minutes and 4 hours')`: 1e9 → 1800, 1e10 → 5000, 5e10 → 14400.

- [ ] **Step 2: Run them to verify they fail.**

- [ ] **Step 3: Implement**
  - **Dispatcher, in `tryDispatch()` after the capacity checks:**
    - `$bytes` = the original stream's `file_size`; when it is 0, use a guarded `Storage::disk('s3')->size()`, falling back to 0.
    - If routing is on and `$bytes > big_source_fetch_gb × 1e9`, skip (stay PENDING) while the in-flight count reaches `big_sources_in_flight`.
    - The in-flight count is the videos in `IN_FLIGHT` whose original `file_size` is above the threshold.
    - Otherwise claim and dispatch `FetchSourceJob`, with the claim issued at the big-source TTL.
  - **`FetchSourceJob::handle()`:**
    1. Run the active-status guard, then mark the video DOWNLOADING.
    2. Call `SourceMirror::ensureMirrored`, where the heartbeat also refreshes the claim at the big TTL.
    3. Dispatch `PrepareVideoJob` with the claim; the prep then copies from the LAN mirror.

- [ ] **Step 4: Run the tests** — `--filter='BigSourceFetchTest|DispatchConcurrencyTest|GpuQueueRoutingTest|VideoPriorityTest|PrepClaimTest'`; expected PASS.

- [ ] **Step 5: Pint.**

---

### Task 10 (optional, ship only if more than ~1 video/week is reaped): Requeue lost preps (spec 1g)

**Files:** `app/Console/Commands/ReapStuckVideos.php`, `config/nuke.php` (`video.reap_requeue` = `env('REAP_REQUEUE', false)`), `app/Jobs/PrepareVideoJob.php` (`failed()` guard). Test: extend `ReapWindowTest`.

- [ ] **Step 1: Write the failing tests**
  - `it('requeues a stale video with no batch and no claim, at most twice')`:
    - The flag is on; the first and second reaps → PENDING, with `prep-requeues:{id}` counting.
    - The third → FAILED.
  - `it('fails as today with the flag off')`.
  - `it('ignores failed() from a job whose claim no longer holds')`: the key holds another token → `failed()` leaves the video untouched.
- [ ] **Step 2: Run them to verify they fail.**
- [ ] **Step 3: Implement.**
  - In the reaper's fail branch: when the flag is on, the video has no batch, `PrepClaim::pending()` is false and the counter (24 h TTL) is < 2, move it to PENDING (guarded on active statuses) and increment the counter.
  - In `failed()`: return early unless `PrepClaim::holds($id, $this->claim)` (a null claim holds).
- [ ] **Step 4: Run the tests.**
- [ ] **Step 5: Pint.**

---

## Final verification

- [ ] Run `docker compose exec nukevideo-api php artisan config:clear`, then `docker compose exec nukevideo-api php artisan test`; expected all green.
- [ ] Run `docker compose exec nukevideo-api ./vendor/bin/pint --test`; expected no changes.
- [ ] CI parity: run the suite with `REDIS_HOST=127.0.0.2` (unreachable); expected all green.
- [ ] Deploy notes for the owner:
  - Order: workers and the API host can deploy in either order for Tasks 1–8.
  - For Task 9, deploy the API host with routing off, then the workers, which brings up `fetch-worker`. After that, set `BIG_SOURCE_FETCH_GB=7` on the API host.
  - Kill switches: `CHUNK_OOM_SCORE_ADJ=0`, and unsetting `BIG_SOURCE_FETCH_GB`.
